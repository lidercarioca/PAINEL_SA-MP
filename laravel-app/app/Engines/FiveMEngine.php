<?php

namespace App\Engines;

class FiveMEngine implements EngineInterface
{
    private string $ip;
    private int $port;
    private ?string $apiToken;

    public function __construct(string $ip, int $port, ?string $apiToken = null)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->apiToken = $apiToken;
    }

    public function start(string $folder): bool
    {
        // FiveM precisa de inicialização via FXServer.exe
        // Procura FXServer em locais conhecidos
        $executable = $this->resolveFXServerExecutable($folder);
        
        if (!$executable) {
            return false;
        }

        // Inicia o servidor em background (Windows)
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open("start /B \"FXServer\" \"$executable\"", $descriptorspec, $pipes, $folder);
        
        if (is_resource($process)) {
            proc_close($process);
            sleep(3); // Aguarda inicialização
            return true;
        }

        return false;
    }

    private function resolveFXServerExecutable(string $folder): ?string
    {
        // Candidatos em ordem de prioridade
        $candidates = [
            'artifacts' . DIRECTORY_SEPARATOR . 'FXServer.exe',
            'artifacts' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'FXServer.exe',
            'FXServer.exe',
            'run.bat', // Fallback
        ];

        foreach ($candidates as $candidate) {
            $path = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function stop(): bool
    {
        // FiveM pode usar o console ou API txAdmin
        return $this->sendCommand('stop');
    }

    public function restart(string $folder): bool
    {
        $this->stop();
        sleep(3);
        return $this->start($folder);
    }

    public function getLogs(string $folder): string
    {
        // FiveM logs geralmente estão em /logs/
        $logsDir = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'logs';
        
        if (!is_dir($logsDir)) {
            return '';
        }

        $files = scandir($logsDir, SCANDIR_SORT_DESCENDING);
        if (!$files) {
            return '';
        }

        // Obtém o arquivo de log mais recente
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && strpos($file, '.log') !== false) {
                $logFile = $logsDir . DIRECTORY_SEPARATOR . $file;
                if (is_file($logFile)) {
                    return file_get_contents($logFile) ?: '';
                }
            }
        }

        return '';
    }

    public function sendCommand(string $command): bool|string
    {
        // FiveM pode usar HTTP API se txAdmin está habilitado
        // Ou console via stdin/stdout
        
        if ($this->apiToken) {
            return $this->sendHttpCommand($command);
        }

        // Fallback para implementação básica
        return false;
    }

    /**
     * Enviar comando sanitizado para resource via command bridge
     * 
     * @param string $command Comando tipo "ensure resource"
     * @return bool Sucesso ou falha
     */
    public function sendConsoleCommand(string $command, ?string $folder = null): bool
    {
        try {
            $bridge = new \App\Services\FiveMCommandBridge(
                $this->ip,
                $this->port,
                $this->apiToken,
                $folder
            );

            $result = $bridge->sendConsoleCommand($command);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function sendHttpCommand(string $command): bool|string
    {
        try {
            $payload = json_encode(['command' => $command]);
            $headers = "Authorization: Bearer {$this->apiToken}\r\n" .
                       "X-TxAdmin-Token: {$this->apiToken}\r\n" .
                       "Content-Type: application/json\r\n";

            $paths = [
                '/api/manage/server/command',
                '/api/manage/server/console',
            ];

            foreach ($paths as $path) {
                $url = "http://{$this->ip}:{$this->port}{$path}";
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => $headers,
                        'content' => $payload,
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ]
                ]);

                $response = @file_get_contents($url, false, $context);
                if ($response !== false) {
                    return $response;
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getStatus(string $folder, ?int $port = null): array
    {
        $resolvedPort = $this->resolvePort($folder, $port);
        $isRunning = $this->checkFxServerStatus($resolvedPort);

        return [
            'engine' => 'fivem',
            'running' => $isRunning,
            'executable' => 'FXServer.exe',
            'port' => $resolvedPort,
            'default_port' => 30120,
        ];
    }

    private function resolvePort(string $folder, ?int $port): int
    {
        if ($port !== null) {
            return $port;
        }

        $configFile = $this->findConfigFile($folder);
        if ($configFile !== null) {
            $configContent = @file_get_contents($configFile);
            if ($configContent !== false) {
                $detectedPort = $this->detectPortFromConfig($configContent);
                if ($detectedPort !== null) {
                    return $detectedPort;
                }
            }
        }

        return 30120;
    }

    private function findConfigFile(string $folder): ?string
    {
        $candidates = [
            'server.cfg',
            'config.cfg',
            'server.cfg.txt',
            'config.cfg.txt'
        ];

        foreach ($candidates as $candidate) {
            $path = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function detectPortFromConfig(string $content): ?int
    {
        if (preg_match('/endpoint_add_(?:tcp|udp)\s+["\']?[^"\']*:(\d+)["\']?/i', $content, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function checkFxServerStatus(int $port, string $host = '127.0.0.1'): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket) {
            fclose($socket);
            return true;
        }

        return false;
    }

    public static function detect(string $executable, string $folder): bool
    {
        // FiveM usa FXServer.exe
        if (stripos($executable, 'FXServer.exe') !== false) {
            return true;
        }

        // Também detecta se tem artifacts/ e resources/ (estrutura FiveM)
        $artifactsDir = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artifacts';
        $resourcesDir = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'resources';
        
        return (is_dir($artifactsDir) || is_dir($resourcesDir));
    }
}
