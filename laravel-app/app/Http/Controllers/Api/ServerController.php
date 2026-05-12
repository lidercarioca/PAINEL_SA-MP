<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use App\Services\LocalServerService;
use App\Services\RemoteCommandService;
use App\Services\SampRconService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;

class ServerController extends Controller
{
    private function getAuthenticatedUser($request)
    {
        return $request->attributes->get('authenticated_user');
    }

    private function authorizeAdmin($request)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || ($user->role ?? null) !== 'admin') {
            abort(403, 'Acesso negado. Admin apenas.');
        }
    }

    private function isServerOwner($request, Server $server): bool
    {
        $user = $this->getAuthenticatedUser($request);
        return $user && $server->owner_id === $user->id;
    }

    private function authorizeAdminOrOwner($request, Server $server)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || (($user->role ?? null) !== 'admin' && !$this->isServerOwner($request, $server))) {
            abort(403, 'Acesso negado. Somente administrador ou proprietário do servidor.');
        }
    }

    private function findServer(int $serverId)
    {
        return Server::find($serverId);
    }

    private function normalizeFolderPath(?string $folder): ?string
    {
        if (!$folder) {
            return null;
        }

        $normalized = trim($folder);
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $normalized);
        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    private function sanitizeUtf8Value($value)
    {
        if (is_string($value)) {
            return $this->sanitizeUtf8String($value);
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitizeUtf8Value'], $value);
        }

        return $value;
    }

    private function sanitizeUtf8String(string $value): string
    {
        $encodings = ['UTF-8', 'CP1252', 'CP850', 'ISO-8859-1', 'ASCII'];

        foreach ($encodings as $encoding) {
            $clean = @iconv($encoding, 'UTF-8//IGNORE', $value);
            if ($clean !== false) {
                return $clean;
            }
        }

        return '';
    }

    private function jsonResponse($data, int $status = 200)
    {
        $response = new JsonResponse($data, $status);
        return $response->setEncodingOptions(JSON_INVALID_UTF8_IGNORE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function ensureFolderExists(string $folder): bool
    {
        if (is_dir($folder)) {
            return true;
        }

        return mkdir($folder, 0755, true);
    }

    public function index(Request $request, LocalServerService $localServerService)
    {
        $user = $this->getAuthenticatedUser($request);
        $query = Server::with(['owner', 'plan']);

        if (($user['role'] ?? 'admin') !== 'admin') {
            $query->where('owner_id', $user['id']);
        }

        $servers = $query->get();

        $servers = $servers->map(function (Server $server) use ($localServerService) {
            $status = $server->status;

            if ($server->type === 'local' && $server->status !== 'suspended') {
                $isCurrentlyRunning = $localServerService->isRunning($server);

                // Para FiveM: se status salvo é online E porta está aberta, manter online
                if (!$isCurrentlyRunning && $server->status === 'online' && strtolower($server->engine ?? 'samp') === 'fivem') {
                    if (!empty($server->port) && $localServerService->isServerPortInUse((int) $server->port)) {
                        ActionLogService::append("[FiveM] Keeping status ONLINE for server {$server->id}: port {$server->port} is open despite process not detected");
                        $status = 'online';
                    } else {
                        $status = 'offline';
                    }
                } else {
                    $status = $isCurrentlyRunning ? 'online' : 'offline';
                }
            }

            $engine = $server->engine;
            if (!$engine) {
                $engine = $this->guessEngine($server);
            }

            $ping = null;
            if ($status === 'online') {
                $ping = $this->measureServerPingWithServer($server);
            }

            return array_merge($server->toArray(), [
                'status' => $status,
                'ping' => $ping,
                'engine' => $engine,
                'plan_name' => $server->plan?->name,
                'owner_name' => $server->owner?->name,
            ]);
        });

        return response()->json($servers);
    }

    private function guessEngine(Server $server): string
    {
        $gameMode = strtolower($server->game_mode ?? '');
        if (str_contains($gameMode, 'fivem') || $server->port === 30120) {
            return 'fivem';
        }

        return 'samp';
    }

    private function measureServerPing(string $ip, int $port, string $engine = 'samp', int $timeout = 2): ?int
    {
        if (strtolower($engine) === 'fivem') {
            // Para FiveM, precisamos do objeto Server para determinar se é local ou remoto
            // Este método será chamado de index() onde temos acesso ao $server
            // Por enquanto, manter compatibilidade com chamada antiga
            return $this->measureFivemPing($ip, $port);
        }

        // SA-MP ping via UDP
        $startTime = microtime(true);

        $socket = @fsockopen("udp://{$ip}", $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, $timeout);

        // 🔥 pacote SAMP
        $ipParts = explode('.', $ip);
        $packet = 'SAMP';
        foreach ($ipParts as $part) {
            $packet .= chr((int)$part);
        }
        $packet .= chr($port & 0xFF);
        $packet .= chr(($port >> 8) & 0xFF);
        $packet .= 'i';

        fwrite($socket, $packet);

        $response = fread($socket, 2048);
        fclose($socket);

        if (!$response) {
            return null;
        }

        return (int) max(1, round((microtime(true) - $startTime) * 1000));
    }

    private function measureServerPingWithServer(Server $server): ?int
    {
        $engine = strtolower($server->engine ?? 'samp');
        if ($engine === 'fivem') {
            return $this->measureFivemPingWithServer($server);
        }

        // SA-MP ping via UDP
        return $this->measureServerPing($server->ip, $server->port);
    }

    private function measureFivemPing(string $ip, int $port): ?int
    {
        // ⚠️ DEPRECATED: Use measureFivemPingWithServer() instead to properly handle local servers
        // This method doesn't have access to Server object to determine if it's local
        // and should always use 127.0.0.1 for localhost FiveM queries
        
        $urls = [
            "http://{$ip}:{$port}/info.json",
            "http://{$ip}:{$port}/players.json"
        ];

        foreach ($urls as $url) {
            $startTime = microtime(true);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => 2000,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: painel-samp/1.0'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $ping = round((microtime(true) - $startTime) * 1000);

            ActionLogService::append("FiveM ping test: URL={$url}, HTTP={$httpCode}, ping={$ping}ms, error=" . ($error ?: 'none'));

            if ($httpCode === 200 && !$error) {
                return (int) max(1, $ping);
            }
        }

        ActionLogService::append("FiveM ping failed for {$ip}:{$port} - both info.json and players.json failed");
        return null;
    }

    private function measureFivemPingWithServer(Server $server): ?int
    {
        // Para servidores locais, usar 127.0.0.1
        // Para servidores remotos, usar o IP público
        $ip = $server->type === 'local' ? '127.0.0.1' : $server->ip;
        $port = $server->port;

        $urls = [
            "http://{$ip}:{$port}/info.json",
            "http://{$ip}:{$port}/players.json"
        ];

        foreach ($urls as $url) {
            $startTime = microtime(true);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => 2000,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: painel-samp/1.0'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $ping = round((microtime(true) - $startTime) * 1000);

            ActionLogService::append("FiveM ping: server_id={$server->id}, engine=fivem, ping_url={$url}, http_code={$httpCode}, ping={$ping}ms, error=" . ($error ?: 'none'));

            if ($httpCode === 200 && !$error) {
                return (int) max(1, $ping);
            }
        }

        ActionLogService::append("FiveM ping: server_id={$server->id}, engine=fivem, ping_url=failed - both endpoints failed");
        return null;
    }

    public function players(int $serverId, SampRconService $rconService)
    {
        $server = $this->findServer($serverId);

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner(request(), $server);

        $engine = strtolower($server->engine ?? $this->guessEngine($server));

        if ($engine === 'fivem') {
            return $this->getFivemPlayers($server);
        }

        if (empty($server->password)) {
            return response()->json([
                'success' => true,
                'engine' => 'samp',
                'count' => 0,
                'max_slots' => $server->limit_slots ?? 0,
                'players' => [],
                'message' => 'Senha RCON não configurada',
            ]);
        }

        $response = $rconService->sendCommandWithResponse(
            $server->ip,
            $server->port,
            $server->password,
            'players'
        );

        if ($response === null) {
            return response()->json([
                'success' => true,
                'engine' => 'samp',
                'count' => 0,
                'max_slots' => $server->limit_slots ?? 0,
                'players' => [],
                'message' => 'Não foi possível obter dados de jogadores online',
            ]);
        }

        $players = $this->parsePlayersResponse($response);

        return response()->json([
            'success' => true,
            'engine' => 'samp',
            'count' => count($players),
            'max_slots' => $server->limit_slots ?? 0,
            'players' => $players,
            'raw' => $response,
        ]);
    }

    private function fetchUrl(string $url, int $connectTimeoutMs = 1000, int $timeoutMs = 2000): array
    {
        $startTime = microtime(true);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => [
                'User-Agent: painel-samp/1.0',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'response' => $response,
            'httpCode' => $httpCode,
            'error' => $error,
            'durationMs' => round((microtime(true) - $startTime) * 1000),
        ];
    }

    private function getFivemPlayers(Server $server): JsonResponse
    {
        $ip = $server->type === 'local' ? '127.0.0.1' : $server->ip;
        $port = $server->port;
        $url = "http://{$ip}:{$port}/players.json";

        $result = $this->fetchUrl($url, 1000, 2000);
        $response = $result['response'];
        $httpCode = $result['httpCode'];
        $error = $result['error'];

        if ($httpCode !== 200 || $response === false || $error) {
            ActionLogService::append("FiveM players fetch failed: server_id={$server->id}, url={$url}, http={$httpCode}, error=" . ($error ?: 'none'));
            return response()->json([
                'success' => true,
                'engine' => 'fivem',
                'count' => 0,
                'max_slots' => $server->limit_slots ?? 32,
                'players' => [],
                'message' => 'Servidor FiveM offline ou indisponível',
            ]);
        }

        $data = @json_decode($response, true);
        if (!is_array($data) || !isset($data['players']) || !is_array($data['players'])) {
            ActionLogService::append("FiveM players parse failed: server_id={$server->id}, url={$url}, response_invalid");
            return response()->json([
                'success' => true,
                'engine' => 'fivem',
                'count' => 0,
                'max_slots' => $server->limit_slots ?? 32,
                'players' => [],
                'message' => 'Dados de jogadores FiveM inválidos',
            ]);
        }

        $players = [];
        foreach ($data['players'] as $index => $player) {
            if (!is_array($player)) {
                continue;
            }

            $identifiers = [];
            if (isset($player['identifiers']) && is_array($player['identifiers'])) {
                $identifiers = array_values(array_filter($player['identifiers'], fn ($value) => is_string($value) && $value !== ''));
            }

            $players[] = [
                'id' => isset($player['id']) ? (int) $player['id'] : $index + 1,
                'name' => $this->sanitizeUtf8String((string) ($player['name'] ?? ($player['endpoint'] ?? ''))),
                'ping' => isset($player['ping']) ? (int) $player['ping'] : 0,
                'score' => isset($player['score']) ? (int) $player['score'] : 0,
                'identifiers' => $identifiers,
                'endpoint' => isset($player['endpoint']) ? (string) $player['endpoint'] : '',
                'raw' => $player,
            ];
        }

        $maxSlots = $server->limit_slots ?? 32;
        if (isset($data['vars']['sv_maxclients'])) {
            $maxSlots = (int) $data['vars']['sv_maxclients'];
        }

        if (isset($data['server']['sv_maxclients'])) {
            $maxSlots = (int) $data['server']['sv_maxclients'];
        }

        return response()->json([
            'success' => true,
            'engine' => 'fivem',
            'count' => count($players),
            'max_slots' => $maxSlots,
            'players' => $players,
            'raw' => $data,
        ]);
    }

    public function createBackup(int $serverId, LocalServerService $localServerService)
    {
        $server = $this->findServer($serverId);
        if (!$server) {
            return response()->json(['success' => false, 'message' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner(request(), $server);

        try {
            $backupPath = $localServerService->createServerBackup($server);
            $backupName = basename($backupPath);
            $size = $this->formatBytes(filesize($backupPath));

            return response()->json([
                'success' => true,
                'message' => 'Backup criado com sucesso',
                'file' => $backupName,
                'size' => $size,
            ]);
        } catch (\Throwable $exception) {
            ActionLogService::append('Backup failed: server_id=' . $server->id . ', error=' . $exception->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Falha ao criar backup: ' . $exception->getMessage(),
            ], 500);
        }
    }

    public function listBackups(int $serverId)
    {
        $server = $this->findServer($serverId);
        if (!$server) {
            return response()->json(['success' => false, 'message' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner(request(), $server);

        $backupFolder = $this->getBackupFolder($server);
        if (!is_dir($backupFolder)) {
            return response()->json(['success' => true, 'backups' => []]);
        }

        $files = File::files($backupFolder);
        $backups = array_map(function ($file) use ($server) {
            return [
                'name' => $file->getFilename(),
                'size' => $this->formatBytes($file->getSize()),
                'created_at' => date('Y-m-d H:i:s', $file->getMTime()),
                'engine' => $this->extractEngineFromBackupName($file->getFilename(), $server->id),
            ];
        }, $files);

        usort($backups, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return response()->json(['success' => true, 'backups' => $backups]);
    }

    public function deleteBackup(int $serverId, string $backupName)
    {
        $server = $this->findServer($serverId);
        if (!$server) {
            return response()->json(['success' => false, 'message' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner(request(), $server);

        $backupName = basename($backupName);
        $backupPath = $this->getBackupFolder($server) . DIRECTORY_SEPARATOR . $backupName;
        if (!File::exists($backupPath)) {
            return response()->json(['success' => false, 'message' => 'Backup não encontrado'], 404);
        }

        File::delete($backupPath);
        return response()->json(['success' => true, 'message' => 'Backup excluído com sucesso']);
    }

    public function downloadBackup(int $serverId, string $backupName)
    {
        $server = $this->findServer($serverId);
        if (!$server) {
            return response()->json(['success' => false, 'message' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner(request(), $server);

        $backupName = basename($backupName);
        $backupPath = $this->getBackupFolder($server) . DIRECTORY_SEPARATOR . $backupName;
        if (!File::exists($backupPath)) {
            return response()->json(['success' => false, 'message' => 'Backup não encontrado'], 404);
        }

        return response()->download($backupPath, $backupName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    private function getBackupFolder(Server $server): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $server->id);
    }

    private function addFileIfExists(\ZipArchive $zip, string $filePath, string $zipPath): void
    {
        if (File::exists($filePath) && File::isFile($filePath)) {
            $zip->addFile($filePath, $zipPath);
        }
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $folderPath, string $zipBasePath): void
    {
        if (!File::exists($folderPath) || !File::isDirectory($folderPath)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            if ($this->shouldExcludeBackupPath($file->getPathname())) {
                continue;
            }

            $relativeName = ltrim(str_replace($folderPath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $relativeName = $zipBasePath . DIRECTORY_SEPARATOR . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativeName);
            $zip->addFile($file->getPathname(), $relativeName);
        }
    }

    private function shouldExcludeBackupPath(string $path): bool
    {
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $normalized);
        foreach ($parts as $part) {
            if (in_array(strtolower($part), ['cache', 'node_modules', '.git', 'backups'], true)) {
                return true;
            }
        }

        return false;
    }

    private function extractEngineFromBackupName(string $backupName, int $serverId): string
    {
        $parts = explode('-', $backupName);
        if (count($parts) >= 4 && $parts[0] === 'server' && (int) $parts[1] === $serverId) {
            return $parts[2];
        }

        return 'unknown';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    public function stats(int $serverId, SampRconService $rconService, LocalServerService $localServerService)
    {
        $server = $this->findServer($serverId);

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $memory = null;
        $disk = null;
        $cpu = null;
        $debug = [
            'server_id' => $server->id,
            'server_folder' => $server->folder,
            'process_found' => false,
            'process' => [
                'pid' => null,
                'name' => null,
                'executable_path' => null,
                'command_line' => null,
                'engine' => strtolower($server->engine ?? 'samp'),
            ],
            'cpu_source' => null,
            'memory_source' => null,
            'fallback_reason' => null,
            'disk_source' => 'server_folder',
        ];

        if ($server->type === 'local') {
            $processDebug = $localServerService->findMainServerProcessDebug($server);
            $memory = $localServerService->getServerProcessMemoryUsage($server);
            $disk = $localServerService->getServerDiskUsage($server);
            $cpu = $localServerService->getServerProcessCpuUsage($server);

            $debug['process_found'] = $processDebug['process_found'];
            $debug['process'] = $processDebug['process'];
            $debug['fallback_reason'] = $processDebug['fallback_reason'];
            $debug['cpu_source'] = $cpu['source'] ?? null;
            $debug['memory_source'] = $memory['source'] ?? null;

            if ($debug['process_found'] && $cpu['percent'] === 0 && ($cpu['source'] ?? 'unknown') === 'unknown') {
                $debug['fallback_reason'] = 'Processo localizado, mas não foi possível extrair métricas de CPU do Windows.';
            }

            if ($debug['process_found'] && $memory['used_bytes'] === 0 && ($memory['source'] ?? '') === 'WorkingSetSize') {
                $debug['fallback_reason'] = 'Processo localizado, mas não foi possível extrair o WorkingSetSize do processo.';
            }
        }

        // Valores padrão quando processo não encontrado
        $cpu = $cpu ?? ['percent' => 0, 'label' => '0%', 'source' => 'process_not_found'];
        $memory = $memory ?? [
            'used_bytes' => 0,
            'used_mb' => 0.0,
            'label' => '0 MB',
            'percent' => 0,
            'total_bytes' => 0,
            'total_mb' => 0.0,
            'source' => 'process_not_found',
        ];
        $disk = $disk ?? [
            'folder_size_bytes' => 0,
            'folder_size_mb' => 0.0,
            'folder_size_gb' => 0.0,
            'label' => '0 MB',
            'source' => 'server_folder',
            'percent' => 0,
            'total_bytes' => 0,
        ];

        if (empty($server->password)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'fps' => null,
                    'cpu' => $cpu,
                    'memory' => $memory,
                    'disk' => $disk,
                    'debug' => $debug
                ]
            ]);
        }

        $response = $rconService->sendCommandWithResponse(
            $server->ip,
            $server->port,
            $server->password,
            'stats'
        );

        if ($response === null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'fps' => null,
                    'cpu' => $cpu,
                    'memory' => $memory,
                    'disk' => $disk,
                    'debug' => $debug
                ]
            ]);
        }

        $fps = $this->parseServerFps($response);

        return response()->json([
            'success' => true,
            'data' => [
                'fps' => $fps,
                'cpu' => $cpu,
                'memory' => $memory,
                'disk' => $disk,
                'debug' => $debug
            ]
        ]);
    }

    private function parseServerFps(string $response): ?float
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($response));

        foreach ($lines as $line) {
            if (preg_match('/fps\s*[:=]?\s*(\d+(?:\.\d+)?)/i', $line, $matches)) {
                return (float) $matches[1];
            }

            if (preg_match('/frame\s*rate\s*[:=]?\s*(\d+(?:\.\d+)?)/i', $line, $matches)) {
                return (float) $matches[1];
            }
        }

        return null;
    }

    private function parsePlayersResponse(string $response): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($response));
        $players = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/players? online/i', $line) || preg_match('/^#?\s*id/i', $line)) {
                continue;
            }

            if (preg_match('/^\s*(\d+)\s+(.+?)\s+(\d+)\s+(\d+)$/', $line, $matches)) {
                $players[] = [
                    'id' => (int) $matches[1],
                    'name' => trim($matches[2]),
                    'score' => (int) $matches[3],
                    'ping' => (int) $matches[4],
                    'timeOnline' => '—',
                ];
                continue;
            }

            if (preg_match('/^\s*(\d+):\s*(.+?)\s+score\s*(\d+)\s+ping\s*(\d+)/i', $line, $matches)) {
                $players[] = [
                    'id' => (int) $matches[1],
                    'name' => trim($matches[2]),
                    'score' => (int) $matches[3],
                    'ping' => (int) $matches[4],
                    'timeOnline' => '—',
                ];
                continue;
            }

            if (preg_match('/^\s*(\d+)\s+(.+?)\s+\((\d+)\)\s+\[score:\s*(\d+)\]\s+\[ping:\s*(\d+)\]/i', $line, $matches)) {
                $players[] = [
                    'id' => (int) $matches[1],
                    'name' => trim($matches[2]),
                    'score' => (int) $matches[4],
                    'ping' => (int) $matches[5],
                    'timeOnline' => '—',
                ];
                continue;
            }
        }

        return $players;
    }

    public function status(int $serverId, LocalServerService $localServerService)
    {
        $server = $this->findServer($serverId);

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $isRunning = $localServerService->isRunning($server);
        $status = $isRunning ? 'online' : 'offline';
        $ping = null;
        $cpu = null;
        $memory = null;
        $disk = null;
        $debug = null;

        $engine = strtolower($server->engine ?? 'samp');

        if ($server->type === 'local') {
            $processInfo = $localServerService->findMainServerProcess($server);
            $debug = [
                'source' => 'local_status',
                'process' => $processInfo ? [
                    'pid' => $processInfo['pid'],
                    'executable_path' => $processInfo['executable_path'] ?? null,
                    'command_line' => $processInfo['command_line'] ?? null,
                    'engine' => $processInfo['engine'] ?? null,
                ] : null,
                'server_folder' => $server->folder,
                'disk_source' => 'folder_size',
            ];
        }

        if ($status === 'online') {
            $ping = $this->measureServerPingWithServer($server);
        }

        if ($server->type === 'local') {
            // Não coletar métricas de CPU/disco durante o status 'starting' para evitar poluição
            if ($server->status !== 'starting') {
                $cpu = $localServerService->getServerProcessCpuUsage($server);
                $memory = $localServerService->getServerProcessMemoryUsage($server);
                $disk = $localServerService->getServerDiskUsage($server);
            }
        }

        if ($server->status !== $status) {
            $server->status = $status;
            $server->save();
        }

        ActionLogService::append("[API] Status refresh: server_id={$server->id}, status={$status}, ping={$ping}, engine={$engine}");

        return response()->json([
            'status' => $status,
            'ping' => $ping,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'debug' => $debug,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function start(Request $request, LocalServerService $localServerService)
    {
        Log::info('[START ENDPOINT HIT]', ['server_id' => $request->input('server_id')]);

        $request->validate(['server_id' => 'required|integer']);
        $server = $this->findServer($request->input('server_id'));

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        if ($server->type !== 'local') {
            return response()->json(['error' => 'Apenas servidores locais podem ser iniciados pelo painel.'], 400);
        }

        $this->authorizeAdminOrOwner($request, $server);

        $result = $localServerService->start($server);
        $result = $this->sanitizeUtf8Value($result);

        if (empty($result['success'])) {
            $server->status = 'offline';
            $server->save();
            return $this->jsonResponse([
                'success' => false,
                'status' => 'offline',
                'message' => $result['message'] ?? 'Falha ao iniciar o servidor.',
                'output' => $result['output'] ?? null,
                'debug' => $result['debug'] ?? null,
                'server_id' => $server->id,
            ], 500);
        }

        $actualStatus = $result['status'] ?? (
            $localServerService->isRunning($server) ? 'online' : 'starting'
        );

        $server->status = $actualStatus;
        $server->save();

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) iniciou processo de start pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return $this->jsonResponse([
            'success' => true,
            'status' => $actualStatus,
            'message' => $result['message'] ?? 'Servidor iniciado com sucesso.',
            'output' => $result['output'] ?? null,
            'debug' => $result['debug'] ?? null,
            'server_id' => $server->id,
        ]);
    }

    public function stop(Request $request, LocalServerService $localServerService)
    {
        $request->validate(['server_id' => 'required|integer']);
        $server = $this->findServer($request->input('server_id'));

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        if ($server->type !== 'local') {
            return response()->json(['error' => 'Apenas servidores locais podem ser parados pelo painel.'], 400);
        }

        $this->authorizeAdminOrOwner($request, $server);
        $result = $localServerService->stop($server);
        $result = $this->sanitizeUtf8Value($result);
        if (!$result['success']) {
            return $this->jsonResponse(['error' => $result['message'], 'output' => $result['output'] ?? null], 500);
        }

        if ($localServerService->isRunning($server)) {
            return $this->jsonResponse([
                'error' => 'O servidor ainda está em execução após o comando de parada.',
                'output' => $result['output'] ?? null
            ], 500);
        }

        $server->status = 'stopping';
        $server->save();

        if (!$localServerService->isRunning($server)) {
            $server->status = 'offline';
            $server->save();
        }

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) parado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));
        return $this->jsonResponse(['status' => 'offline', 'server_id' => $server->id, 'output' => $result['output']]);
    }

    public function restart(Request $request, LocalServerService $localServerService)
    {
        $request->validate(['server_id' => 'required|integer']);
        $server = $this->findServer($request->input('server_id'));

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        if ($server->type !== 'local') {
            return response()->json(['error' => 'Apenas servidores locais podem ser reiniciados pelo painel.'], 400);
        }

        $this->authorizeAdminOrOwner($request, $server);
        $result = $localServerService->restart($server);
        $result = $this->sanitizeUtf8Value($result);
        if (!$result['success']) {
            return $this->jsonResponse(['error' => $result['message'], 'output' => $result['output'] ?? null], 500);
        }

        $server->status = 'online';
        $server->save();

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) reiniciado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));
        return $this->jsonResponse(['status' => 'online', 'server_id' => $server->id, 'output' => $result['output']]);
    }

    public function suspend(Request $request, LocalServerService $localServerService)
    {
        $request->validate(['server_id' => 'required|integer']);
        $server = $this->findServer($request->input('server_id'));

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        if ($server->type !== 'local') {
            return response()->json(['error' => 'Apenas servidores locais podem ser suspensos pelo painel.'], 400);
        }

        $this->authorizeAdmin($request);
        $result = $localServerService->suspend($server);
        $result = $this->sanitizeUtf8Value($result);
        if (!$result['success']) {
            return $this->jsonResponse(['error' => $result['message'], 'output' => $result['output'] ?? null], 500);
        }

        $server->status = 'suspended';
        $server->save();

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) suspenso pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));
        return $this->jsonResponse(['status' => 'suspended', 'server_id' => $server->id, 'output' => $result['output']]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name' => 'required|string',
            'ip' => 'required|ip',
            'port' => 'required|integer',
            'txadmin_port' => 'nullable|integer',
            'password' => 'nullable|string',
            'type' => 'required|in:local,ssh',
            'engine' => 'nullable|in:samp,fivem',
            'folder' => 'nullable|string',
            'owner_id' => 'nullable|integer',
            'limit_ram' => 'nullable|integer',
            'limit_slots' => 'nullable|integer',
            'disk_limit_gb' => 'nullable|integer|min:1',
            'plan_id' => 'nullable|integer',
            'game_mode' => 'nullable|string',
            'auto_restart_interval_hours' => 'nullable|integer',
            'auto_restart_on_crash' => 'nullable|boolean',
            'auto_restart_on_offline' => 'nullable|boolean',
        ]);

        $folder = $this->normalizeFolderPath($data['folder'] ?? null);
        if ($folder && !$this->ensureFolderExists($folder)) {
            return response()->json(['error' => 'Não foi possível criar a pasta do servidor.'], 500);
        }

        $server = Server::create([
            'name' => $data['name'],
            'ip' => $data['ip'],
            'port' => $data['port'],
            'txadmin_port' => $data['txadmin_port'] ?? null,
            'password' => $data['password'] ?? null,
            'type' => $data['type'],
            'engine' => $data['engine'] ?? 'samp',
            'status' => 'offline',
            'folder' => $folder,
            'owner_id' => $data['owner_id'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'game_mode' => $data['game_mode'] ?? null,
            'limit_ram' => $data['limit_ram'] ?? null,
            'limit_slots' => $data['limit_slots'] ?? null,
            'disk_limit_gb' => $data['disk_limit_gb'] ?? null,
            'auto_restart_interval_hours' => $data['auto_restart_interval_hours'] ?? null,
            'auto_restart_on_crash' => $data['auto_restart_on_crash'] ?? false,
            'auto_restart_on_offline' => $data['auto_restart_on_offline'] ?? false,
        ]);

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) criado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));
        return response()->json(['message' => 'Servidor criado', 'server' => $server]);
    }

    public function update(Request $request)
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'server_id' => 'required|integer',
            'name' => 'sometimes|required|string',
            'ip' => 'sometimes|required|ip',
            'port' => 'sometimes|required|integer',
            'txadmin_port' => 'nullable|integer',
            'password' => 'nullable|string',
            'type' => 'sometimes|required|in:local,ssh',
            'engine' => 'nullable|in:samp,fivem',
            'folder' => 'nullable|string',
            'owner_id' => 'nullable|integer',
            'plan_id' => 'nullable|integer',
            'game_mode' => 'nullable|string',
            'limit_ram' => 'nullable|integer',
            'limit_slots' => 'nullable|integer',
            'disk_limit_gb' => 'nullable|integer|min:1',
            'auto_restart_interval_hours' => 'nullable|integer',
            'auto_restart_on_crash' => 'nullable|boolean',
            'auto_restart_on_offline' => 'nullable|boolean',
        ]);

        $server = $this->findServer($data['server_id']);
        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        if ($request->has('name')) {
            $server->name = $data['name'];
        }
        if ($request->has('ip')) {
            $server->ip = $data['ip'];
        }
        if ($request->has('port')) {
            $server->port = $data['port'];
        }
        if ($request->has('password')) {
            $password = $data['password'];
            // Só atualizar senha se ela for fornecida e não vazia
            if (!empty($password)) {
                $server->password = $password;
            }
            // Se password for string vazia, não alterar (manter senha existente)
        }
        if ($request->has('type')) {
            $server->type = $data['type'];
        }
        if ($request->has('engine')) {
            $server->engine = $data['engine'] ?? 'samp';
        }
        if ($request->has('folder')) {
            $folder = $this->normalizeFolderPath($data['folder'] ?? null);
            if ($folder && !$this->ensureFolderExists($folder)) {
                return response()->json(['error' => 'Não foi possível criar a pasta do servidor.'], 500);
            }
            $server->folder = $folder;
        }
        if ($request->has('owner_id')) {
            $server->owner_id = $data['owner_id'] ?? null;
        }
        if ($request->has('plan_id')) {
            $server->plan_id = $data['plan_id'] ?? null;
        }
        if ($request->has('txadmin_port')) {
            $server->txadmin_port = $data['txadmin_port'] ?? null;
        }
        if ($request->has('game_mode')) {
            $server->game_mode = $data['game_mode'] ?? null;
        }
        if ($request->has('limit_ram')) {
            $server->limit_ram = $data['limit_ram'] ?? null;
        }
        if ($request->has('limit_slots')) {
            $server->limit_slots = $data['limit_slots'] ?? null;
        }
        if ($request->has('disk_limit_gb')) {
            $server->disk_limit_gb = $data['disk_limit_gb'] ?? null;
        }
        if ($request->has('auto_restart_interval_hours')) {
            $server->auto_restart_interval_hours = $data['auto_restart_interval_hours'] ?? null;
        }
        if ($request->has('auto_restart_on_crash')) {
            $server->auto_restart_on_crash = $data['auto_restart_on_crash'] ?? false;
        }
        if ($request->has('auto_restart_on_offline')) {
            $server->auto_restart_on_offline = $data['auto_restart_on_offline'] ?? false;
        }

        $server->save();

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) atualizado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Servidor atualizado com sucesso', 'server' => $server]);
    }

    public function delete(Request $request)
    {
        $this->authorizeAdmin($request);
        $request->validate(['server_id' => 'required|integer']);

        $server = $this->findServer($request->input('server_id'));
        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $serverName = $server->name;
        $serverIp = $server->ip;
        $serverPort = $server->port;
        $server->delete();

        ActionLogService::append("Servidor {$serverName} ({$serverIp}:{$serverPort}) excluído pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));
        return response()->json(['message' => 'Servidor removido', 'server_id' => $request->input('server_id')]);
    }

    public function command(Request $request, RemoteCommandService $commandService)
    {
        $this->authorizeAdmin($request);
        $request->validate([
            'server_id' => 'required|integer',
            'command' => 'required|string',
            'mode' => 'required|in:local,ssh',
        ]);

        $serverId = $request->input('server_id');
        $server = $this->findServer($serverId);

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $commandText = $request->input('command');
        if ($request->input('mode') === 'ssh') {
            $result = $commandService->executeSsh(
                $server['ip'],
                $request->input('ssh_user', 'root'),
                $request->input('ssh_password', ''),
                $commandText
            );
        } else {
            $result = $commandService->executeLocal(explode(' ', $commandText));
        }

        ActionLogService::append("Comando servidor {$server['name']} ({$server['ip']}:{$server['port']}) executado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido') . ": {$commandText}");

        return response()->json($result);
    }
}
