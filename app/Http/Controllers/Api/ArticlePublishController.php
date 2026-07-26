<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Publicación automática de artículos del sistema de noticias
 * (backend.esnoticia.org) en las páginas de Facebook del medio.
 *
 * POST /api/articulos/publicar
 * Header:  X-Editus-Token: <EDITUS_INGEST_TOKEN>
 * Body:    { titulo, url, medio, extracto? }
 *
 * El medio llega como slug (ej. "opanoticias") y se publica en todas
 * las páginas de meta_pages cuyo medio_slug coincida y tengan un token
 * activo.
 *
 * editus actúa SOLO como puente: publica y devuelve éxito/falla por
 * página. NO crea registros MetaPost — el historial de estas
 * publicaciones se registra en el sistema de noticias (esnoticia).
 * Las vistas de posts de editus quedan solo para lo publicado
 * manualmente desde su interfaz.
 */
class ArticlePublishController extends Controller
{
    public function store(Request $request)
    {
        // ── Autenticación por token compartido ──────────────────────
        $tokenEsperado = (string) config('services.editus.ingest_token', '');
        $tokenRecibido = (string) $request->header('X-Editus-Token', '');

        if ($tokenEsperado === '' || !hash_equals($tokenEsperado, $tokenRecibido)) {
            return response()->json(['success' => false, 'error' => 'No autorizado'], 401);
        }

        $data = $request->validate([
            'titulo'   => ['required', 'string', 'max:500'],
            'url'      => ['required', 'url', 'max:1000'],
            'medio'    => ['required', 'string', 'max:100'],
            'extracto' => ['nullable', 'string', 'max:2000'],
        ]);

        $medioSlug = Str::of($data['medio'])->lower()->trim()->toString();

        // ── Páginas del medio con token activo ──────────────────────
        $pages = MetaPage::where('medio_slug', $medioSlug)
            ->with(['users' => fn($q) => $q->wherePivot('is_active', true)])
            ->get();

        if ($pages->isEmpty()) {
            Log::info('[ARTICULOS] Medio sin páginas vinculadas', ['medio' => $medioSlug]);
            return response()->json([
                'success' => true,
                'publicados' => 0,
                'mensaje' => "El medio '$medioSlug' no tiene páginas de Facebook vinculadas (columna medio_slug en meta_pages)",
            ], 200);
        }

        // Mensaje del post: titular + extracto (el link genera la
        // vista previa con imagen automáticamente en Facebook)
        $mensaje = trim($data['titulo'] . (!empty($data['extracto']) ? "\n\n" . $data['extracto'] : ''));

        $graphVersion = config('services.facebook.version', 'v23.0');
        $batch = (string) Str::uuid();
        $resultados = [];
        $publicados = 0;

        // Todos los artículos automáticos quedan bajo la campaña de sistema
        // "Esnoticia", para poder medirlos por separado en los informes.
        $campaign = \App\Models\Campaign::esnoticia();
        $systemUserId = \App\Models\User::whereHas('role', fn($q) => $q->where('slug', 'admin'))->orderBy('id')->value('id')
            ?? \App\Models\User::orderBy('id')->value('id');

        $registrar = function (MetaPage $page, bool $ok, ?string $postId, ?string $permalink, ?string $error) use ($batch, $campaign, $systemUserId, $mensaje, $data) {
            try {
                \App\Models\MetaPost::create([
                    'batch_uuid' => $batch,
                    'user_id' => $systemUserId,
                    'campaign_id' => $campaign->id,
                    'meta_page_id' => $page->id,
                    'type' => 'text',
                    'network' => 'facebook',
                    'message' => $mensaje,
                    'link' => $data['url'],
                    'status' => $ok ? 'success' : 'fail',
                    'fb_post_id' => $postId,
                    'fb_permalink_url' => $permalink,
                    'published_at' => $ok ? now() : null,
                    'error' => $error,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[ARTICULOS] No se pudo registrar MetaPost', ['err' => $e->getMessage()]);
            }
        };

        foreach ($pages as $page) {
            $pivot = $page->users->first()?->pivot;
            if (!$pivot?->page_access_token) {
                $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => 'Sin token activo'];
                $registrar($page, false, null, null, 'Sin token activo');
                continue;
            }

            $token = $pivot->page_access_token;

            try {
                $resp = Http::asForm()->timeout(20)->connectTimeout(8)
                    ->post("https://graph.facebook.com/{$graphVersion}/{$page->page_id}/feed", [
                    'message' => $mensaje,
                    'link' => $data['url'],
                    'access_token' => $token,
                ]);

                if ($resp->ok()) {
                    $postId = data_get($resp->json(), 'id');
                    $permalink = $this->permalinkFromPostId($postId);
                    $publicados++;
                    $resultados[] = [
                        'pagina' => $page->name,
                        'red' => 'facebook',
                        'ok' => true,
                        'fb_post_id' => $postId,
                        'permalink' => $permalink,
                    ];
                    $registrar($page, true, $postId, $permalink, null);
                } else {
                    $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => $resp->body()];
                    $registrar($page, false, null, null, mb_substr($resp->body(), 0, 5000));
                }
            } catch (\Throwable $e) {
                $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => $e->getMessage()];
                $registrar($page, false, null, null, $e->getMessage());
            }
        }

        Log::info('[ARTICULOS] Artículo replicado en Facebook', [
            'medio' => $medioSlug,
            'batch' => $batch,
            'publicados' => $publicados,
            'total_paginas' => $pages->count(),
        ]);

        return response()->json([
            'success' => true,
            'batch' => $batch,
            'publicados' => $publicados,
            'resultados' => $resultados,
        ], 200);
    }

    /**
     * Construye el permalink sin llamada extra a la Graph API:
     * los IDs de feed tienen formato {page_id}_{post_id}.
     */
    private function permalinkFromPostId(?string $postId): ?string
    {
        if (!$postId) {
            return null;
        }

        if (str_contains($postId, '_')) {
            [$pageId, $id] = explode('_', $postId, 2);
            return "https://www.facebook.com/{$pageId}/posts/{$id}";
        }

        return "https://www.facebook.com/{$postId}";
    }
}
