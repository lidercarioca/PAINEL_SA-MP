<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FileController extends Controller
{
    private function storageDirectory(): string
    {
        return storage_path('app/scripts');
    }

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

    private function resolveServer(Request $request): ?Server
    {
        $serverId = $request->input('server_id');
        if (!$serverId) {
            return null;
        }

        return Server::find($serverId);
    }

    private function resolveDirectory(Request $request): string
    {
        $server = $this->resolveServer($request);

        if (!$server) {
            abort(400, 'server_id é obrigatório para operações de arquivo.');
        }

        $folder = trim($server->folder ?? '');
        if (!$folder || !is_dir($folder)) {
            abort(404, 'Pasta do servidor não encontrada.');
        }

        return rtrim($folder, DIRECTORY_SEPARATOR);
    }

    private function normalizePath(string $path): string
    {
        $parts = [];
        $segments = preg_split('/[\\\\\/]+/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $segment;
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function getFilePath(Request $request, string $name): string
    {
        $relativePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, ltrim($name, '\/'));
        if ($relativePath === '') {
            abort(400, 'Nome de arquivo inválido.');
        }

        $directory = $this->resolveDirectory($request);
        $path = $directory . DIRECTORY_SEPARATOR . $relativePath;
        $this->ensurePathInsideDirectory($path, $directory);

        return $path;
    }

    private function ensurePathInsideDirectory(string $path, string $directory): void
    {
        $normalizedDirectory = $this->normalizePath($directory);
        $normalizedPath = $this->normalizePath($path);

        $allowedPrefix = $normalizedDirectory . DIRECTORY_SEPARATOR;
        if ($normalizedPath !== $normalizedDirectory && !str_starts_with($normalizedPath, $allowedPrefix)) {
            abort(400, 'Caminho inválido ou fora do diretório do servidor.');
        }
    }

    private function listDirectoryContents(string $directory, string $baseDirectory): array
    {
        $items = [];

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $itemPath = $directory . DIRECTORY_SEPARATOR . $entry;
            $relativePath = ltrim(substr($itemPath, strlen($baseDirectory)), DIRECTORY_SEPARATOR);

            $items[] = [
                'name' => $entry,
                'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                'type' => is_dir($itemPath) ? 'directory' : 'file',
                'size' => is_file($itemPath) ? filesize($itemPath) : null,
            ];
        }

        usort($items, function ($first, $second) {
            if ($first['type'] === $second['type']) {
                return strcasecmp($first['name'], $second['name']);
            }

            return $first['type'] === 'directory' ? -1 : 1;
        });

        return $items;
    }

    public function index(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'path' => 'nullable|string',
        ]);

        $directory = $this->resolveDirectory($request);
        $path = trim((string) $request->query('path', ''), '/');
        $targetDirectory = $directory;

        if ($path !== '') {
            $targetDirectory = $this->getFilePath($request, $path);
        }

        if (!is_dir($targetDirectory)) {
            return response()->json(['error' => 'Pasta não encontrada.'], 404);
        }

        return response()->json([
            'path' => $path,
            'items' => $this->listDirectoryContents($targetDirectory, $directory),
        ]);
    }

    public function upload(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'file|max:10240',
            'server_id' => 'required|integer|exists:servers,id',
            'root_folder' => 'nullable|string|max:255',
        ]);

        $server = $this->resolveServer($request);
        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado.'], 404);
        }

        $folder = trim($server->folder ?? '');
        if (!$folder || !is_dir($folder)) {
            return response()->json(['error' => 'Pasta do servidor inválida ou inexistente.'], 404);
        }

        $rootFolder = trim(str_replace(['\\', '/'], '/', $request->input('root_folder', '')), '/');

        $uploadedNames = [];
        foreach ($request->file('files') as $file) {
            if (!$file->isValid()) {
                continue;
            }

            $relativePath = str_replace(['\\', '/'], '/', $file->getClientOriginalName());
            $relativePath = ltrim($relativePath, '/');
            $relativePath = $this->sanitizeRelativePath($relativePath);

            if ($relativePath === '' || preg_match('/(^|[\\/\\\\])\.\.([\\/\\\\]|$)/', $relativePath)) {
                continue;
            }

            if ($rootFolder !== '' && !Str::startsWith($relativePath, $rootFolder . '/')) {
                $relativePath = $rootFolder . '/' . $relativePath;
            }

            $destinationPath = $folder . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $destinationDir = dirname($destinationPath);

            if (!file_exists($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                return response()->json(['error' => 'Não foi possível criar o diretório de destino.'], 500);
            }

            $file->move($destinationDir, basename($destinationPath));
            $uploadedNames[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        }

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Arquivos enviados para o servidor {$server->name} pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json([
            'message' => 'Arquivo(s) enviado(s) com sucesso.',
            'names' => $uploadedNames,
        ]);
    }

    public function createFolder(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'path' => 'nullable|string',
            'folder_name' => 'required|string|max:255',
        ]);

        $directory = $this->resolveDirectory($request);
        $path = trim(str_replace('\\', '/', $data['path'] ?? ''), '/');
        $newFolder = trim($data['folder_name']);
        $newFolder = $this->sanitizeRelativePath($newFolder);

        $targetDirectory = $directory;
        if ($path !== '') {
            $targetDirectory .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        if (!is_dir($targetDirectory)) {
            return response()->json(['error' => 'Caminho de destino não encontrado.'], 404);
        }

        $folderPath = $targetDirectory . DIRECTORY_SEPARATOR . $newFolder;
        if (file_exists($folderPath)) {
            return response()->json(['error' => 'A pasta já existe.'], 400);
        }

        if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
            return response()->json(['error' => 'Não foi possível criar a pasta.'], 500);
        }

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Pasta {$newFolder} criada em {$path} pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Pasta criada com sucesso.']);
    }

    private function sanitizeRelativePath(string $relativePath): string
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', $relativePath)), function ($part) {
            return $part !== '' && $part !== '.' && $part !== '..';
        });

        return implode('/', array_map('basename', $parts));
    }

    private function findPawnCompiler(): ?string
    {
        $checkCommands = [
            'where pawncc 2>NUL',
            'where pawncc.exe 2>NUL',
            'command -v pawncc 2>/dev/null',
        ];

        foreach ($checkCommands as $command) {
            $result = trim(shell_exec($command) ?? '');
            if ($result !== '') {
                return explode("\n", $result)[0];
            }
        }

        return null;
    }

    public function show(Request $request, string $name)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $path = $this->getFilePath($request, $name);

        if (!file_exists($path)) {
            return response()->json(['error' => 'Arquivo não encontrado.'], 404);
        }

        if ($request->query('download')) {
            return response()->download($path, basename($path));
        }

        return response()->json(['name' => basename($name), 'content' => file_get_contents($path)]);
    }

    public function update(Request $request, string $name)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $content = $request->validate([
            'content' => 'required|string',
        ])['content'];

        $path = $this->getFilePath($request, $name);

        if (!file_exists($path)) {
            return response()->json(['error' => 'Arquivo não encontrado.'], 404);
        }

        file_put_contents($path, $content);
        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Arquivo {$name} atualizado pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Arquivo salvo com sucesso.']);
    }

    public function rename(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'old_path' => 'required|string',
            'new_path' => 'required|string',
        ]);

        $sourcePath = $this->getFilePath($request, $data['old_path']);
        $destinationPath = $this->getFilePath($request, $data['new_path']);

        if (!file_exists($sourcePath)) {
            return response()->json(['error' => 'Arquivo ou pasta de origem não encontrado.'], 404);
        }

        if (file_exists($destinationPath)) {
            return response()->json(['error' => 'O destino já existe.'], 400);
        }

        $destinationDir = dirname($destinationPath);
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
            return response()->json(['error' => 'Não foi possível criar o diretório de destino.'], 500);
        }

        if (!rename($sourcePath, $destinationPath)) {
            return response()->json(['error' => 'Não foi possível renomear ou mover o item.'], 500);
        }

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Arquivo/movido de {$data['old_path']} para {$data['new_path']} pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Item renomeado/movido com sucesso.']);
    }

    public function move(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'source' => 'required|string',
            'destination' => 'required|string',
        ]);

        $sourcePath = $this->getFilePath($request, $data['source']);
        $destinationPath = $this->getFilePath($request, $data['destination']);

        if (!file_exists($sourcePath)) {
            return response()->json(['error' => 'Arquivo ou pasta de origem não encontrado.'], 404);
        }

        if (is_dir($destinationPath)) {
            $destinationPath = rtrim($destinationPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($sourcePath);
        }

        if (file_exists($destinationPath)) {
            return response()->json(['error' => 'O destino já existe.'], 400);
        }

        $destinationDir = dirname($destinationPath);
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
            return response()->json(['error' => 'Não foi possível criar o diretório de destino.'], 500);
        }

        if (!rename($sourcePath, $destinationPath)) {
            return response()->json(['error' => 'Não foi possível mover o item.'], 500);
        }

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Arquivo/movido de {$data['source']} para {$data['destination']} pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Item movido com sucesso.']);
    }

    public function compile(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'source_path' => 'required|string',
        ]);

        $sourcePath = $this->getFilePath($request, $data['source_path']);
        if (!file_exists($sourcePath) || pathinfo($sourcePath, PATHINFO_EXTENSION) !== 'pwn') {
            return response()->json(['error' => 'Somente arquivos .pwn podem ser compilados.'], 400);
        }

        $compiler = $this->findPawnCompiler();
        if (!$compiler) {
            return response()->json(['error' => 'Compilador Pawn não encontrado no servidor.'], 500);
        }

        $outputPath = dirname($sourcePath) . DIRECTORY_SEPARATOR . pathinfo($sourcePath, PATHINFO_FILENAME) . '.amx';
        $command = sprintf(
            '%s -o %s %s 2>&1',
            escapeshellarg($compiler),
            escapeshellarg($outputPath),
            escapeshellarg($sourcePath)
        );

        exec($command, $output, $returnVar);

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Compilação solicitada para {$data['source_path']} pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json([
            'message' => $returnVar === 0 ? 'Compilação concluída.' : 'Compilação finalizada com mensagens.',
            'success' => $returnVar === 0,
            'output' => implode("\n", $output),
            'output_path' => str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($outputPath, strlen($this->resolveDirectory($request))), DIRECTORY_SEPARATOR)),
        ]);
    }

    private function deleteDirectoryRecursively(string $directory): bool
    {
        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if (!$this->deleteDirectoryRecursively($path)) {
                    return false;
                }
            } elseif (is_file($path) || is_link($path)) {
                if (!unlink($path)) {
                    return false;
                }
            }
        }

        return rmdir($directory);
    }

    public function destroy(Request $request, string $name)
    {
        $server = $this->resolveServer($request);
        if ($server) {
            $this->authorizeAdminOrOwner($request, $server);
        } else {
            $this->authorizeAdmin($request);
        }
        $path = $this->getFilePath($request, $name);

        if (!file_exists($path)) {
            return response()->json(['error' => 'Arquivo ou pasta não encontrado.'], 404);
        }

        if (is_dir($path)) {
            if (!$this->deleteDirectoryRecursively($path)) {
                return response()->json(['error' => 'Não foi possível excluir a pasta. Verifique permissões e conteúdo.'], 500);
            }
        } else {
            if (!unlink($path)) {
                return response()->json(['error' => 'Não foi possível excluir o arquivo.'], 500);
            }
        }

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Arquivo/pasta {$name} excluído pelo usuário " . ($user['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Item removido com sucesso.']);
    }
}
