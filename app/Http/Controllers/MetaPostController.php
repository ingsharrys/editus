<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MetaPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Jobs\PublishPhotosToFacebook;
use Illuminate\Support\Facades\Log;


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

        // photo_urls: de local_media o (fallback) de photo_urls
        $urls = [];
        $lm = json_decode($post->local_media ?? '[]', true) ?: [];
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

        // Encolar: SIN page_token (el Job lo resolverá desde meta_page_user)
        PublishPhotosToFacebook::dispatch([
            'meta_post_id' => $post->id,
            'page_id' => $pageId,
            'photo_urls' => $urls,
            'caption' => $post->message,
            'cleanup_rel' => (array) ($lm['cleanup_rel'] ?? []),
            'cleanup_abs' => (array) ($lm['cleanup_abs'] ?? []),
        ])->onQueue('default');

        return back()->with('ok', 'Reintento encolado.');
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
            // 1) page_id robusto: relación -> columna -> parse de fb_post_id
            $pageId = $post->page->page_id ?? $post->page_id ?? null;
            if (!$pageId && $post->fb_post_id) {
                $pageId = explode('_', $post->fb_post_id)[0] ?? null;
            }
            if (!$pageId) {
                Log::warning('[retryFails] sin page_id', ['meta_post_id' => $post->id]);
                continue;
            }

            // 2) photo_urls: primero local_media, luego fallback a photo_urls
            $lm = json_decode($post->local_media ?? '[]', true) ?: [];
            $photoUrls = array_values(array_filter((array) ($lm['photo_urls'] ?? [])));

            if (empty($photoUrls) && !empty($post->photo_urls)) {
                $pj = json_decode($post->photo_urls, true) ?: [];
                $photoUrls = array_values(array_filter((array) ($pj['photo_urls'] ?? $pj)));
            }

            if (empty($photoUrls)) {
                Log::info('[retryFails] sin photo_urls', ['meta_post_id' => $post->id]);
                continue;
            }

            // 3) (Opcional) HEAD NO bloqueante
            try {
                $head = Http::timeout(10)->head($photoUrls[0]);
                if (!$head->ok() || stripos($head->header('Content-Type') ?? '', 'image/') !== 0) {
                    Log::info('[retryFails] HEAD no-OK o no image/* (se continúa igual)', [
                        'url' => $photoUrls[0],
                        'status' => $head->status(),
                        'ct' => $head->header('Content-Type')
                    ]);
                }
            } catch (\Throwable $e) {
                Log::info('[retryFails] HEAD exception (se continúa igual)', [
                    'url' => $photoUrls[0],
                    'err' => $e->getMessage()
                ]);
            }

            // 4) marcar en cola y encolar SIN token (el Job lo resuelve desde meta_page_user)
            $post->update(['status' => 'queued', 'error' => null]);

            \App\Jobs\PublishPhotosToFacebook::dispatch([
                'meta_post_id' => $post->id,
                'page_id' => $pageId,
                'photo_urls' => $photoUrls,
                'caption' => $post->message,
                'cleanup_rel' => (array) ($lm['cleanup_rel'] ?? []),
                'cleanup_abs' => (array) ($lm['cleanup_abs'] ?? []),
            ])->onQueue('default')->delay(now()->addSeconds($countQueued * 3));

            $countQueued++;
        }

        return back()->with('ok', "Se encolaron $countQueued reintentos.");
    }

}
