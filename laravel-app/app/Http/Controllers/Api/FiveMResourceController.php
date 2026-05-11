<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class FiveMResourceController extends Controller
{
    private function getAuthenticatedUser($request)
    {
        return $request->attributes->get('authenticated_user');
    }

    private function authorizeAdminOrOwner($request, Server $server)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user) {
            abort(401, 'Não autenticado');
        }

        $isAdmin = ($user->role ?? null) === 'admin' || ($user->role ?? null) === 'administrador';
        $isOwner = $server->owner_id == $user->id;

        if (!$isAdmin && !$isOwner) {
            abort(403, 'Acesso negado');
        }
    }

    /**
     * Listar resources do servidor FiveM
     */
    public function index(Request $request, $serverId)
    {
        $server = Server::find($serverId);
        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner($request, $server);

        if (strtolower($server->engine ?? 'samp') !== 'fivem') {
            return response()->json(['error' => 'Resource Manager disponível apenas para FiveM'], 400);
        }

        $resources = [];
        $resourcePaths = $this->getResourcePaths($server);

        foreach ($resourcePaths as $path) {
            if (!File::isDirectory($path)) {
                continue;
            }

            $items = File::directories($path);
            foreach ($items as $resourcePath) {
                $resourceName = basename($resourcePath);
                $resource = $this->analyzeResource($resourceName, $resourcePath, $server);
                if ($resource) {
                    $resources[] = $resource;
                }
            }
        }

        // Ordenar por nome
        usort($resources, fn($a, $b) => strcmp($a['name'], $b['name']));

        // Contar stats
        $stats = [
            'total' => count($resources),
            'enabled' => count(array_filter($resources, fn($r) => $r['enabled'])),
            'with_fxmanifest' => count(array_filter($resources, fn($r) => $r['has_fxmanifest'])),
            'with_lua' => count(array_filter($resources, fn($r) => $r['has_resource_lua'])),
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'resources' => $resources,
        ]);
    }

    /**
     * Executar ação em um resource
     */
    public function action(Request $request, $serverId)
    {
        $server = Server::find($serverId);
        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner($request, $server);

        if (strtolower($server->engine ?? 'samp') !== 'fivem') {
            return response()->json(['error' => 'Resource Manager disponível apenas para FiveM'], 400);
        }

        $data = $request->validate([
            'resource' => 'required|string|max:100',
            'action' => 'required|in:ensure,start,stop,restart',
        ]);

        $resourceName = basename($data['resource']);
        $action = $data['action'];

        // Verificar se resource existe
        if (!$this->resourceExists($resourceName, $server)) {
            return response()->json(['error' => 'Resource não encontrado'], 404);
        }

        // Executar ação
        try {
            $result = $this->executeResourceAction($server, $resourceName, $action);
            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => "Ação '{$action}' não disponível. Console FiveM ainda não conectado.",
                ], 503);
            }

            ActionLogService::append("FiveM resource action: {$action} {$resourceName} (Server: {$server->id})");

            return response()->json([
                'success' => true,
                'message' => "Resource '{$resourceName}' - ação '{$action}' enviada",
                'resource' => $resourceName,
                'action' => $action,
            ]);
        } catch (\Exception $e) {
            ActionLogService::append("FiveM resource action failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao executar ação: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obter caminhos possíveis para recursos
     */
    private function getResourcePaths(Server $server): array
    {
        $basePath = rtrim($server->folder, '\\/');
        $paths = [];

        if ($basePath && File::isDirectory($basePath)) {
            // {folder}/resources
            if (File::isDirectory($basePath . '/resources')) {
                $paths[] = $basePath . '/resources';
            }

            // {folder}/server-data/resources
            if (File::isDirectory($basePath . '/server-data/resources')) {
                $paths[] = $basePath . '/server-data/resources';
            }

            // {folder}/zirix-data/resources
            if (File::isDirectory($basePath . '/zirix-data/resources')) {
                $paths[] = $basePath . '/zirix-data/resources';
            }
        }

        return $paths;
    }

    /**
     * Analisar um resource
     */
    private function analyzeResource(string $name, string $path, Server $server): ?array
    {
        if (!File::isDirectory($path)) {
            return null;
        }

        $hasFxManifest = File::exists($path . '/fxmanifest.lua');
        $hasResourceLua = File::exists($path . '/resource.lua');
        $enabled = $this->isResourceEnabled($name, $server);

        if (!$hasFxManifest && !$hasResourceLua) {
            return null; // Ignorar pastas sem manifesto
        }

        $size = $this->getDirectorySize($path);
        $lastModified = $this->getLastModified($path);

        return [
            'name' => $name,
            'path' => $path,
            'has_fxmanifest' => $hasFxManifest,
            'has_resource_lua' => $hasResourceLua,
            'enabled' => $enabled,
            'size' => $this->formatBytes($size),
            'size_bytes' => $size,
            'last_modified' => $lastModified,
        ];
    }

    /**
     * Verificar se resource está enabled no config
     */
    private function isResourceEnabled(string $resourceName, Server $server): bool
    {
        $configFiles = [
            rtrim($server->folder, '\\/') . '/server.cfg',
            rtrim($server->folder, '\\/') . '/config.cfg',
            rtrim($server->folder, '\\/') . '/server-data/server.cfg',
            rtrim($server->folder, '\\/') . '/zirix-data/server.cfg',
        ];

        foreach ($configFiles as $file) {
            if (!File::exists($file)) {
                continue;
            }

            $content = File::get($file);
            $lines = explode("\n", $content);

            foreach ($lines as $line) {
                $line = trim($line);
                // Pular comentários
                if (str_starts_with($line, '#') || str_starts_with($line, '//')) {
                    continue;
                }

                if (preg_match('/^(?:ensure|start)\s+' . preg_quote($resourceName, '/') . '\s*$/i', $line)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verificar se resource existe
     */
    private function resourceExists(string $name, Server $server): bool
    {
        $resourcePaths = $this->getResourcePaths($server);
        foreach ($resourcePaths as $path) {
            if (File::isDirectory($path . '/' . $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Executar ação no resource via console FiveM
     */
    private function executeResourceAction(Server $server, string $resourceName, string $action): bool
    {
        // Mapeamento de ações para comandos FiveM
        $commands = [
            'ensure' => "ensure {$resourceName}",
            'start' => "start {$resourceName}",
            'stop' => "stop {$resourceName}",
            'restart' => "restart {$resourceName}",
        ];

        if (!isset($commands[$action])) {
            return false;
        }

        $command = $commands[$action];

        // TODO: Implementar envio de comando via console FiveM quando disponível
        // Por enquanto, apenas retornar sucesso para indicar que seria enviado
        // Quando houver canal de comando FiveM estabelecido, descomentar:
        // return $this->sendFiveMConsoleCommand($server, $command);

        // Retornar false por enquanto (console ainda não conectado)
        return false;
    }

    /**
     * Calcular tamanho total de um diretório
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Ignorar erros de acesso
        }

        return $size;
    }

    /**
     * Obter última modificação
     */
    private function getLastModified(string $path): string
    {
        try {
            $lastModified = 0;
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                $mtime = $file->getMTime();
                if ($mtime > $lastModified) {
                    $lastModified = $mtime;
                }
            }

            if ($lastModified) {
                return date('Y-m-d H:i:s', $lastModified);
            }
        } catch (\Exception $e) {
            // Ignorar erros
        }

        return 'N/A';
    }

    /**
     * Formatar bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
