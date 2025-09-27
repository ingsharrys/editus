<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
/**
 * ───── PROBES (para verificar que el scheduler corre) ─────
 * Puedes borrarlas cuando confirmes que funciona.
 */
Schedule::command('inspire')
    ->everyMinute()
    ->appendOutputTo(storage_path('logs/_probe.log'));

Schedule::call(fn() => Log::info('[probe] schedule tick', ['at' => now()->toDateTimeString()]))
    ->everyMinute();

/**
 * ───── TU TAREA DE MÉTRICAS ─────
 * Modo producción: cada 5 minutos solo entre 4 y 5 pm (Colombia)
 */
Schedule::command('meta:collect-metrics-simple --limit=500 --only-missing')
    ->everyFiveMinutes()
    ->between('16:00', '17:00')
    ->timezone(config('app.timezone', 'America/Bogota')) // fuerza TZ de Colombia
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/metrics.log'));