<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MetaPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Jobs\PublishPhotosToFacebook;
use App\Jobs\PublishVideoToFacebook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Jobs\CollectBatchMetrics;
use Illuminate\Support\Str;


class MetaPostController extends Controller
{
    public function index(Request $request)
    {
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;

        $groups = MetaPost::query()
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $request->user()->id))
            ->whereNotNull('batch_uuid')
            ->select([
                'batch_uuid',
                'type',
                DB::raw('MIN(created_at) as first_at'),
                DB::raw('MIN(COALESCE(message,"")) as message'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as ok"),
                DB::raw("SUM(CASE WHEN status='fail' THEN 1 ELSE 0 END) as fails"),
            ])
            ->groupBy('batch_uuid', 'type')
            ->orderByDesc(DB::raw('MIN(created_at)'))
            ->paginate(20);

        return view('meta_posts.index', compact('groups', 'isAdmin'));
    }

    public function show(Request $request, string $batch)
    {
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;

        $posts = MetaPost::with(['page:id,name,page_id', 'user:id,name'])
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $request->user()->id))
            ->where('batch_uuid', $batch)
            ->orderByDesc('created_at')
            ->get();

        abort_if($posts->isEmpty(), 404);

        $head = $posts->first();
        $summary = [
            'batch' => $batch,
            'type' => $head->type,
            'message' => $head->message,
            'user' => $head->user,
            'first_at' => $posts->min('created_at'),
            'total' => $posts->count(),
            'ok' => $posts->where('status', 'success')->count(),
            'fails' => $posts->where('status', 'fail')->count(),
        ];

        return view('meta_posts.show', compact('summary', 'posts', 'isAdmin'));
    }
    public function retry(Request $request, MetaPost $post)
    {
        // Admin o dueño
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;
        if (!$isAdmin && $post->user_id !== $request->user()->id) {
            abort(403);
        }

        // Solo si falló y no tiene fb_post_id
        if ($post->status !== 'fail' || $post->fb_post_id) {
            return back()->with('warn', 'Este item no está en estado fallido o ya tiene publicación.');
        }

        // Cargar relación para sacar el page_id
        $post->loadMissing('page');

        // page_id: relación -> columna -> fallback a fb_post_id
        $pageId = $post->page->page_id ?? $post->page_id ?? null;
        if (!$pageId && $post->fb_post_id) {
            $pageId = explode('_', $post->fb_post_id)[0] ?? null;
        }
        if (!$pageId) {
            return back()->with('error', 'No se pudo determinar la página de destino.');
        }

        $type = $post->type; // 'photo' | 'video' | 'text'
        $lm = json_decode($post->local_media ?? '[]', true) ?: [];

        if ($type === 'photo') {
            // photo_urls: de local_media o (fallback) de photo_urls
            $urls = array_values(array_filter((array) ($lm['photo_urls'] ?? [])));
            if (empty($urls) && !empty($post->photo_urls)) {
                $pj = json_decode($post->photo_urls, true) ?: [];
                $urls = array_values(array_filter((array) ($pj['photo_urls'] ?? $pj)));
            }
            if (empty($urls)) {
                return back()->with('error', 'No hay photo_urls guardadas para reintentar.');
            }

            // Marcar en cola y limpiar error
            $post->update(['status' => 'queued', 'error' => null]);

            // Encolar (el Job resolverá token desde meta_page_user)
            PublishPhotosToFacebook::dispatch([
                'meta_post_id' => $post->id,
                'page_id' => $pageId,
                'photo_urls' => $urls,
                'caption' => $post->message,
                'cleanup_rel' => (array) ($lm['cleanup_rel'] ?? []),
                'cleanup_abs' => (array) ($lm['cleanup_abs'] ?? []),
            ])->onQueue('default');

            return back()->with('ok', 'Reintento de fotos encolado.');
        }

        if ($type === 'video') {
            // video: public_url y limpiezas simples (string)
            $publicUrl = $lm['public_url'] ?? null;
            $cleanupRel = $lm['cleanup_rel'] ?? null;
            $cleanupAbs = $lm['cleanup_abs'] ?? null;

            if (!$publicUrl) {
                return back()->with('error', 'No hay public_url guardada para reintentar el video.');
            }

            // Preflight opcional (si ya se limpió el tmp, evitar reintentos inútiles)
            try {
                $head = Http::timeout(10)->withOptions(['allow_redirects' => true])->send('HEAD', $publicUrl);
                if (!$head->successful()) {
                    return back()->with('error', 'El video temporal ya no es accesible (re-subir archivo).');
                }
            } catch (\Throwable $e) {
                return back()->with('error', 'No se pudo acceder al video temporal (re-subir archivo).');
            }

            $post->update(['status' => 'queued', 'error' => null]);

            PublishVideoToFacebook::dispatch([
                'meta_post_id' => $post->id,
                'page_id' => $pageId,
                'public_url' => $publicUrl,
                'caption' => $post->message,
                'cleanup_rel' => $cleanupRel,
                'cleanup_abs' => $cleanupAbs,
            ])->onQueue('default');

            return back()->with('ok', 'Reintento de video encolado.');
        }

        // (Opcional) soporte para texto si lo deseas
        return back()->with('warn', 'Este tipo de publicación no admite reintento automático.');
    }


    public function retryFails(Request $request, string $batch)
    {
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;

        $posts = MetaPost::with('page:id,name,page_id', 'user:id')
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $request->user()->id))
            ->where('batch_uuid', $batch)
            ->where('status', 'fail')
            ->whereNull('fb_post_id')
            ->orderBy('id')
            ->get();

        if ($posts->isEmpty()) {
            return back()->with('warn', 'No hay fallidos para reintentar en este lote.');
        }

        $countQueued = 0;

        foreach ($posts as $post) {
            // page_id robusto
            $pageId = $post->page->page_id ?? $post->page_id ?? null;
            if (!$pageId && $post->fb_post_id) {
                $pageId = explode('_', $post->fb_post_id)[0] ?? null;
            }
            if (!$pageId) {
                Log::warning('[retryFails] sin page_id', ['meta_post_id' => $post->id]);
                continue;
            }

            $type = $post->type;
            $lm = json_decode($post->local_media ?? '[]', true) ?: [];

            if ($type === 'photo') {
                $photoUrls = array_values(array_filter((array) ($lm['photo_urls'] ?? [])));
                if (empty($photoUrls) && !empty($post->photo_urls)) {
                    $pj = json_decode($post->photo_urls, true) ?: [];
                    $photoUrls = array_values(array_filter((array) ($pj['photo_urls'] ?? $pj)));
                }
                if (empty($photoUrls)) {
                    Log::info('[retryFails] sin photo_urls', ['meta_post_id' => $post->id]);
                    continue;
                }

                // HEAD NO bloqueante
                try {
                    $head = Http::timeout(10)->withOptions(['allow_redirects' => true])->send('HEAD', $photoUrls[0]);
                    if (!$head->ok() || stripos($head->header('Content-Type') ?? '', 'image/') !== 0) {
                        Log::info('[retryFails] HEAD no-OK o no image/* (continuando)', [
                            'url' => $photoUrls[0],
                            'status' => $head->status(),
                            'ct' => $head->header('Content-Type')
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::info('[retryFails] HEAD exception (continuando)', [
                        'url' => $photoUrls[0],
                        'err' => $e->getMessage()
                    ]);
                }

                $post->update(['status' => 'queued', 'error' => null]);

                PublishPhotosToFacebook::dispatch([
                    'meta_post_id' => $post->id,
                    'page_id' => $pageId,
                    'photo_urls' => $photoUrls,
                    'caption' => $post->message,
                    'cleanup_rel' => (array) ($lm['cleanup_rel'] ?? []),
                    'cleanup_abs' => (array) ($lm['cleanup_abs'] ?? []),
                ])->onQueue('default')->delay(now()->addSeconds($countQueued * 3));

                $countQueued++;
                continue;
            }

            if ($type === 'video') {
                $publicUrl = $lm['public_url'] ?? null;
                $cleanupRel = $lm['cleanup_rel'] ?? null;
                $cleanupAbs = $lm['cleanup_abs'] ?? null;

                if (!$publicUrl) {
                    Log::info('[retryFails] sin public_url de video', ['meta_post_id' => $post->id]);
                    continue;
                }

                // HEAD rápido; si no es accesible, mejor no encolar
                try {
                    $head = Http::timeout(10)->withOptions(['allow_redirects' => true])->send('HEAD', $publicUrl);
                    if (!$head->successful()) {
                        Log::info('[retryFails] video no accesible, se omite', [
                            'meta_post_id' => $post->id,
                            'status' => $head->status(),
                        ]);
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::info('[retryFails] HEAD exception en video, se omite', [
                        'meta_post_id' => $post->id,
                        'err' => $e->getMessage()
                    ]);
                    continue;
                }

                $post->update(['status' => 'queued', 'error' => null]);

                PublishVideoToFacebook::dispatch([
                    'meta_post_id' => $post->id,
                    'page_id' => $pageId,
                    'public_url' => $publicUrl,
                    'caption' => $post->message,
                    'cleanup_rel' => $cleanupRel,
                    'cleanup_abs' => $cleanupAbs,
                ])->onQueue('default')->delay(now()->addSeconds($countQueued * 3));

                $countQueued++;
                continue;
            }

            Log::info('[retryFails] tipo no soportado para reintento', [
                'meta_post_id' => $post->id,
                'type' => $type
            ]);
        }

        return back()->with('ok', "Se encolaron $countQueued reintentos.");
    }
    public function startMetrics(Request $request, string $batch)
    {
        $user = $request->user();
        $isAdmin = (int) ($user->role_id ?? 0) === 1;

        // Verifica que el batch sea accesible por el usuario
        $exists = MetaPost::query()
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $user->id))
            ->where('batch_uuid', $batch)
            ->exists();

        if (!$exists) {
            return response()->json(['ok' => false, 'error' => 'Batch no encontrado o sin permisos.'], 404);
        }

        $key = "metrics:batch:{$batch}:progress";

        // Si ya hay un progreso activo y no está terminado, no lances otro
        $progress = Cache::get($key);
        if ($progress && !($progress['finished'] ?? false)) {
            return response()->json(['ok' => true, 'already_running' => true, 'progress' => $progress]);
        }

        // Inicializa progreso minimal mientras arranca el job
        Cache::put($key, [
            'total' => 0,
            'done' => 0,
            'ok' => 0,
            'empty' => 0,
            'errors' => 0,
            'started_at' => now()->toIso8601String(),
            'finished' => false,
            'finished_at' => null,
        ], now()->addHours(2));

        // Despacha el job
        CollectBatchMetrics::dispatch(
            batch: $batch,
            onlyUserId: $isAdmin ? null : $user->id,
            onlyForUser: !$isAdmin
        )->onQueue('default');

        return response()->json(['ok' => true]);
    }

    public function metricsProgress(Request $request, string $batch)
    {
        $user = $request->user();
        $isAdmin = (int) ($user->role_id ?? 0) === 1;

        $exists = MetaPost::query()
            ->when(!$isAdmin, fn($q) => $q->where('user_id', $user->id))
            ->where('batch_uuid', $batch)
            ->exists();

        if (!$exists) {
            return response()->json(['ok' => false, 'error' => 'Batch no encontrado o sin permisos.'], 404);
        }

        $progress = Cache::get("metrics:batch:{$batch}:progress");
        if (!$progress) {
            return response()->json(['ok' => false, 'error' => 'Sin progreso disponible.'], 404);
        }

        return response()->json(['ok' => true, 'progress' => $progress]);
    }



}
