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
        // Autorización básica: admin o dueño del post
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;
        if (!$isAdmin && $post->user_id !== $request->user()->id) {
            abort(403);
        }

        // Solo reintentar si falló y no tiene ya post publicado
        if ($post->status !== 'fail' || $post->fb_post_id) {
            return back()->with('warn', 'Este item no está en estado fallido o ya tiene publicación.');
        }

        // Datos base
        $page = $post->page; // relación que ya cargas en show()
        $pageId = $page?->page_id;
        $pageName = $page?->name;
        // Ajusta según tu esquema: de aquí debe salir el Page Access Token vigente
        $pageToken = $page->access_token ?? $post->page_token ?? null;

        if (!$pageId || !$pageToken) {
            return back()->with('error', 'Falta page_id o page_token para reintentar.');
        }

        // Foto(s) y caption
        $photoUrls = (array) data_get(json_decode($post->photo_urls ?? '[]', true), 'photo_urls', []);
        $caption = $post->message;

        if (empty($photoUrls)) {
            return back()->with('error', 'No hay photo_urls guardadas para reintentar.');
        }

        // HEAD rápido: evita reintentar imágenes 404/0 bytes
        foreach ($photoUrls as $u) {
            try {
                $res = Http::timeout(10)->head($u);
                if (!$res->ok()) {
                    return back()->with('error', "La imagen no es accesible (HEAD {$res->status()}): $u");
                }
                $ct = strtolower($res->header('Content-Type') ?? '');
                if (strpos($ct, 'image/') !== 0) {
                    return back()->with('error', "Content-Type inválido para Facebook ($ct): $u");
                }
            } catch (\Throwable $e) {
                return back()->with('error', "No se pudo verificar la imagen: $u");
            }
        }

        // Marcar en "queued" y limpiar error para que en UI se note el reintento
        $post->update([
            'status' => 'queued',
            'error' => null,
        ]);

        // Encolar exactamente el mismo Job que ya usas
        PublishPhotosToFacebook::dispatch([
            'meta_post_id' => $post->id,
            'page_id' => $pageId,
            'page_name' => $pageName,
            'page_token' => $pageToken,
            'photo_urls' => $photoUrls,
            'cleanup_rel' => [], // opcional
            'cleanup_abs' => [], // opcional
            'caption' => $caption,
        ])->onQueue('default');

        return back()->with('ok', 'Reintento encolado.');
    }

    public function retryFails(Request $request, string $batch)
    {
        $isAdmin = (int) ($request->user()->role_id ?? 0) === 1;

        $posts = MetaPost::with('page:id,name,page_id')
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
            $page = $post->page;
            $pageId = $page?->page_id;
            $pageName = $page?->name;
            $pageToken = $page->access_token ?? $post->page_token ?? null;

            $photoUrls = (array) data_get(json_decode($post->photo_urls ?? '[]', true), 'photo_urls', []);
            if (!$pageId || !$pageToken || empty($photoUrls)) {
                continue; // saltar inválidos
            }

            // Chequeo opcional (no bloqueante)
            try {
                $head = Http::timeout(10)->head($photoUrls[0]);
                if (!$head->ok() || stripos($head->header('Content-Type') ?? '', 'image/') !== 0) {
                    Log::info('[retryFails] HEAD no-OK o no image/*, se sigue igual', [
                        'url' => $photoUrls[0],
                        'status' => $head->status(),
                        'ct' => $head->header('Content-Type')
                    ]);
                }
            } catch (\Throwable $e) {
                Log::info('[retryFails] HEAD exception, se sigue igual', [
                    'url' => $photoUrls[0],
                    'err' => $e->getMessage()
                ]);
            }



            $post->update(['status' => 'queued', 'error' => null]);

            // Escalonar con pequeños delays para no saturar
            PublishPhotosToFacebook::dispatch([
                'meta_post_id' => $post->id,
                'page_id' => $pageId,
                'page_name' => $pageName,
                'page_token' => $pageToken,
                'photo_urls' => $photoUrls,
                'cleanup_rel' => [],
                'cleanup_abs' => [],
                'caption' => $post->message,
            ])->onQueue('default')->delay(now()->addSeconds($countQueued * 3));

            $countQueued++;
        }

        return back()->with('ok', "Se encolaron $countQueued reintentos.");
    }
}
