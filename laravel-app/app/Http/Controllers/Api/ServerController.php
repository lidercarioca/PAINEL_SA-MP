<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use App\Services\LocalServerService;
use App\Services\RemoteCommandService;
use App\Services\SampRconService;
use Illuminate\Http\Request;
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
                $status = $localServerService->isRunning($server) ? 'online' : 'offline';
            }

            $ping = null;
            if ($status === 'online') {
                $ping = $this->measureServerPing($server->ip, $server->port);
            }

            return array_merge($server->toArray(), [
                'status' => $status,
                'ping' => $ping,
                'plan_name' => $server->plan?->name,
                'owner_name' => $server->owner?->name,
            ]);
        });

        return response()->json($servers);
    }

    private function measureServerPing(string $ip, int $port, int $timeout = 2): ?int
{
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

    public function players(int $serverId, SampRconService $rconService)
    {
        $server = $this->findServer($serverId);

        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        if (empty($server->password)) {
            return response()->json(['players' => [], 'count' => 0, 'message' => 'Senha RCON não configurada']);
        }

        $response = $rconService->sendCommandWithResponse(
            $server->ip,
            $server->port,
            $server->password,
            'players'
        );

        if ($response === null) {
            return response()->json(['players' => [], 'count' => 0, 'message' => 'Não foi possível obter dados de jogadores online']);
        }

        $players = $this->parsePlayersResponse($response);

        return response()->json(['players' => $players, 'count' => count($players), 'raw' => $response]);
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
        if ($server->type === 'local') {
            $memory = $localServerService->getServerProcessMemoryUsage($server);
            $disk = $localServerService->getServerDiskUsage($server);
            $cpu = $localServerService->getServerProcessCpuUsage($server);
        }

        if (empty($server->password)) {
            return response()->json(['fps' => null, 'raw' => null, 'cpu' => $cpu, 'memory' => $memory, 'disk' => $disk, 'message' => 'Senha RCON não configurada']);
        }

        $response = $rconService->sendCommandWithResponse(
            $server->ip,
            $server->port,
            $server->password,
            'stats'
        );

        if ($response === null) {
            return response()->json(['fps' => null, 'raw' => null, 'memory' => $memory, 'message' => 'Não foi possível obter estatísticas do servidor']);
        }

        $fps = $this->parseServerFps($response);

        return response()->json(['fps' => $fps, 'raw' => $response, 'cpu' => $cpu, 'memory' => $memory, 'disk' => $disk]);
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

    public function start(Request $request, LocalServerService $localServerService)
    {
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

        if (!$result['success']) {
            return $this->jsonResponse(['error' => $result['message'], 'output' => $result['output'] ?? null], 500);
        }

        $server->status = 'online';
        $server->save();

        ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) iniciado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));
        return $this->jsonResponse(['status' => 'online', 'server_id' => $server->id, 'output' => $result['output']]);
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

        $server->status = 'offline';
        $server->save();

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
            'password' => 'nullable|string',
            'type' => 'required|in:local,ssh',
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
            'password' => $data['password'] ?? null,
            'type' => $data['type'],
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
            'password' => 'nullable|string',
            'type' => 'sometimes|required|in:local,ssh',
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
            $server->password = $data['password'] ?? null;
        }
        if ($request->has('type')) {
            $server->type = $data['type'];
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
