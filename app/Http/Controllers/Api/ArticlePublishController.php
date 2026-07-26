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
 * Body:    { titulo, url, medio, extracto?, imagen_url? }
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
            'imagen_url' => ['nullable', 'url', 'max:1000'],
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

        // Imagen para Instagram: la del payload o el og:image del artículo
        $imagenUrl = $data['imagen_url'] ?? $this->obtenerImagenDesdeUrl($data['url']);

        $graphVersion = config('services.facebook.version', 'v23.0');
        $batch = (string) Str::uuid();
        $resultados = [];
        $publicados = 0;

        foreach ($pages as $page) {
            $pivot = $page->users->first()?->pivot;
            if (!$pivot?->page_access_token) {
                $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => 'Sin token activo'];
                continue;
            }

            $token = $pivot->page_access_token;

            try {
                $resp = Http::asForm()->post("https://graph.facebook.com/{$graphVersion}/{$page->page_id}/feed", [
                    'message' => $mensaje,
                    'link' => $data['url'],
                    'access_token' => $token,
                ]);

                if ($resp->ok()) {
                    $postId = data_get($resp->json(), 'id');
                    $publicados++;
                    $resultados[] = [
                        'pagina' => $page->name,
                        'red' => 'facebook',
                        'ok' => true,
                        'fb_post_id' => $postId,
                        'permalink' => $this->fetchPermalink($postId, $token),
                    ];
                } else {
                    $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => $resp->body()];
                }
            } catch (\Throwable $e) {
                $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => $e->getMessage()];
            }

            // ── Instagram (si la página tiene cuenta Business conectada) ──
            $igId = $page->instagram_business_account_id;
            if ($igId) {
                if (!$imagenUrl) {
                    $resultados[] = ['pagina' => $page->name, 'red' => 'instagram', 'ok' => false,
                        'error' => 'Sin imagen: no llegó imagen_url y el artículo no tiene og:image'];
                } else {
                    $resIg = $this->publicarEnInstagram($igId, $imagenUrl, $mensaje, $data['url'], $token, $graphVersion);
                    $resIg['pagina'] = $page->name;
                    if (!empty($resIg['ok'])) {
                        $publicados++;
                    }
                    $resultados[] = $resIg;
                }
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
     * Publicar una imagen con caption en Instagram Business.
     * Flujo oficial: crear contenedor (/media) y publicarlo
     * (/media_publish). Si el contenedor aún no está listo, se
     * reintenta una vez tras una breve espera.
     */
    private function publicarEnInstagram(string $igId, string $imagenUrl, string $mensaje, string $urlArticulo, string $token, string $graphVersion): array
    {
        try {
            // IG no permite links clicables en el caption; se incluye la URL como texto
            $caption = mb_substr($mensaje . "\n\n🔗 " . $urlArticulo, 0, 2100);

            $r1 = Http::asForm()->post("https://graph.facebook.com/{$graphVersion}/{$igId}/media", [
                'image_url' => $imagenUrl,
                'caption' => $caption,
                'access_token' => $token,
            ]);

            if (!$r1->ok()) {
                return ['red' => 'instagram', 'ok' => false, 'error' => $r1->body()];
            }

            $creationId = data_get($r1->json(), 'id');

            $r2 = Http::asForm()->post("https://graph.facebook.com/{$graphVersion}/{$igId}/media_publish", [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]);

            // El contenedor puede tardar unos segundos en procesarse
            if (!$r2->ok()) {
                sleep(4);
                $r2 = Http::asForm()->post("https://graph.facebook.com/{$graphVersion}/{$igId}/media_publish", [
                    'creation_id' => $creationId,
                    'access_token' => $token,
                ]);
            }

            if (!$r2->ok()) {
                return ['red' => 'instagram', 'ok' => false, 'error' => $r2->body()];
            }

            $mediaId = data_get($r2->json(), 'id');

            // Permalink del post de Instagram
            $permalink = null;
            try {
                $r3 = Http::get("https://graph.facebook.com/{$graphVersion}/{$mediaId}", [
                    'fields' => 'permalink',
                    'access_token' => $token,
                ]);
                $permalink = $r3->ok() ? data_get($r3->json(), 'permalink') : null;
            } catch (\Throwable) {
            }

            return ['red' => 'instagram', 'ok' => true, 'fb_post_id' => $mediaId, 'permalink' => $permalink];
        } catch (\Throwable $e) {
            return ['red' => 'instagram', 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lee el og:image de la página del artículo (WordPress y el sitio
     * Laravel lo publican para las vistas previas de redes sociales).
     */
    private function obtenerImagenDesdeUrl(string $url): ?string
    {
        try {
            $html = Http::timeout(10)->get($url)->body();

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                return html_entity_decode($m[1]);
            }
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
                return html_entity_decode($m[1]);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function fetchPermalink(?string $objectId, string $token): ?string
    {
        if (!$objectId) {
            return null;
        }

        try {
            $resp = Http::get("https://graph.facebook.com/v20.0/{$objectId}", [
                'fields' => 'permalink_url',
                'access_token' => $token,
            ]);
            return $resp->ok() ? data_get($resp->json(), 'permalink_url') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
