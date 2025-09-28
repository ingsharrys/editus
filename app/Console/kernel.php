<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;


class Kernel extends ConsoleKernel
{

    protected function scheduleTimezone(): string
    {
        return config('app.timezone', 'America/Bogota');
    }

    protected function schedule(Schedule $schedule): void
    {
        // (1) Sonda: comando "inspire" cada minuto → logs/_probe.log
        $schedule->command('inspire')
            ->everyMinute()
            ->appendOutputTo(storage_path('logs/_probe.log'));

        // (2) Sonda: escribe una línea en laravel.log cada minuto
        $schedule->call(fn () => Log::info('[probe] schedule tick', ['at' => now()->toDateTimeString()]))
            ->everyMinute();

        // (3) Tu recolector de métricas cada minuto (sin ventana por ahora)
        $schedule->command('meta:collect-metrics-simple --limit=500 --only-missing')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/metrics.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }

    protected $commands = [
        \App\Console\Commands\CollectSimpleMetaInsights::class,
    ];
}
