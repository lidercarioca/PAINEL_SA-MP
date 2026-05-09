<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
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

            $logPath = null;
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
                return response()->json(['error' => 'Arquivo de log não encontrado'], 404);
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

        $lines = collect(File::lines($logPath))
            ->map(function ($line) {
                $line = trim($line);
                $line = @iconv('UTF-8', 'UTF-8//IGNORE', $line);
                return $line !== false ? $line : '';
            })
            ->filter(fn ($line) => $line !== '')
            ->values()
            ->toArray();

        $lines = array_slice(array_reverse($lines), 0, 200);

        return response()->json(['lines' => array_reverse($lines)]);
    }
}
