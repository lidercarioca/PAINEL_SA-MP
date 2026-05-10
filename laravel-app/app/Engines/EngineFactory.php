<?php

namespace App\Engines;

use App\Models\Server;
use App\Services\SampRconService;
use App\Services\LocalServerService;
use App\Engines\FiveMEngine;

class EngineFactory
{
    private SampRconService $rconService;
    private LocalServerService $localServerService;

    public function __construct(
        SampRconService $rconService,
        LocalServerService $localServerService
    ) {
        $this->rconService = $rconService;
        $this->localServerService = $localServerService;
    }

    /**
     * Cria uma instância de engine baseado no servidor
     * 
     * @param Server $server
     * @return EngineInterface
     */
    public function create(Server $server): EngineInterface
    {
        // Se engine está vazio, detecta automaticamente
        if (empty($server->engine)) {
            $engine = $this->autoDetect($server);
            return $this->createByEngine($engine, $server);
        }

        return $this->createByEngine($server->engine, $server);
    }

    /**
     * Cria engine por tipo específico
     * 
     * @param string $engine
     * @param Server $server
     * @return EngineInterface
     */
    private function createByEngine(string $engine, Server $server): EngineInterface
    {
        return match ($engine) {
            'samp' => new SampEngine(
                $this->rconService,
                $this->localServerService,
                $server->ip,
                $server->port,
                $server->password ?? ''
            ),
            'fivem' => new FiveMEngine(
                $server->ip,
                $server->port,
                null // API token pode ser adicionado depois
            ),
            default => new SampEngine(
                $this->rconService,
                $this->localServerService,
                $server->ip,
                $server->port,
                $server->password ?? ''
            )
        };
    }

    /**
     * Auto-detecta o engine baseado nas características do servidor
     * 
     * @param Server $server
     * @return string
     */
    public function autoDetect(Server $server): string
    {
        $folder = $server->folder ?? '';
        $executable = '';

        // Tenta encontrar o executável na pasta
        if (!empty($folder) && is_dir($folder)) {
            $files = scandir($folder);
            foreach ($files as $file) {
                if (stripos($file, '.exe') !== false) {
                    $executable = $file;
                    break;
                }
            }
        }

        // Detecta baseado no nome do executável
        if (FiveMEngine::detect($executable, $folder)) {
            return 'fivem';
        }

        if (SampEngine::detect($executable, $folder)) {
            return 'samp';
        }

        // Fallback para SA-MP se não conseguir detectar
        return 'samp';
    }

    /**
     * Obtém informações padrão sobre um engine
     * 
     * @param string $engine
     * @return array
     */
    public static function getEngineDefaults(string $engine): array
    {
        return match ($engine) {
            'samp' => [
                'port' => 7777,
                'executable' => 'samp-server.exe',
                'config_file' => 'server.cfg',
                'logs_dir' => '/',
            ],
            'fivem' => [
                'port' => 30120,
                'executable' => 'FXServer.exe',
                'config_file' => 'server.cfg', // FiveM também usa server.cfg
                'logs_dir' => '/logs/',
            ],
            default => [
                'port' => 7777,
                'executable' => 'samp-server.exe',
                'config_file' => 'server.cfg',
                'logs_dir' => '/',
            ]
        };
    }

    /**
     * Lista os engines disponíveis
     * 
     * @return array
     */
    public static function getAvailableEngines(): array
    {
        return [
            [
                'id' => 'samp',
                'name' => 'SA-MP',
                'description' => 'San Andreas Multiplayer',
                'icon' => 'gamepad2'
            ],
            [
                'id' => 'fivem',
                'name' => 'FiveM',
                'description' => 'FiveM - Grand Theft Auto V',
                'icon' => 'rocket'
            ]
        ];
    }
}
