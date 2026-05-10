<?php

namespace App\Services;

use App\Models\Server;
use App\Engines\EngineFactory;

/**
 * Serviço de exemplo para demonstrar uso da arquitetura multi-engine
 * 
 * Este arquivo mostra como usar a EngineFactory para criar e gerenciar
 * servidores de diferentes engines sem duplicação de código.
 */
class MultiEngineExampleService
{
    private EngineFactory $engineFactory;

    public function __construct(EngineFactory $engineFactory)
    {
        $this->engineFactory = $engineFactory;
    }

    /**
     * Exemplo 1: Iniciar servidor qualquer engine
     */
    public function startServer(Server $server): bool
    {
        $engine = $this->engineFactory->create($server);
        return $engine->start($server->folder);
    }

    /**
     * Exemplo 2: Parar servidor qualquer engine
     */
    public function stopServer(Server $server): bool
    {
        $engine = $this->engineFactory->create($server);
        return $engine->stop();
    }

    /**
     * Exemplo 3: Reiniciar servidor qualquer engine
     */
    public function restartServer(Server $server): bool
    {
        $engine = $this->engineFactory->create($server);
        return $engine->restart($server->folder);
    }

    /**
     * Exemplo 4: Obter status do servidor
     */
    public function getServerStatus(Server $server): array
    {
        $engine = $this->engineFactory->create($server);
        return $engine->getStatus($server->folder);
    }

    /**
     * Exemplo 5: Obter logs do servidor
     */
    public function getServerLogs(Server $server): string
    {
        $engine = $this->engineFactory->create($server);
        return $engine->getLogs($server->folder);
    }

    /**
     * Exemplo 6: Enviar comando ao servidor
     * 
     * Nota: SA-MP suporta, FiveM não (RCON SA-MP)
     */
    public function sendCommand(Server $server, string $command): bool|string
    {
        $engine = $this->engineFactory->create($server);
        return $engine->sendCommand($command);
    }

    /**
     * Exemplo 7: Auto-detectar engine e atualizar no banco
     */
    public function autoDetectAndSaveEngine(Server $server): string
    {
        $detectedEngine = $this->engineFactory->autoDetect($server);
        $server->update(['engine' => $detectedEngine]);
        return $detectedEngine;
    }

    /**
     * Exemplo 8: Obter informações de defaults para um engine
     */
    public function getEngineDefaults(string $engine): array
    {
        return EngineFactory::getEngineDefaults($engine);
    }

    /**
     * Exemplo 9: Listar todos os engines disponíveis
     */
    public function getAvailableEngines(): array
    {
        return EngineFactory::getAvailableEngines();
    }

    /**
     * Exemplo 10: Migrar servidor de um engine para outro
     */
    public function migrateServerEngine(Server $server, string $newEngine): void
    {
        // 1. Parar servidor antigo
        $oldEngine = $this->engineFactory->create($server);
        $oldEngine->stop();

        // 2. Atualizar engine
        $server->update(['engine' => $newEngine]);

        // 3. Obter defaults do novo engine
        $defaults = EngineFactory::getEngineDefaults($newEngine);

        // 4. Atualizar dados do servidor conforme necessário
        if ($server->port === 7777 && $newEngine === 'fivem') {
            $server->update(['port' => $defaults['port']]);
        }

        // 5. Iniciar com novo engine
        $newEngineInstance = $this->engineFactory->create($server);
        $newEngineInstance->start($server->folder);

        ActionLogService::append(
            "Servidor {$server->name} migrado de {$oldEngine} para {$newEngine}"
        );
    }

    /**
     * Exemplo 11: Processar múltiplos servidores
     */
    public function restartAllServersOfEngine(string $engine): int
    {
        $servers = Server::where('engine', $engine)->get();
        $count = 0;

        foreach ($servers as $server) {
            if ($this->restartServer($server)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Exemplo 12: Health check engine-aware
     */
    public function healthCheckServer(Server $server): array
    {
        $engine = $this->engineFactory->create($server);
        $status = $engine->getStatus($server->folder);

        return [
            'server_id' => $server->id,
            'name' => $server->name,
            'engine' => $status['engine'],
            'running' => $status['running'],
            'port' => $server->port,
            'check_time' => now()->toIso8601String(),
        ];
    }
}

/**
 * Uso em Controllers:
 * 
 * class ServerController extends Controller {
 *     public function start(Request $request, MultiEngineExampleService $service) {
 *         $server = Server::find($request->input('server_id'));
 *         $success = $service->startServer($server);
 *         return response()->json(['success' => $success]);
 *     }
 * }
 * 
 * Vantagens:
 * - Mesmo código funciona para qualquer engine
 * - Fácil estender para novo engine
 * - Zero duplicação de lógica
 * - Engine-aware automaticamente
 * - Type-safe via Interface
 */
