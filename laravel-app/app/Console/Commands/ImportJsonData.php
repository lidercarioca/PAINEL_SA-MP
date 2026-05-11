<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class ImportJsonData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:import-json {--force : Forçar importação mesmo se dados já existirem}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importar dados dos arquivos JSON para o banco de dados';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando importação de dados JSON para banco de dados...');

        $force = $this->option('force');

        // Verificar se já existem dados
        if (!$force && (User::count() > 0 || Server::count() > 0 || Plan::count() > 0)) {
            $this->warn('Dados já existem no banco. Use --force para sobrescrever.');
            return;
        }

        // Importar usuários
        $this->importUsers();

        // Importar planos
        $this->importPlans();

        // Importar servidores
        $this->importServers();

        $this->info('Importação concluída!');
    }

    private function importUsers()
    {
        $this->info('Importando usuários...');

        $usersFile = database_path('users.json');
        if (!File::exists($usersFile)) {
            $this->warn('Arquivo users.json não encontrado.');
            return;
        }

        $users = json_decode(File::get($usersFile), true);
        if (!$users) {
            $this->error('Erro ao ler users.json');
            return;
        }

        foreach ($users as $userData) {
            User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => isset($userData['password']) ? Hash::make($userData['password']) : Hash::make('password'),
                'role' => $userData['role'],
                'api_token' => $userData['api_token'] ?? null,
                'blocked' => false,
            ]);
        }

        $this->info('Usuários importados: ' . count($users));
    }

    private function importPlans()
    {
        $this->info('Importando planos...');

        $plansFile = database_path('plans.json');
        if (!File::exists($plansFile)) {
            $this->warn('Arquivo plans.json não encontrado.');
            return;
        }

        $plans = json_decode(File::get($plansFile), true);
        if (!$plans) {
            $this->error('Erro ao ler plans.json');
            return;
        }

        foreach ($plans as $planData) {
            Plan::create([
                'name' => $planData['name'],
                'slots' => $planData['slots'],
                'price' => $planData['price'],
                'description' => $planData['description'] ?? '',
            ]);
        }

        $this->info('Planos importados: ' . count($plans));
    }

    private function importServers()
    {
        $this->info('Importando servidores...');

        $serversFile = database_path('servers.json');
        if (!File::exists($serversFile)) {
            $this->warn('Arquivo servers.json não encontrado.');
            return;
        }

        $servers = json_decode(File::get($serversFile), true);
        if (!$servers) {
            $this->error('Erro ao ler servers.json');
            return;
        }

        foreach ($servers as $serverData) {
            Server::create([
                'name' => $serverData['name'],
                'ip' => $serverData['ip'],
                'port' => $serverData['port'],
                'password' => $serverData['password'] ?? '',
                'type' => $serverData['type'] ?? 'local',
                'engine' => $serverData['engine'] ?? 'samp',
                'status' => $serverData['status'] ?? 'offline',
                'folder' => $serverData['folder'] ?? '',
                'owner_id' => $serverData['owner_id'] ?? null,
                'plan_id' => $serverData['plan_id'] ?? 1,
                'game_mode' => $serverData['game_mode'] ?? 'Freeroam',
                'limit_ram' => $serverData['limit_ram'] ?? 1024,
                'limit_slots' => $serverData['limit_slots'] ?? 50,
                'disk_limit_gb' => $serverData['disk_limit_gb'] ?? 10,
                'auto_restart_interval_hours' => $serverData['auto_restart_interval_hours'] ?? 24,
                'auto_restart_on_crash' => $serverData['auto_restart_on_crash'] ?? true,
                'auto_restart_on_offline' => $serverData['auto_restart_on_offline'] ?? false,
            ]);
        }

        $this->info('Servidores importados: ' . count($servers));
    }
}
