<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use App\Engines\FiveMEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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
        try {
            $server = Server::find($serverId);
            if (!$server) {
                return response()->json([
                    'success' => false,
                    'resources' => [],
                    'message' => 'Servidor não encontrado'
                ]);
            }

            $this->authorizeAdminOrOwner($request, $server);

            if (strtolower($server->engine ?? 'samp') !== 'fivem') {
                return response()->json([
                    'success' => false,
                    'resources' => [],
                    'message' => 'Resource Manager disponível apenas para FiveM'
                ]);
            }

            $resources = [];
            $resourcePaths = $this->getResourcePaths($server);

            ActionLogService::append("FiveM resources: Iniciando detecção para servidor {$serverId} (pasta: {$server->folder})");

            foreach ($resourcePaths as $path) {
                if (!File::isDirectory($path)) {
                    ActionLogService::append("FiveM resources: Path não existe ou não é diretório: {$path}");
                    continue;
                }

                ActionLogService::append("FiveM resources: Verificando path: {$path}");

                foreach ($this->collectResourceDirectories($path) as $resourcePath) {
                    $resourceName = basename($resourcePath);
                    $resource = $this->analyzeResource($resourceName, $resourcePath, $server);
                    if ($resource) {
                        $resources[] = $resource;
                        ActionLogService::append("FiveM resources: Resource válido encontrado: {$resourceName} em {$resourcePath}");
                    } else {
                        ActionLogService::append("FiveM resources: Resource inválido ignorado: {$resourceName} em {$resourcePath}");
                    }
                }
            }

            ActionLogService::append("FiveM resources: Total de resources detectados: " . count($resources));

            // Ordenar por nome
            usort($resources, fn($a, $b) => strcmp($a['name'], $b['name']));

            return response()->json([
                'success' => true,
                'resources' => $resources,
            ]);

        } catch (\Exception $e) {
            ActionLogService::append("FiveM resources: Erro crítico na detecção: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'resources' => [],
                'message' => 'Erro interno ao detectar resources'
            ]);
        }
    }

    /**
     * Executar ação em um resource
     */
    public function action(Request $request, $serverId, $routeAction = null)
    {
        try {
            $server = Server::find($serverId);
            if (!$server) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servidor não encontrado'
                ]);
            }

            $this->authorizeAdminOrOwner($request, $server);

            if (strtolower($server->engine ?? 'samp') !== 'fivem') {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource Manager disponível apenas para FiveM'
                ]);
            }

            $data = $request->validate([
                'resource' => 'required|string|max:100',
                'action' => 'nullable|in:ensure,start,stop,restart',
            ]);

            $resourceName = basename($data['resource']);
            $action = $routeAction ?: ($data['action'] ?? null);

            if (!$action) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ação inválida para resource'
                ]);
            }

            // Verificar se resource existe
            if (!$this->resourceExists($resourceName, $server)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource não encontrado'
                ]);
            }

            if (!$this->isFivemServerOnline($server)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Servidor FiveM offline ou porta incorreta',
                ]);
            }

            // Executar ação
            $result = $this->executeResourceAction($server, $resourceName, $action);

            if (!$result['success']) {
                ActionLogService::append("FiveM resource action FAILED: server={$server->id}, resource={$resourceName}, action={$action}, bridge=" . ($result['method'] ?? 'none') . ", command=" . ($result['command'] ?? 'unknown') . ", message=" . ($result['message'] ?? 'none'));
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? "Ação '{$action}' falhou ao enviar comando.",
                    'bridge' => $result['method'] ?? 'none',
                    'resource' => $resourceName,
                    'action' => $action,
                    'command' => $result['command'] ?? null,
                ]);
            }

            ActionLogService::append("FiveM resource action SUCCESS: server={$server->id}, resource={$resourceName}, action={$action}, bridge=" . ($result['method'] ?? 'unknown') . ", command=" . ($result['command'] ?? 'unknown'));
            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? "Resource iniciado com sucesso via " . ($result['method'] ?? 'unknown'),
                'resource' => $resourceName,
                'action' => $action,
                'bridge' => $result['method'] ?? 'none',
                'command' => $result['command'] ?? null,
                'error_code' => $result['error_code'] ?? null,
            ]);
        } catch (\Exception $e) {
            ActionLogService::append("FiveM resource action failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao executar ação: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Obter caminhos possíveis para recursos
     */
    private function getResourcePaths(Server $server): array
    {
        $basePath = rtrim($server->folder, '\\/');
        $paths = [];

        if (!$basePath || !File::isDirectory($basePath)) {
            ActionLogService::append("FiveM resources: Pasta base do servidor não existe: {$basePath}");
            return $paths;
        }

        // Paths padrão do FiveM
        $candidates = [
            $basePath . '/resources',
            $basePath . '/server-data/resources',
            $basePath . '/zirix-data/resources',
            $basePath . '/txData/resources',
        ];

        foreach ($candidates as $candidate) {
            if (File::isDirectory($candidate)) {
                $paths[] = $candidate;
                ActionLogService::append("FiveM resources: Path encontrado: {$candidate}");
            }
        }

        // Procurar em txData/**/resources (incluindo subpastas como [local], [standalone], etc.)
        $txDataPath = $basePath . '/txData';
        if (File::isDirectory($txDataPath)) {
            ActionLogService::append("FiveM resources: Verificando txData: {$txDataPath}");

            // Procurar recursivamente por pastas resources
            $this->findResourceFoldersRecursively($txDataPath, $paths);
        }

        // Procurar em subpastas da pasta resources principal
        $resourcesPath = $basePath . '/resources';
        if (File::isDirectory($resourcesPath)) {
            ActionLogService::append("FiveM resources: Verificando subpastas em resources: {$resourcesPath}");

            foreach (File::directories($resourcesPath) as $subdir) {
                $subdirName = basename($subdir);
                // Verificar se é uma pasta de categoria (começa e termina com [])
                if (str_starts_with($subdirName, '[') && str_ends_with($subdirName, ']')) {
                    $candidate = $subdir . '/resources';
                    if (File::isDirectory($candidate)) {
                        $paths[] = $candidate;
                        ActionLogService::append("FiveM resources: Path de categoria encontrado: {$candidate}");
                    }
                }
            }
        }

        ActionLogService::append("FiveM resources: Total de paths encontrados: " . count($paths));
        return $paths;
    }

    /**
     * Procurar pastas resources recursivamente
     */
    private function findResourceFoldersRecursively(string $rootPath, array &$paths): void
    {
        if (!File::isDirectory($rootPath)) {
            return;
        }

        foreach (File::directories($rootPath) as $directory) {
            $resourcesCandidate = $directory . '/resources';
            if (File::isDirectory($resourcesCandidate)) {
                $paths[] = $resourcesCandidate;
                ActionLogService::append("FiveM resources: Path recursivo encontrado: {$resourcesCandidate}");
            }

            // Continuar procurando recursivamente
            $this->findResourceFoldersRecursively($directory, $paths);
        }
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
        $hasResourceLua = File::exists($path . '/__resource.lua');

        // Resource deve ter fxmanifest.lua OU __resource.lua
        if (!$hasFxManifest && !$hasResourceLua) {
            return null;
        }

        $flags = $this->getResourceStartupFlags($name, $server);

        // Determinar categoria baseada no path
        $category = $this->determineResourceCategory($path, $server);

        return [
            'name' => $name,
            'path' => $this->normalizeResourcePath($path, $server),
            'started' => $flags['started'],
            'ensured' => $flags['ensured'],
            'hasFxmanifest' => $hasFxManifest,
            'hasResourceLua' => $hasResourceLua,
            'category' => $category,
        ];
    }

    /**
     * Identificar flags de inicialização do resource
     */
    private function getResourceStartupFlags(string $resourceName, Server $server): array
    {
        $flags = [
            'started' => false,
            'ensured' => false,
        ];

        $basePath = rtrim($server->folder, '\\/');
        $configFiles = [
            $basePath . '/server.cfg',
            $basePath . '/config.cfg',
            $basePath . '/server-data/server.cfg',
            $basePath . '/zirix-data/server.cfg',
            $basePath . '/txData/server.cfg',
            $basePath . '/txData/default/server.cfg',
        ];

        $configFound = false;
        foreach ($configFiles as $file) {
            if (!File::exists($file)) {
                continue;
            }

            $configFound = true;
            ActionLogService::append("FiveM resources: Verificando config: {$file}");

            try {
                $content = File::get($file);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                        continue;
                    }

                    if (preg_match('/^ensure\s+' . preg_quote($resourceName, '/') . '\s*$/i', $line)) {
                        $flags['ensured'] = true;
                        ActionLogService::append("FiveM resources: Resource '{$resourceName}' marcado como ensured em {$file}");
                    }

                    if (preg_match('/^start\s+' . preg_quote($resourceName, '/') . '\s*$/i', $line)) {
                        $flags['started'] = true;
                        ActionLogService::append("FiveM resources: Resource '{$resourceName}' marcado como started em {$file}");
                    }

                    if ($flags['started'] && $flags['ensured']) {
                        break 2;
                    }
                }
            } catch (\Exception $e) {
                ActionLogService::append("FiveM resources: Erro ao ler config {$file}: " . $e->getMessage());
            }
        }

        if (!$configFound) {
            ActionLogService::append("FiveM resources: Nenhum arquivo de configuração encontrado para servidor {$server->id}");
        }

        return $flags;
    }



    /**
     * Verificar se resource existe
     */
    private function resourceExists(string $name, Server $server): bool
    {
        $resourcePaths = $this->getResourcePaths($server);
        foreach ($resourcePaths as $path) {
            foreach ($this->collectResourceDirectories($path) as $resourcePath) {
                if (basename($resourcePath) === $name && $this->isResourceDirectory($resourcePath)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Buscar diretórios de resources de forma recursiva
     */
    private function collectResourceDirectories(string $rootPath): array
    {
        $resources = [];

        if (!File::isDirectory($rootPath)) {
            return $resources;
        }

        foreach (File::directories($rootPath) as $directory) {
            if ($this->isResourceDirectory($directory)) {
                $resources[] = $directory;
                continue;
            }

            $resources = array_merge($resources, $this->collectResourceDirectories($directory));
        }

        return $resources;
    }

    /**
     * Verificar se diretório representa um resource FiveM válido
     */
    private function isResourceDirectory(string $path): bool
    {
        return File::exists($path . '/fxmanifest.lua') || File::exists($path . '/__resource.lua');
    }

    /**
     * Determinar categoria do resource baseada no path
     */
    private function determineResourceCategory(string $resourcePath, Server $server): string
    {
        $basePath = rtrim($server->folder, '\\/');
        $relativePath = str_replace($basePath . '/', '', $resourcePath);

        // Verificar se está em uma pasta de categoria [nome]
        if (preg_match('/\[([^\]]+)\]/', $relativePath, $matches)) {
            return $matches[1];
        }

        // Verificar paths específicos
        if (str_contains($relativePath, 'txData/')) {
            return 'txData';
        }

        if (str_contains($relativePath, 'server-data/')) {
            return 'server-data';
        }

        if (str_contains($relativePath, 'zirix-data/')) {
            return 'zirix-data';
        }

        return 'resources';
    }

    /**
     * Normalizar caminho do resource para exibição
     */
    private function normalizeResourcePath(string $resourcePath, Server $server): string
    {
        $basePath = rtrim($server->folder, '\/');
        $normalized = str_replace('\\', '/', $resourcePath);

        if (str_starts_with($normalized, str_replace('\\', '/', $basePath))) {
            $relative = substr($normalized, strlen(str_replace('\\', '/', $basePath)));
            return ltrim(str_replace('\\', '/', $relative), '/');
        }

        return $normalized;
    }

    private function isFivemServerOnline(Server $server): bool
    {
        $ip = $server->type === 'local' ? '127.0.0.1' : $server->ip;
        $url = "http://{$ip}:{$server->port}/players.json";

        try {
            $response = Http::timeout(2)->get($url);
            $success = $response->ok() && !empty($response->body());

            if (!$success) {
                ActionLogService::append("FiveM resource action: servidor offline ou indisponível - server_id={$server->id}, url={$url}, http={$response->status()}, body=" . substr($response->body(), 0, 200));
            }

            return $success;
        } catch (\Exception $e) {
            ActionLogService::append("FiveM resource action: servidor offline ou indisponível - server_id={$server->id}, url={$url}, exception=" . $e->getMessage());
            return false;
        }
    }

    /**
     * Executar ação no resource via console FiveM
     */
    private function executeResourceAction(Server $server, string $resourceName, string $action): array
    {
        // Sanitizar nome do resource
        $resourceName = basename($resourceName);

        ActionLogService::append("FiveM executeResourceAction: Iniciando ação '{$action}' para resource '{$resourceName}' no servidor {$server->id}");

        // Mapear ações para comandos FiveM
        $commandMap = [
            'ensure' => "ensure {$resourceName}",
            'start' => "start {$resourceName}",
            'stop' => "stop {$resourceName}",
            'restart' => "stop {$resourceName}",  // Stop + ensure para restart
        ];

        if (!isset($commandMap[$action])) {
            ActionLogService::append("FiveM resources: Ação desconhecida: {$action}");
            return [
                'success' => false,
                'method' => 'none',
                'message' => 'Ação desconhecida',
            ];
        }

        try {
            // Usar command bridge para enviar comando real
            $bridge = new \App\Services\FiveMCommandBridge(
                $server->ip,
                $server->port,
                $server->password ?? null,
                $server->folder ?? null,
                $server->id,
                $server->txadmin_port
            );

            ActionLogService::append("FiveM executeResourceAction: Bridge criado com ip={$server->ip}, port={$server->port}, hasPassword=" . (!empty($server->password) ? 'sim' : 'não') . ", hasFolder=" . (!empty($server->folder) ? 'sim' : 'não'));

            // Enviar comando principal
            $command = $commandMap[$action];
            $result = $bridge->sendConsoleCommand($command);

            if (!$result['success']) {
                ActionLogService::append("FiveM resources: Falha ao enviar comando '{$command}': " . ($result['message'] ?? 'desconhecido'));
                return [
                    'success' => false,
                    'method' => $result['method'] ?? 'none',
                    'message' => $result['message'] ?? 'Falha ao enviar comando principal',
                    'command' => $command,
                ];
            }

            $previousState = $this->getResourceStartupFlags($resourceName, $server);
            $previousStarted = $previousState['started'];

            if ($action === 'restart') {
                sleep(1);  // Aguardar um pouco após stop
                $ensureCommand = "ensure {$resourceName}";
                $ensureResult = $bridge->sendConsoleCommand($ensureCommand);

                if (!$ensureResult['success']) {
                    ActionLogService::append("FiveM resources: Falha ao enviar ensure (parte de restart) para '{$resourceName}': " . ($ensureResult['message'] ?? 'desconhecido'));
                    return [
                        'success' => false,
                        'method' => $ensureResult['method'] ?? $result['method'] ?? 'none',
                        'message' => $ensureResult['message'] ?? 'Falha ao enviar ensure durante restart',
                        'command' => $ensureCommand,
                    ];
                }

                $result = $ensureResult;
                $command = $ensureCommand;
            }

            if (in_array($action, ['ensure', 'start', 'stop', 'restart'], true)) {
                sleep(1);
                $currentState = $this->getResourceStartupFlags($resourceName, $server);
                $currentStarted = $currentState['started'];

                $stateValid = $this->isResourceStateValid($action, $currentStarted);

                ActionLogService::append("FiveM resources: comando enviado para '{$resourceName}' - command='{$command}', previous_started=" . ($previousStarted ? 'true' : 'false') . ", current_started=" . ($currentStarted ? 'true' : 'false') . ", bridge=" . ($result['method'] ?? 'none'));

                if (!$stateValid) {
                    return [
                        'success' => false,
                        'method' => $result['method'] ?? 'none',
                        'message' => 'Resource não mudou para o estado esperado após o comando',
                        'command' => $command,
                        'auth_success' => $result['auth_success'] ?? true,
                        'raw_response' => $result['raw_response'] ?? null,
                        'error_code' => ($result['method'] ?? 'none') . '_state_unchanged',
                        'previous_state' => $previousStarted ? 'started' : 'stopped',
                        'current_state' => $currentStarted ? 'started' : 'stopped',
                    ];
                }
            }

            ActionLogService::append("FiveM resources: Comando '{$command}' confirmado para {$server->id} (método: {$result['method']})");
            return [
                'success' => true,
                'method' => $result['method'] ?? 'none',
                'message' => $action === 'stop' ? 'Resource parado com sucesso' : 'Resource iniciado com sucesso',
                'command' => $command,
                'auth_success' => $result['auth_success'] ?? true,
                'raw_response' => $result['raw_response'] ?? null,
            ];

        } catch (\Exception $e) {
            ActionLogService::append("FiveM resources: Exceção ao executar ação '{$action}': " . $e->getMessage());
            return [
                'success' => false,
                'method' => 'none',
                'message' => 'Erro interno ao executar ação',
            ];
        }
    }

    /**
     * Validar se o estado atual do resource corresponde à ação executada.
     *
     * @param string $action
     * @param bool $currentStarted
     * @return bool
     */
    private function isResourceStateValid(string $action, bool $currentStarted): bool
    {
        if ($action === 'stop') {
            return !$currentStarted;
        }

        return $currentStarted;
    }

}
