<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inspire')
    ->everyMinute()
    ->timezone(config('app.timezone', 'America/Bogota'))
    ->appendOutputTo(storage_path('logs/_probe.log'));

Schedule::call(fn() => Log::info('[probe] schedule tick', ['at' => now()->toDateTimeString()]))
    ->everyMinute()
    ->timezone(config('app.timezone', 'America/Bogota'));


// ===== One-shot por páginas =====
Artisan::command('meta:collect-metrics-pages
    {--user-id= : Tomar páginas activas de este user (pivot meta_page_user)}
    {--pages= : CSV de meta_page_id (IDs de tu tabla meta_pages.id) }
    {--only-missing : Solo posts sin métricas (o con alguna nula)}
    {--since= : ISO datetime para filtrar published_at >= since}
    {--per-page=500 : Máximo de posts por página en esta corrida}
    {--chunk=100 : Tamaño de chunk para procesar posts}
    {--desc : Procesar los posts más nuevos primero}
    {--sleep-pages=2 : Segundos de pausa entre páginas}
', function () {
    $svc = app(\App\Services\MetaInsightsService::class);

    // 1) Resolver el set de páginas
    $metaPageIds = [];

    // a) CSV explícito de meta_page_id
    if ($csv = $this->option('pages')) {
        $metaPageIds = collect(explode(',', $csv))
            ->map(fn($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    // b) Por user_id (pivot), solo activas y con page_access_token
    if (empty($metaPageIds) && ($uid = $this->option('user-id'))) {
        $metaPageIds = DB::table('meta_page_user')
            ->where('user_id', (int) $uid)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->pluck('meta_page_id')
            ->unique()
            ->values()
            ->all();
    }

    if (empty($metaPageIds)) {
        $this->error('No hay páginas a procesar. Usa --pages=1,2,3 o --user-id=2');
        return 1;
    }

    $onlyMissing = (bool) $this->option('only-missing');
    $since = $this->option('since');    // ej: 2025-09-01T00:00:00-05:00
    $perPage = (int) $this->option('per-page') ?: 500;
    $chunk = (int) $this->option('chunk') ?: 100;
    $desc = (bool) $this->option('desc');
    $sleepPages = (int) $this->option('sleep-pages') ?: 0;

    $this->info('Páginas a procesar: ' . implode(',', $metaPageIds));
    $totalProcessed = 0;

    foreach ($metaPageIds as $metaPageId) {
        $this->line("→ Página meta_page_id={$metaPageId}");

        // 2) Query de posts de esa página (respeta orderBy/limit con chunk)
        $q = \App\Models\MetaPost::where('meta_page_id', $metaPageId);

        if ($onlyMissing) {
            $q->where(function ($qq) {
                $qq->whereNull('last_insights_at')
                    ->orWhereNull('alcance')
                    ->orWhereNull('visualizaciones')
                    ->orWhereNull('interacciones');
            });
        }

        if ($since) {
            $q->where('published_at', '>=', $since);
        }

        $q->orderBy('id', $desc ? 'desc' : 'asc')
            ->limit($perPage);

        $count = 0;

        // 3) Procesar en chunks por performance (aquí SÍ respeta orderBy+limit)
        $q->chunk($chunk, function ($rows) use ($svc, &$count) {
            foreach ($rows as $p) {
                try {
                    $svc->updatePostMetrics($p);
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('[metrics][page-batch:error]', [
                        'meta_post_id' => $p->id,
                        'err' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->line("   ✓ Procesados {$count} posts en meta_page_id={$metaPageId}");
        $totalProcessed += $count;

        if ($sleepPages > 0) {
            sleep($sleepPages); // respiro entre páginas
        }
    }

    $this->info("FINALIZADO. Total posts procesados: {$totalProcessed}");
    return 0; // <- one-shot, termina y el cron no queda vivo
})->purpose('Recoge métricas página por página y termina (one-shot)');