<?php

namespace App\Engines;

class FiveM Engine implements EngineInterface
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
        // Esta é uma implementação base que pode ser expandida
        $executable = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'FXServer.exe';
        
        if (!file_exists($executable)) {
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

    public function getStatus(string $folder): array
    {
        // Verifica se FXServer está rodando
        $isRunning = $this->checkFxServerStatus();

        return [
            'engine' => 'fivem',
            'running' => $isRunning,
            'executable' => 'FXServer.exe',
            'default_port' => 30120,
        ];
    }

    private function checkFxServerStatus(): bool
    {
        $socket = @fsockopen($this->ip, $this->port, $errno, $errstr, 2);
        
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
