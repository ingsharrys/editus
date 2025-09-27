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

Schedule::call(fn () => Log::info('[probe] schedule tick', ['at' => now()->toDateTimeString()]))
    ->everyMinute();


/**
 * ───── TU TAREA DE MÉTRICAS ─────
 * Producción: ajusta la ventana a lo que necesites.
 * (Ejemplo: solo entre 17:00 y 17:15 COL, cada 5 minutos)
 */
Schedule::command('meta:collect-metrics-simple --limit=500 --only-missing')
    ->everyFiveMinutes()
    ->between('17:15', '17:30')   // ← cambia a ('16:00','17:00') para tu ventana real
    ->timezone(config('app.timezone', 'America/Bogota'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/metrics.log'));