<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
/**
 * PROBES: borrar cuando confirmes
 */
Schedule::command('inspire')
    ->everyMinute()
    ->appendOutputTo(storage_path('logs/_probe.log'));

Schedule::call(fn () => Log::info('[probe] schedule tick', ['at' => now()->toDateTimeString()]))
    ->everyMinute();

/**
 * MÉTRICAS: producción
 * - Ajusta la ventana (ej. '16:00','17:00')
 * - Para probar YA: usa everyMinute() y comenta between()
 */
Schedule::command('meta:collect-metrics-simple --limit=500 --only-missing')
    ->everyFiveMinutes()
    ->between('17:30', '17:45') // ← tu ventana real
    ->timezone(config('app.timezone', 'America/Bogota'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/metrics.log'));