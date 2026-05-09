<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;

class SecurityController extends Controller
{
    public function index()
    {
        $logPath = dirname(__DIR__, 4) . '/database/server_log.txt';

        if (!File::exists($logPath)) {
            File::put($logPath, '');
        }

        $lines = array_values(array_filter(array_map('trim', File::lines($logPath)->toArray()), fn ($line) => $line !== ''));
        $events = array_filter($lines, function ($line) {
            return str_contains($line, 'login') || str_contains($line, 'bloqueado') || str_contains($line, 'token inválido') || str_contains($line, 'Autorizado') || str_contains($line, 'tentativa');
        });

        return response()->json(['events' => array_values($events)]);
    }
}
