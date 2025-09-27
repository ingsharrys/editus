<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    /**
     * Forzar la zona horaria del scheduler (usa la de config/app.php).
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
        // Actualiza métricas de meta_posts SOLO entre 4:00 y 5:00 pm (hora Colombia),
        // ejecutándose cada 5 minutos en esa ventana.
        $schedule->command('meta:collect-metrics-simple --limit=500 --only-missing')
            ->everyFiveMinutes()
            ->between('16:00', '17:00')          // ventana 4–5 pm
            ->withoutOverlapping()               // evita solapamientos
            ->onOneServer()                      // si hay varios servidores
            ->runInBackground()                  // no bloquea si hay más tareas
            ->appendOutputTo(storage_path('logs/metrics.log')); // log dedicado
    }

    /**
     * Registro de comandos Artisan.
     */
    protected function commands(): void
    {
        // Autocarga todos los comandos en app/Console/Commands
        $this->load(__DIR__ . '/Commands');

        // (Opcional) rutas de consola
        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }

    /**
     * Si no usas autodiscovery, declara aquí tus comandos.
     */
    protected $commands = [
        \App\Console\Commands\CollectSimpleMetaInsights::class,
    ];
}
