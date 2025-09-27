<?php
if (file_exists(storage_path('app/disable-scheduler'))) { return; }

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
// PROBES
// Schedule::command('inspire')
//     ->everyMinute()
//     ->appendOutputTo(storage_path('logs/_probe.log'));

// Schedule::call(fn () => Log::info('[probe] schedule tick', ['at' => now()->toDateTimeString()]))
//     ->everyMinute();

// // MÉTRICAS — MODO PRUEBA: cada minuto, SIN withoutOverlapping ni ventana:
// Schedule::command('meta:collect-metrics-simple --limit=500 --only-missing')
//     ->everyMinute()
//     //->between('16:00', '17:00')   // ← comentar en prueba
//     //->withoutOverlapping()        // ← comentar en prueba (evita atascarse por mutex)
//     ->timezone(config('app.timezone', 'America/Bogota'))
//     ->appendOutputTo(storage_path('logs/metrics.log'));