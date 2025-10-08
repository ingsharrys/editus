<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MetaPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Jobs\PublishPhotosToFacebook;
use App\Jobs\PublishVideoToFacebook;
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
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;
        if (!$isAdmin && $post->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($post->status !== 'fail' || $post->fb_post_id) {
            return back()->with('warn', 'Este item no está en estado fallido o ya tiene publicación.');
        }

        $post->loadMissing('page');
        $pageId = $post->page->page_id ?? $post->page_id ?? null;
        if (!$pageId && $post->fb_post_id) {
            $pageId = explode('_', $post->fb_post_id)[0] ?? null;
        }
        if (!$pageId) {
            return back()->with('error', 'No se pudo determinar la página de destino.');
        }

        // Local media
        $lm = json_decode($post->local_media ?? '[]', true) ?: [];

        // === NUEVO: si es VIDEO, despachar video job ===
        if (($post->type ?? 'photo') === 'video') {
            $videoUrl = $lm['video_url'] ?? ($post->video_url ?? null);
            if (empty($videoUrl)) {
                return back()->with('error', 'No hay video_url guardada para reintentar.');
            }

            $post->update(['status' => 'queued', 'error' => null]);

            PublishVideoToFacebook::dispatch([
                'meta_post_id' => $post->id,
                'page_id' => $pageId,
                'video_url' => $videoUrl,
                'caption' => $post->message,
                'cleanup_rel' => (array) ($lm['cleanup_rel'] ?? []),
                'cleanup_abs' => (array) ($lm['cleanup_abs'] ?? []),
            ])->onQueue('default');

            return back()->with('ok', 'Reintento de video encolado.');
        }

        // === FOTO (lo que ya tenías) ===
        $urls = [];
        $urls = array_values(array_filter((array) ($lm['photo_urls'] ?? [])));
        if (empty($urls) && !empty($post->photo_urls)) {
            $pj = json_decode($post->photo_urls, true) ?: [];
            $urls = array_values(array_filter((array) ($pj['photo_urls'] ?? $pj)));
        }
        if (empty($urls)) {
            return back()->with('error', 'No hay photo_urls guardadas para reintentar.');
        }

        $post->update(['status' => 'queued', 'error' => null]);

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
        $skippedNoPage = 0;
        $skippedNoMedia = 0;

        foreach ($posts as $post) {
            $pageId = $post->page->page_id ?? $post->page_id ?? null;
            if (!$pageId && $post->fb_post_id) {
                $pageId = explode('_', $post->fb_post_id)[0] ?? null;
            }
            if (!$pageId) {
                Log::warning('[retryFails] sin page_id', ['meta_post_id' => $post->id]);
                $skippedNoPage++;
                continue;
            }

            $lm = json_decode($post->local_media ?? '[]', true) ?: [];

            if (($post->type ?? 'photo') === 'video') {
                $videoUrl = $lm['video_url'] ?? ($post->video_url ?? null);
                if (empty($videoUrl)) {
                    Log::info('[retryFails] sin video_url', ['meta_post_id' => $post->id]);
                    $skippedNoMedia++;
                    continue;
                }

                $post->update(['status' => 'queued', 'error' => null]);

                \App\Jobs\PublishVideoToFacebook::dispatch([
                    'meta_post_id' => $post->id,
                    'page_id' => $pageId,
                    'video_url' => $videoUrl,
                    'caption' => $post->message,
                    'cleanup_rel' => (array) ($lm['cleanup_rel'] ?? []),
                    'cleanup_abs' => (array) ($lm['cleanup_abs'] ?? []),
                ])->onQueue('default')->delay(now()->addSeconds($countQueued * 3));

                $countQueued++;
                continue;
            }

            // FOTO
            $photoUrls = array_values(array_filter((array) ($lm['photo_urls'] ?? [])));
            if (empty($photoUrls) && !empty($post->photo_urls)) {
                $pj = json_decode($post->photo_urls, true) ?: [];
                $photoUrls = array_values(array_filter((array) ($pj['photo_urls'] ?? $pj)));
            }
            if (empty($photoUrls)) {
                Log::info('[retryFails] sin photo_urls', ['meta_post_id' => $post->id]);
                $skippedNoMedia++;
                continue;
            }

            // (HEAD opcional, igual que ya tenías)
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

        $msg = "Se encolaron $countQueued reintentos.";
        if ($skippedNoPage)
            $msg .= " Omitidos sin página: $skippedNoPage.";
        if ($skippedNoMedia)
            $msg .= " Omitidos sin media: $skippedNoMedia.";

        return back()->with('ok', $msg);
    }


}
