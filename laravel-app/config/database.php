<?php

use Illuminate\Support\Str;

$sqlitePath = env('DB_DATABASE', database_path('database.sqlite'));
$isWindowsAbsolute = preg_match('#^[A-Za-z]:(\\\\|/)#', $sqlitePath);
$isUnixAbsolute = Str::startsWith($sqlitePath, ['/','\\']);
if (! $isWindowsAbsolute && ! $isUnixAbsolute) {
    $sqlitePath = database_path($sqlitePath);
}

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
    ],

    'migrations' => 'migrations',
];
