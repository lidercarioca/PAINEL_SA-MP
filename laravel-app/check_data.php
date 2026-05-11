<?php

require_once 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;

echo 'Users: ' . User::count() . PHP_EOL;
echo 'Servers: ' . Server::count() . PHP_EOL;
echo 'Plans: ' . Plan::count() . PHP_EOL;
$plan = Plan::first();
echo 'First Plan ID: ' . ($plan ? $plan->id : 'none') . PHP_EOL;
echo 'First Plan Name: ' . ($plan ? $plan->name : 'none') . PHP_EOL;