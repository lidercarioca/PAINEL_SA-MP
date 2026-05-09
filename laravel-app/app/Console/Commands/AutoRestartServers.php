<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\ActionLogService;
use App\Services\LocalServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AutoRestartServers extends Command
{
    protected $signature = 'servers:auto-restart';
    protected $description = 'Verifica servidores locais e reinicia automaticamente conforme configuração.';

    public function handle(LocalServerService $localServerService)
    {
        $servers = Server::where('type', 'local')->get();

        foreach ($servers as $server) {
            $running = $localServerService->isRunning($server);
            $statusChanged = false;

            if ($running && $server->status !== 'online' && $server->status !== 'suspended') {
                $server->status = 'online';
                $statusChanged = true;
            }

            if (!$running && $server->status === 'online' && $server->auto_restart_on_crash) {
                $this->restartServer($server, $localServerService, 'crash');
                continue;
            }

            if (!$running && $server->status !== 'suspended' && $server->auto_restart_on_offline) {
                $this->restartServer($server, $localServerService, 'offline');
                continue;
            }

            if ($running && $server->auto_restart_interval_hours > 0) {
                $last = $server->last_auto_restart_at ? Carbon::parse($server->last_auto_restart_at) : null;
                if ($last === null || now()->diffInHours($last) >= $server->auto_restart_interval_hours) {
                    $this->restartServer($server, $localServerService, 'interval');
                    continue;
                }
            }

            if ($statusChanged) {
                $server->save();
            }
        }

        return 0;
    }

    protected function restartServer(Server $server, LocalServerService $localServerService, string $reason): void
    {
        $result = $localServerService->restart($server);
        if ($result['success']) {
            $server->status = 'online';
            $server->last_auto_restart_at = now();
            $server->save();
            ActionLogService::append("Servidor {$server->name} ({$server->ip}:{$server->port}) reiniciado automaticamente ({$reason}).");
            $this->info("Servidor {$server->id} reiniciado ({$reason}).");
        } else {
            $server->status = 'offline';
            $server->save();
            ActionLogService::append("Falha ao reiniciar automaticamente o servidor {$server->name} ({$server->ip}:{$server->port}) ({$reason}): {$result['message']}");
            $this->error("Falha ao reiniciar servidor {$server->id} ({$reason}): {$result['message']}");
        }
    }
}
