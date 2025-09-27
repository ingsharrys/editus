<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Si quieres forzar la zona horaria del scheduler.
     */
    protected function scheduleTimezone(): string
    {
        return config('app.timezone', 'America/Bogota');
    }

    /**
     * Define tareas programadas.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Actualiza automáticamente alcance / visualizaciones / interacciones en meta_posts
        $schedule->command('meta:collect-metrics-simple --limit=500')
            ->everyFifteenMinutes()
            ->withoutOverlapping()   // evita solapamientos si la anterior sigue corriendo
            ->onOneServer()          // si tienes varios workers/servidores
            ->runInBackground()      // no bloquea el scheduler si hay más tareas
            ->appendOutputTo(storage_path('logs/schedule.log')); // guarda salida en log
    }

    /**
     * Registra tus Artisan commands.
     * - Si usas autodiscovery, igual deja esto para cargar routes/console.php.
     */
    protected function commands(): void
    {
        // Autocarga todos los comandos en app/Console/Commands
        $this->load(__DIR__ . '/Commands');

        // (Opcional) también puedes requerir rutas de consola si las usas
        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }

    /**
     * Si NO usas autodiscovery de comandos,
     * puedes declararlos explícitamente aquí.
     */
    protected $commands = [
        \App\Console\Commands\CollectSimpleMetaInsights::class,
    ];
}
