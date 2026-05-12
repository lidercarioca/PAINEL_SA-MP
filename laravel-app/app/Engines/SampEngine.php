<?php

namespace App\Engines;

use App\Services\SampRconService;
use App\Services\LocalServerService;

class SampEngine implements EngineInterface
{
    private SampRconService $rconService;
    private LocalServerService $localServerService;
    private string $ip;
    private int $port;
    private string $password;

    public function __construct(
        SampRconService $rconService,
        LocalServerService $localServerService,
        string $ip,
        int $port,
        string $password
    ) {
        $this->rconService = $rconService;
        $this->localServerService = $localServerService;
        $this->ip = $ip;
        $this->port = $port;
        $this->password = $password;
    }

    public function start(string $folder): bool
    {
        return $this->localServerService->startServer($folder, 'samp-server.exe');
    }

    public function stop(): bool
    {
        return $this->rconService->sendCommand($this->ip, $this->port, $this->password, 'exit');
    }

    public function restart(string $folder): bool
    {
        $this->stop();
        sleep(2);
        return $this->start($folder);
    }

    public function getLogs(string $folder): string
    {
        $logFile = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'server_log.txt';
        
        if (!file_exists($logFile)) {
            return '';
        }

        return file_get_contents($logFile) ?: '';
    }

    public function sendCommand(string $command): bool|string
    {
        return $this->rconService->sendCommandWithResponse(
            $this->ip,
            $this->port,
            $this->password,
            $command
        );
    }

    public function getStatus(string $folder, ?int $port = null): array
    {
        $isRunning = $this->localServerService->isRunning(['folder' => $folder, 'type' => 'local']);
        
        $playersResponse = null;
        if ($isRunning && !empty($this->password)) {
            $playersResponse = $this->sendCommand('players');
        }

        return [
            'engine' => 'samp',
            'running' => $isRunning,
            'players_info' => $playersResponse,
            'executable' => 'samp-server.exe',
            'default_port' => 7777,
        ];
    }

    public static function detect(string $executable, string $folder): bool
    {
        // SA-MP usa samp-server.exe
        return stripos($executable, 'samp-server.exe') !== false;
    }
}
