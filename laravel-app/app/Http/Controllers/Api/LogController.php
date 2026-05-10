<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogController extends Controller
{
    private function getAuthenticatedUser($request)
    {
        return $request->attributes->get('authenticated_user');
    }

    private function isServerOwner($request, Server $server): bool
    {
        $user = $this->getAuthenticatedUser($request);
        return $user && $server->owner_id === $user->id;
    }

    private function authorizeAdmin($request)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || ($user->role ?? null) !== 'admin') {
            abort(403, 'Acesso negado. Admin apenas.');
        }
    }

    private function authorizeAdminOrOwner($request, Server $server)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || (($user->role ?? null) !== 'admin' && !$this->isServerOwner($request, $server))) {
            abort(403, 'Acesso negado. Somente administrador ou proprietário do servidor.');
        }
    }

    public function index(Request $request)
    {
        $serverId = $request->query('server_id');
        $requestedFile = trim((string) $request->query('file', 'server_log'));
        $fileKey = strtolower($requestedFile);
        $fileMap = [
            'server_log' => ['server_log.txt'],
            'server_log.txt' => ['server_log.txt'],
            'crashdetect' => ['crashdetect.txt', 'crashinfo.txt', 'crashdetect.log', 'crashinfo.log'],
            'crashdetect.txt' => ['crashdetect.txt', 'crashinfo.txt', 'crashdetect.log', 'crashinfo.log'],
            'errors' => ['errors.txt', 'error.txt', 'errors.log', 'error_log.txt'],
            'errors.txt' => ['errors.txt', 'error.txt', 'errors.log', 'error_log.txt'],
        ];

        if (!isset($fileMap[$fileKey])) {
            return response()->json(['error' => 'Arquivo de log não permitido'], 400);
        }

        $fileCandidates = $fileMap[$fileKey];
        $logPath = null;

        if ($serverId && is_numeric($serverId)) {
            $server = Server::find((int) $serverId);
            if (!$server) {
                return response()->json(['error' => 'Servidor não encontrado'], 404);
            }

            $this->authorizeAdminOrOwner($request, $server);

            $folder = trim($server->folder ?? '');
            if (!$folder || !is_dir($folder)) {
                return response()->json(['error' => 'Pasta do servidor inválida'], 400);
            }

            $engine = strtolower($server->engine ?? 'samp');
            ActionLogService::append("LogController::index request server_id={$server->id}, engine={$engine}, file={$fileKey}");

            if ($engine === 'fivem' && in_array($fileKey, ['server_log', 'server_log.txt'], true)) {
                $logPath = $this->getLatestFivemLogFile($server);
                if (!$logPath) {
                    ActionLogService::append("LogController::index no FiveM log file found for server_id={$server->id}");
                    return response()->json([
                        'success' => false,
                        'lines' => [],
                        'message' => 'Nenhum log FiveM encontrado em txData/default/logs',
                    ]);
                }
            } else {
                foreach ($fileCandidates as $candidate) {
                    $candidatePath = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
                    if (File::exists($candidatePath)) {
                        $logPath = $candidatePath;
                        break;
                    }
                }

                if (!$logPath && in_array($fileKey, ['crashdetect', 'errors'], true)) {
                    $searchPattern = $fileKey === 'crashdetect' ? 'crash' : 'error';
                    foreach (File::files($folder) as $file) {
                        $fileNameLower = strtolower($file->getFilename());
                        if (str_contains($fileNameLower, $searchPattern)) {
                            $logPath = $file->getRealPath();
                            break;
                        }
                    }
                }

                if (!$logPath) {
                    return response()->json([
                        'success' => false,
                        'lines' => [],
                        'message' => 'Arquivo de log não encontrado.',
                    ]);
                }
            }
        } else {
            $this->authorizeAdmin($request);

            if (!in_array('server_log.txt', $fileCandidates, true)) {
                return response()->json(['error' => 'Arquivo de log não encontrado'], 404);
            }

            $logPath = dirname(__DIR__, 4) . '/database/server_log.txt';
            if (!File::exists($logPath)) {
                File::put($logPath, '');
            }
        }

        $lines = $this->readLogLines($logPath, 200);
        ActionLogService::append("LogController::index returning " . count($lines) . " lines from {$logPath}");

        return response()->json(['lines' => $lines]);
    }

    public function stream(Request $request, $serverId)
    {
        $server = Server::find((int) $serverId);
        if (!$server) {
            return response()->json(['success' => false, 'lines' => [], 'message' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner($request, $server);
        $engine = strtolower($server->engine ?? 'samp');
        
        if ($engine !== 'fivem') {
            ActionLogService::append("LogController::stream called for non-FiveM server engine={$engine}. Rejecting.");
            return response()->json([
                'success' => false,
                'lines' => [],
                'message' => 'Streaming de console disponível apenas para servidores FiveM.',
            ]);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $logPath = $this->getLatestFivemLogFile($server);
        if (!$logPath) {
            ActionLogService::append("LogController::stream no FiveM log file found for server_id={$server->id}");
            return response()->json([
                'success' => false,
                'lines' => [],
                'message' => 'Nenhum log FiveM encontrado em txData/default/logs',
                'offset' => $offset,
                'file' => null,
            ]);
        }

        $result = $this->readFivemConsoleStream($logPath, $offset, 300);
        ActionLogService::append("LogController::stream server_id={$server->id}, file={$logPath}, requested_offset={$offset}, returned_lines=" . count($result['lines']) . ", next_offset=" . $result['offset']);
        return response()->json($result);
    }

    private function readFivemConsoleStream(string $logPath, int $offset, int $maxLines = 300): array
    {
        if (!File::exists($logPath)) {
            return [
                'success' => false,
                'lines' => [],
                'message' => 'Arquivo de log FiveM não encontrado.',
                'offset' => $offset,
                'file' => $logPath,
                'truncated' => false,
            ];
        }

        $size = @filesize($logPath);
        if ($size === false) {
            return [
                'success' => false,
                'lines' => [],
                'message' => 'Falha ao ler o arquivo de log.',
                'offset' => $offset,
                'file' => $logPath,
                'truncated' => false,
            ];
        }

        if ($offset <= 0 || $offset > $size) {
            $lines = $this->tailFileLines($logPath, $maxLines);
            return [
                'success' => true,
                'lines' => $lines,
                'offset' => $size,
                'file' => $logPath,
                'truncated' => $offset > $size,
            ];
        }

        $handle = fopen($logPath, 'rb');
        if (!$handle) {
            return [
                'success' => false,
                'lines' => [],
                'message' => 'Não foi possível abrir o arquivo de log.',
                'offset' => $offset,
                'file' => $logPath,
                'truncated' => false,
            ];
        }

        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        $nextOffset = ftell($handle);
        fclose($handle);

        $lines = preg_split('/\r\n|\n|\r/', trim($content)) ?: [];
        $lines = array_filter(array_map(function ($line) {
            $line = trim($line);
            return $line !== '' ? @iconv('UTF-8', 'UTF-8//IGNORE', $line) : null;
        }, $lines));
        $lines = array_values(array_filter($lines, fn ($line) => $line !== false));

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return [
            'success' => true,
            'lines' => $lines,
            'offset' => $nextOffset,
            'file' => $logPath,
            'truncated' => false,
        ];
    }

    private function tailFileLines(string $logPath, int $limit = 300): array
    {
        $handle = fopen($logPath, 'rb');
        if (!$handle) {
            return [];
        }

        $chunkSize = 4096;
        $buffer = '';
        $lines = [];
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && count($lines) <= $limit) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
            $lines = preg_split('/\r\n|\n|\r/', trim($buffer)) ?: [];
            if ($position === 0) {
                break;
            }
        }

        fclose($handle);
        if (count($lines) > $limit) {
            $lines = array_slice($lines, -$limit);
        }

        return array_values(array_map(fn ($line) => trim($line), $lines));
    }

    private function getLatestFivemLogFile(Server $server): ?string
    {
        $folder = rtrim($server->folder ?? '', DIRECTORY_SEPARATOR);
        $searchPaths = [
            $folder . DIRECTORY_SEPARATOR . 'txData' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'logs',
            $folder . DIRECTORY_SEPARATOR . 'txData' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'logs',
            $folder . DIRECTORY_SEPARATOR . 'logs',
        ];

        ActionLogService::append("LogController::getLatestFivemLogFile server_id={$server->id}, engine=fivem, searchPaths=" . implode(' | ', $searchPaths));

        foreach ($searchPaths as $searchPath) {
            $pattern = $this->normalizeGlobPath($searchPath . DIRECTORY_SEPARATOR . '*.log');
            $files = glob($pattern, GLOB_NOSORT) ?: [];
            $files = array_filter($files, 'is_file');

            if (empty($files)) {
                ActionLogService::append("LogController::getLatestFivemLogFile no log files found in {$searchPath}");
                continue;
            }

            $latestFile = null;
            $latestTime = 0;
            foreach ($files as $filePath) {
                $modified = @filemtime($filePath);
                if ($modified !== false && $modified > $latestTime) {
                    $latestTime = $modified;
                    $latestFile = $filePath;
                }
            }

            if ($latestFile) {
                ActionLogService::append("LogController::getLatestFivemLogFile selected file {$latestFile} from {$searchPath}");
                return $latestFile;
            }
        }

        return null;
    }

    private function normalizeGlobPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function readLogLines(string $logPath, int $limit = 200): array
    {
        $lines = collect(File::lines($logPath))
            ->map(function ($line) {
                $line = trim($line);
                $line = @iconv('UTF-8', 'UTF-8//IGNORE', $line);
                return $line !== false ? $line : '';
            })
            ->filter(fn ($line) => $line !== '')
            ->values()
            ->toArray();

        $lines = array_slice(array_reverse($lines), 0, $limit);
        return array_reverse($lines);
    }
}
