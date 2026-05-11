<?php

namespace App\Console;

use App\Console\Commands\ImportJsonData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        ImportJsonData::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('servers:auto-restart')->everyMinute();
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
