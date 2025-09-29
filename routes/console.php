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

Artisan::command('meta:diag-perms {--user-id=} {--limit-posts=1}', function () {
    $uid   = (int) $this->option('user-id');
    $limit = (int) ($this->option('limit-posts') ?: 1);

    if (!$uid) {
        $this->error('Usa --user-id=2');
        return 1;
    }

    // Páginas activas del user con token
    $pages = DB::table('meta_pages as mp')
        ->join('meta_page_user as mpu', 'mpu.meta_page_id', '=', 'mp.id')
        ->where('mpu.user_id', $uid)
        ->where('mpu.is_active', 1)
        ->whereNotNull('mpu.page_access_token')
        ->select('mp.id as meta_page_id', 'mp.page_id', 'mp.name', 'mpu.page_access_token')
        ->orderBy('mp.id')
        ->get();

    if ($pages->isEmpty()) {
        $this->warn('No hay páginas con token activo para este user.');
        return 0;
    }

    foreach ($pages as $pg) {
        $this->line("═ Página #{$pg->meta_page_id} {$pg->name} ({$pg->page_id})");

        // 1) Token válido contra la Page
        $okPage = false;
        $r1 = Http::get("https://graph.facebook.com/v23.0/{$pg->page_id}", [
            'fields'       => 'id,name',
            'access_token' => $pg->page_access_token,
        ]);
        if ($r1->ok()) {
            $okPage = true;
            $this->info("  ✓ token válido para la página");
        } else {
            $this->error("  ✗ token inválido para la página (".$r1->status().")");
            Log::warning('[diag] page_access_fail', ['page_id'=>$pg->page_id, 'body'=>$r1->json() ?: $r1->body()]);
            continue; // sin esto no vale seguir
        }

        // 2) ¿Puede leer posts? (pages_read_engagement)
        $okPosts = false; $lastPostId = null; $lastType = null;
        $r2 = Http::get("https://graph.facebook.com/v23.0/{$pg->page_id}/posts", [
            'fields'       => 'id,created_time,status_type',
            'limit'        => $limit,
            'access_token' => $pg->page_access_token,
        ]);
        if ($r2->ok() && ($data = $r2->json('data')) && count($data)) {
            $okPosts = true;
            $lastPostId = $data[0]['id'] ?? null;
            $lastType   = $data[0]['status_type'] ?? null;
            $this->info("  ✓ puede leer posts (last_post={$lastPostId})");
        } else {
            $this->error("  ✗ no puede leer posts (falta pages_read_engagement?)");
            Log::warning('[diag] posts_fail', ['page_id'=>$pg->page_id, 'body'=>$r2->json() ?: $r2->body()]);
        }

        // 3) ¿Puede leer insights del último post? (read_insights)
        if ($lastPostId) {
            $r3 = Http::get("https://graph.facebook.com/v23.0/{$lastPostId}/insights", [
                'metric'       => 'post_impressions,post_impressions_unique,post_engaged_users',
                'access_token' => $pg->page_access_token,
            ]);
            if ($r3->ok()) {
                $this->info("  ✓ read_insights OK en post {$lastPostId}");
            } else {
                $this->error("  ✗ read_insights falta en post {$lastPostId} (".$r3->status().")");
                Log::warning('[diag] post_insights_fail', ['post_id'=>$lastPostId, 'body'=>$r3->json() ?: $r3->body()]);
            }
        }

        // 4) Si es video y quieres testear video_insights:
        //    Ojo: necesitamos un video_id real. Si tus MetaPosts guardan video_id en fb_post_id para type=video, podrías probar aquí.
        //    Ejemplo de test muy ligero:
        if ($lastPostId && $lastType === 'added_video') {
            // tratar de resolver video_id desde el post
            $r4 = Http::get("https://graph.facebook.com/v23.0/{$lastPostId}", [
                'fields'       => 'attachments{media_type,target{id}}',
                'access_token' => $pg->page_access_token,
            ]);
            $videoId = data_get($r4->json(),'attachments.data.0.target.id');
            if ($videoId) {
                $rv = Http::get("https://graph-video.facebook.com/v23.0/{$videoId}/video_insights", [
                    'metric'       => 'total_video_impressions,total_video_views',
                    'access_token' => $pg->page_access_token,
                ]);
                if ($rv->ok()) {
                    $this->info("  ✓ video_insights OK (video_id={$videoId})");
                } else {
                    $this->error("  ✗ video_insights falla (".$rv->status().")");
                    Log::warning('[diag] video_insights_fail', ['video_id'=>$videoId, 'body'=>$rv->json() ?: $rv->body()]);
                }
            }
        }

        $this->line("");
    }

    return 0;
})->purpose('Diagnóstico por página: token, posts, insights y (opcional) video_insights');

// Artisan::command('meta:collect-metrics-pages
//     {--user-id= : Tomar páginas activas de este user (pivot meta_page_user)}
//     {--pages= : CSV de meta_page_id (IDs de tu tabla meta_pages.id)}
//     {--only-missing : Solo posts sin métricas (o con alguna nula)}
//     {--since= : ISO datetime para filtrar published_at >= since}
//     {--chunk=200 : Tamaño de lote para procesar posts}
// ', function () {
//     $svc   = app(\App\Services\MetaInsightsService::class);
//     $uid   = $this->option('user-id');
//     $csv   = $this->option('pages');
//     $chunk = (int) $this->option('chunk') ?: 200;

//     // 1) Resolver meta_page_id (igual que tu SQL de origen)
//     $metaPageIds = [];

//     if ($csv) {
//         $metaPageIds = collect(explode(',', $csv))
//             ->map(fn($v) => (int) trim($v))
//             ->filter()->unique()->values()->all();
//     } elseif ($uid) {
//         $metaPageIds = DB::table('meta_page_user')
//             ->where('user_id', (int) $uid)
//             ->where('is_active', 1)
//             ->whereNotNull('page_access_token')
//             ->pluck('meta_page_id')->unique()->values()->all();
//     } else {
//         $this->error('Debes pasar --user-id=... o --pages=1,2,3');
//         return 1;
//     }

//     if (empty($metaPageIds)) {
//         $this->error('No hay páginas válidas para procesar (revisa pivot/tokens).');
//         return 1;
//     }

//     $onlyMissing = (bool) $this->option('only-missing');
//     $since       = $this->option('since'); // ej: 2025-09-01T00:00:00-05:00

//     $this->info('Páginas a procesar: '.implode(',', $metaPageIds));

//     // 2) Query ÚNICO (JOIN idéntico a tu SQL) sobre meta_posts
//     $q = \App\Models\MetaPost::query()
//         ->select('meta_posts.*')
//         ->join('meta_pages as mp', 'mp.id', '=', 'meta_posts.meta_page_id')
//         ->join('meta_page_user as mpu', function($j) use ($uid) {
//             // Si pasaste --pages, igual exigimos pivot activo con token;
//             // si pasaste --user-id, filtramos por ese user.
//             if ($uid) {
//                 $j->on('mpu.meta_page_id', '=', 'mp.id')
//                   ->where('mpu.user_id', (int) $uid);
//             } else {
//                 $j->on('mpu.meta_page_id', '=', 'mp.id');
//             }
//             $j->where('mpu.is_active', 1)
//               ->whereNotNull('mpu.page_access_token');
//         })
//         ->whereIn('mp.id', $metaPageIds);

//     if ($onlyMissing) {
//         $q->where(function($qq){
//             $qq->whereNull('meta_posts.last_insights_at')
//                ->orWhereNull('meta_posts.alcance')
//                ->orWhereNull('meta_posts.visualizaciones')
//                ->orWhereNull('meta_posts.interacciones');
//         });
//     }
//     if ($since) {
//         $q->where('meta_posts.published_at', '>=', $since);
//     }

//     // Orden similar a tu SQL para inspección (no afecta el cálculo)
//     $q->orderBy('mp.id')->orderBy('meta_posts.published_at', 'desc');

//     $total = (clone $q)->count();
//     $this->line("Total de posts a procesar: {$total}");

//     $processed = 0;
//     $q->chunk($chunk, function($rows) use ($svc, &$processed) {
//         foreach ($rows as $p) {
//             try {
//                 $svc->updatePostMetrics($p);
//                 $processed++;
//             } catch (\Throwable $e) {
//                 Log::warning('[metrics][bulk:error]', [
//                     'meta_post_id' => $p->id,
//                     'err' => $e->getMessage(),
//                 ]);
//             }
//         }
//     });

//     $this->info("FINALIZADO. Total posts procesados: {$processed}");
//     return 0;
// })->purpose('Recoge métricas (JOIN a pages+pivot) en una sola pasada y termina');
