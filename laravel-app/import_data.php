<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

echo "Limpando dados existentes...\n";
User::query()->delete();
Server::query()->delete();
Plan::query()->delete();

// Reset auto-increment
\DB::statement('DELETE FROM sqlite_sequence WHERE name="users"');
\DB::statement('DELETE FROM sqlite_sequence WHERE name="servers"');
\DB::statement('DELETE FROM sqlite_sequence WHERE name="plans"');

echo "Iniciando importação de dados JSON...\n";

// Importar usuários
echo "Importando usuários...\n";
$usersFile = database_path('users.json');
if (File::exists($usersFile)) {
    $users = json_decode(File::get($usersFile), true);
    if ($users) {
        foreach ($users as $userData) {
            User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'role' => $userData['role'],
                'api_token' => $userData['api_token'] ?? null,
                'blocked' => false,
            ]);
        }
        echo "Usuários importados: " . count($users) . "\n";
    }
}

// Importar planos
echo "Importando planos...\n";
$plansFile = database_path('plans.json');
if (File::exists($plansFile)) {
    $plans = json_decode(File::get($plansFile), true);
    if ($plans) {
        foreach ($plans as $planData) {
            Plan::create([
                'name' => $planData['name'],
                'slots' => $planData['slots'],
                'price' => $planData['price'],
                'description' => $planData['description'] ?? '',
            ]);
        }
        echo "Planos importados: " . count($plans) . "\n";
    }
}

// Importar servidores
echo "Importando servidores...\n";
$serversFile = database_path('servers.json');
if (File::exists($serversFile)) {
    $servers = json_decode(File::get($serversFile), true);
    if ($servers) {
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
                'plan_id' => Plan::find(1) ? 1 : null,
                'game_mode' => $serverData['game_mode'] ?? 'Freeroam',
                'limit_ram' => $serverData['limit_ram'] ?? 1024,
                'limit_slots' => $serverData['limit_slots'] ?? 50,
                'disk_limit_gb' => $serverData['disk_limit_gb'] ?? 10,
                'auto_restart_interval_hours' => $serverData['auto_restart_interval_hours'] ?? 24,
                'auto_restart_on_crash' => $serverData['auto_restart_on_crash'] ?? true,
                'auto_restart_on_offline' => $serverData['auto_restart_on_offline'] ?? false,
            ]);
        }
        echo "Servidores importados: " . count($servers) . "\n";
    }
}

echo "Importação concluída!\n";