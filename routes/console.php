<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

// Artisan::command('meta:sync-page-tokens {--user-id=}', function () {
//     $uid = (int) $this->option('user-id') ?: 2;

//     $rows = DB::table('meta_page_user as mpu')
//         ->join('meta_pages as mp', 'mp.id', '=', 'mpu.meta_page_id')
//         ->leftJoin('social_accounts as sa', 'sa.id', '=', 'mpu.social_account_id')
//         ->where('mpu.user_id', $uid)
//         ->whereNull('mpu.page_access_token')
//         ->whereNotNull('sa.access_token') // token de usuario
//         ->select('mpu.id as pivot_id','mp.id as meta_page_id','mp.page_id',
//                  'sa.access_token as user_token')
//         ->get();

//     $ok = $fail = 0;
//     foreach ($rows as $r) {
//         try {
//             $resp = Http::get("https://graph.facebook.com/v23.0/{$r->page_id}", [
//                 'fields'       => 'access_token',
//                 'access_token' => $r->user_token, // USER token
//             ]);

//             if ($resp->ok() && ($pageToken = data_get($resp->json(), 'access_token'))) {
//                 DB::table('meta_page_user')
//                     ->where('id', $r->pivot_id)
//                     ->update([
//                         'page_access_token' => $pageToken,
//                         'is_active'         => 1,
//                         'updated_at'        => now(),
//                     ]);
//                 $ok++;
//             } else {
//                 $this->warn("No pude obtener page token para page_id={$r->page_id}");
//                 $fail++;
//             }
//         } catch (\Throwable $e) {
//             $this->warn("Error page_id={$r->page_id}: {$e->getMessage()}");
//             $fail++;
//         }
//     }

//     $this->info("Listo. Tokens actualizados: {$ok}. Fallidos: {$fail}.");
// });


Artisan::command('meta:collect-metrics-pages
    {--user-id= : Tomar páginas activas de este user (pivot meta_page_user)}
    {--pages= : CSV de meta_page_id (IDs de tu tabla meta_pages.id)}
    {--only-missing : Solo posts sin métricas (o con alguna nula)}
    {--since= : ISO datetime para filtrar published_at >= since}
    {--chunk=200 : Tamaño de lote para procesar posts}
', function () {
    $svc   = app(\App\Services\MetaInsightsService::class);
    $uid   = $this->option('user-id');
    $csv   = $this->option('pages');
    $chunk = (int) $this->option('chunk') ?: 200;

    // 1) Resolver meta_page_id (igual que tu SQL de origen)
    $metaPageIds = [];

    if ($csv) {
        $metaPageIds = collect(explode(',', $csv))
            ->map(fn($v) => (int) trim($v))
            ->filter()->unique()->values()->all();
    } elseif ($uid) {
        $metaPageIds = DB::table('meta_page_user')
            ->where('user_id', (int) $uid)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->pluck('meta_page_id')->unique()->values()->all();
    } else {
        $this->error('Debes pasar --user-id=... o --pages=1,2,3');
        return 1;
    }

    if (empty($metaPageIds)) {
        $this->error('No hay páginas válidas para procesar (revisa pivot/tokens).');
        return 1;
    }

    $onlyMissing = (bool) $this->option('only-missing');
    $since       = $this->option('since'); // ej: 2025-09-01T00:00:00-05:00

    $this->info('Páginas a procesar: '.implode(',', $metaPageIds));

    // 2) Query ÚNICO (JOIN idéntico a tu SQL) sobre meta_posts
    $q = \App\Models\MetaPost::query()
        ->select('meta_posts.*')
        ->join('meta_pages as mp', 'mp.id', '=', 'meta_posts.meta_page_id')
        ->join('meta_page_user as mpu', function($j) use ($uid) {
            // Si pasaste --pages, igual exigimos pivot activo con token;
            // si pasaste --user-id, filtramos por ese user.
            if ($uid) {
                $j->on('mpu.meta_page_id', '=', 'mp.id')
                  ->where('mpu.user_id', (int) $uid);
            } else {
                $j->on('mpu.meta_page_id', '=', 'mp.id');
            }
            $j->where('mpu.is_active', 1)
              ->whereNotNull('mpu.page_access_token');
        })
        ->whereIn('mp.id', $metaPageIds);

    if ($onlyMissing) {
        $q->where(function($qq){
            $qq->whereNull('meta_posts.last_insights_at')
               ->orWhereNull('meta_posts.alcance')
               ->orWhereNull('meta_posts.visualizaciones')
               ->orWhereNull('meta_posts.interacciones');
        });
    }
    if ($since) {
        $q->where('meta_posts.published_at', '>=', $since);
    }

    // Orden similar a tu SQL para inspección (no afecta el cálculo)
    $q->orderBy('mp.id')->orderBy('meta_posts.published_at', 'desc');

    $total = (clone $q)->count();
    $this->line("Total de posts a procesar: {$total}");

    $processed = 0;
    $q->chunk($chunk, function($rows) use ($svc, &$processed) {
        foreach ($rows as $p) {
            try {
                $svc->updatePostMetrics($p);
                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[metrics][bulk:error]', [
                    'meta_post_id' => $p->id,
                    'err' => $e->getMessage(),
                ]);
            }
        }
    });

    $this->info("FINALIZADO. Total posts procesados: {$processed}");
    return 0;
})->purpose('Recoge métricas (JOIN a pages+pivot) en una sola pasada y termina');
