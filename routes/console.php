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

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
| En el cron de cPanel debe existir UNA sola entrada:
|   * * * * * cd /home/editus/public_html/app.editus.online && php artisan schedule:run >> /dev/null 2>&1
| Todo lo demás se orquesta desde aquí.
*/

// Worker de cola sin acumulación de procesos: atiende los jobs pendientes
// (fotos/videos/Instagram) y TERMINA. withoutOverlapping evita que se
// apilen workers si una corrida tarda más de un minuto.
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(10);

// Estadísticas de páginas: métricas diarias + audiencia + reacciones.
// Una vez al día en la madrugada (los datos de Meta cierran por día).
Schedule::command('stats:collect --days=3')
    ->dailyAt('03:30')
    ->timezone(config('app.timezone', 'America/Bogota'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/stats.log'));

// Métricas de posts recientes que aún no tienen datos (lote acotado).
Schedule::command('meta:collect-metrics-simple --limit=150 --only-missing')
    ->dailyAt('04:15')
    ->timezone(config('app.timezone', 'America/Bogota'))
    ->withoutOverlapping();

// Higiene: limpiar trabajos fallidos de más de 7 días.
Schedule::command('queue:prune-failed --hours=168')->weekly();

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
Artisan::command('meta:debug-token
    {--page-id= : ID interno meta_pages.id}
    {--user-id= : Filtrar por user_id del pivot}
    {--post-id= : Probar un post del feed (pageid_postid)}
    {--video-id= : Probar un video_id directo}
    {--days=7   : Días para probar page_impressions}
', function () {
    $pageId = $this->option('page-id') ? (int) $this->option('page-id') : null;
    $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;
    $postId = $this->option('post-id');
    $videoId= $this->option('video-id');
    $days   = (int) ($this->option('days') ?? 7);

    if (!$pageId && !$userId) {
        $this->error('Pasa --page-id=XX o --user-id=YY');
        return 1;
    }

    $rows = DB::table('meta_page_user as mpu')
        ->join('meta_pages as mp', 'mp.id', '=', 'mpu.meta_page_id')
        ->select([
            'mpu.meta_page_id','mpu.user_id','mpu.page_access_token',
            'mp.page_id as fb_page_id','mp.name as page_name'
        ])
        ->where('mpu.is_active', 1)
        ->whereNotNull('mpu.page_access_token')
        ->when($pageId, fn($q)=>$q->where('mpu.meta_page_id',$pageId))
        ->when($userId, fn($q)=>$q->where('mpu.user_id',$userId))
        ->get();

    if ($rows->isEmpty()) {
        $this->error('No hay tokens activos para ese filtro.');
        return 1;
    }

    $appId     = env('FACEBOOK_CLIENT_ID');
    $appSecret = env('FACEBOOK_CLIENT_SECRET');
    $appToken  = $appId && $appSecret ? ($appId.'|'.$appSecret) : null;

    foreach ($rows as $r) {
        $tok = $r->page_access_token;
        $ctx = "meta_page_id={$r->meta_page_id} fb_page_id={$r->fb_page_id} user_id={$r->user_id}";
        $this->line(str_repeat('-', 70));
        $this->info("Página: {$r->page_name} ({$ctx})");

        // A) debug_token
        if ($appToken) {
            try {
                $dt = Http::timeout(15)->get('https://graph.facebook.com/debug_token', [
                    'input_token'  => $tok,
                    'access_token' => $appToken,
                ])->json();
                $data   = data_get($dt,'data',[]);
                $valid  = data_get($data,'is_valid') ? 'yes':'no';
                $type   = data_get($data,'type','unknown');
                $scope1 = implode(',', (array) data_get($data,'scopes',[]));
                $profileId = data_get($data,'profile_id'); // debe ser el page_id de FB
                $this->line("debug_token: valid={$valid} type={$type} profile_id={$profileId}");
                if ($scope1) $this->line("debug_token.scopes: {$scope1}");
            } catch (\Throwable $e) {
                $this->warn("debug_token error: ".$e->getMessage());
            }
        } else {
            $this->warn('Omitiendo debug_token (falta FACEBOOK_CLIENT_ID/SECRET).');
        }

        // B) tasks de la página
        try {
            $rTasks = Http::timeout(20)->get("https://graph.facebook.com/v23.0/{$r->fb_page_id}", [
                'fields' => 'id,name,tasks',
                'access_token' => $tok,
            ]);
            if ($rTasks->ok()) {
                $tasks = implode(',', (array) data_get($rTasks->json(),'tasks',[]));
                $this->info("page.tasks: {$tasks}");
            } else {
                $this->error("page.tasks FAIL {$rTasks->status()} ".substr($rTasks->body(),0,200));
            }
        } catch (\Throwable $e) {
            $this->error("page.tasks ERROR ".$e->getMessage());
        }

        // C) pages_read_engagement (leer posts)
        try {
            $r1 = Http::timeout(25)->connectTimeout(10)->retry(2,800)
                ->withOptions(['curl'=>[CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/v23.0/{$r->fb_page_id}/posts", [
                    'limit'=>1,'access_token'=>$tok,
                ]);
            if ($r1->ok()) $this->info("pages_read_engagement: OK (/page/posts)");
            else $this->error("pages_read_engagement: FAIL {$r1->status()} ".substr($r1->body(),0,200));
        } catch (\Throwable $e) {
            $this->error("pages_read_engagement: ERROR ".$e->getMessage());
        }

        // D) read_insights (page)
        try {
            $sinceTs = now()->subDays($days)->timestamp;
            $r2 = Http::timeout(25)->connectTimeout(10)->retry(2,800)
                ->withOptions(['curl'=>[CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/v23.0/{$r->fb_page_id}/insights", [
                    'metric'=>'page_impressions','period'=>'day','since'=>$sinceTs,'access_token'=>$tok,
                ]);
            if ($r2->ok()) $this->info("read_insights(page): OK (page_impressions)");
            else $this->error("read_insights(page): FAIL {$r2->status()} ".substr($r2->body(),0,200));
        } catch (\Throwable $e) {
            $this->error("read_insights(page): ERROR ".$e->getMessage());
        }

        // E) Probar un post-id concreto (engagement sin insights)
        if ($postId) {
            try {
                $rp = Http::timeout(25)->connectTimeout(10)->retry(2,800)
                    ->withOptions(['curl'=>[CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]])
                    ->get("https://graph.facebook.com/v23.0/{$postId}", [
                        'fields'=>'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares',
                        'access_token'=>$tok,
                    ]);
                if ($rp->ok()) $this->info("post.fields: OK (reactions/comments/shares)");
                else $this->error("post.fields: FAIL {$rp->status()} ".substr($rp->body(),0,240));
            } catch (\Throwable $e) {
                $this->error("post.fields: ERROR ".$e->getMessage());
            }
        }

        // F) Probar un video-id (video_insights)
        if ($videoId) {
            try {
                $rv = Http::timeout(25)->connectTimeout(10)->retry(2,800)
                    ->withOptions(['curl'=>[CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]])
                    ->get("https://graph-video.facebook.com/v23.0/{$videoId}/video_insights", [
                        'metric'=>'total_video_impressions,total_video_views',
                        'access_token'=>$tok,
                    ]);
                if ($rv->ok()) $this->info("video_insights: OK (views/impressions)");
                else $this->error("video_insights: FAIL {$rv->status()} ".substr($rv->body(),0,240));
            } catch (\Throwable $e) {
                $this->error("video_insights: ERROR ".$e->getMessage());
            }
        }
    }

    $this->line(str_repeat('-', 70));
    return 0;
})->purpose('Audita tokens, tasks, scopes y prueba post/video endpoints');
