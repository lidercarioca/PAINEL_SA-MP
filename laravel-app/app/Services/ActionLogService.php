<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ActionLogService
{
    public static function append(string $message): void
    {
        $logPath = dirname(__DIR__, 2) . '/database/server_log.txt';

        if (!File::exists($logPath)) {
            File::put($logPath, '');
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        File::append($logPath, "[{$timestamp}] {$message}\n");
    }
}
