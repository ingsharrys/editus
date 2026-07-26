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
 * Publica en Facebook (enlace con vista previa) y en el Instagram
 * Business conectado a cada página (imagen + caption). Devuelve
 * éxito/falla por página y red para que esnoticia lo registre, y
 * además registra cada post bajo la campaña de sistema "Esnoticia"
 * para los informes de editus.
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

        // Todos los artículos automáticos quedan bajo la campaña de sistema
        // "Esnoticia", para poder medirlos por separado en los informes.
        $campaign = \App\Models\Campaign::esnoticia();
        $systemUserId = \App\Models\User::whereHas('role', fn($q) => $q->where('slug', 'admin'))->orderBy('id')->value('id')
            ?? \App\Models\User::orderBy('id')->value('id');

        $registrar = function (MetaPage $page, bool $ok, ?string $postId, ?string $permalink, ?string $error, string $red = 'facebook') use ($batch, $campaign, $systemUserId, $mensaje, $data) {
            try {
                \App\Models\MetaPost::create([
                    'batch_uuid' => $batch,
                    'user_id' => $systemUserId,
                    'campaign_id' => $campaign->id,
                    'meta_page_id' => $page->id,
                    'type' => $red === 'instagram' ? 'photo' : 'text',
                    'network' => $red,
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

            // Token guardado o, si no hay, uno fresco vía Usuario de Sistema
            $token = $pivot?->page_access_token ?: $this->tokenViaUsuarioSistema($page->page_id, $graphVersion);

            if (!$token) {
                $resultados[] = ['pagina' => $page->name, 'red' => 'facebook', 'ok' => false, 'error' => 'Sin token activo (ni Usuario de Sistema disponible)'];
                $registrar($page, false, null, null, 'Sin token activo');
                continue;
            }

            try {
                $publicarFeed = fn($tok) => Http::asForm()->timeout(20)->connectTimeout(8)
                    ->post("https://graph.facebook.com/{$graphVersion}/{$page->page_id}/feed", [
                    'message' => $mensaje,
                    'link' => $data['url'],
                    'access_token' => $tok,
                ]);

                $resp = $publicarFeed($token);

                // Token vencido o sin permisos (OAuth 190): pedir uno fresco
                // vía Usuario de Sistema de Business Manager y reintentar
                if (!$resp->ok() && str_contains($resp->body(), '"code":190')) {
                    $fresco = $this->tokenViaUsuarioSistema($page->page_id, $graphVersion);
                    if ($fresco && $fresco !== $token) {
                        Log::info('[ARTICULOS] Token 190: reintentando con token de Usuario de Sistema', ['pagina' => $page->name]);
                        $token = $fresco; // también lo usará Instagram más abajo
                        $resp = $publicarFeed($token);
                    }
                }

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

            // ── Instagram (si la página tiene cuenta Business conectada) ──
            $igId = $page->instagram_business_account_id;
            if ($igId) {
                if (!$imagenUrl) {
                    $resultados[] = ['pagina' => $page->name, 'red' => 'instagram', 'ok' => false,
                        'error' => 'Sin imagen: no llegó imagen_url y el artículo no tiene og:image'];
                    $registrar($page, false, null, null, 'Sin imagen para Instagram', 'instagram');
                } else {
                    $resIg = $this->publicarEnInstagram($igId, $imagenUrl, $mensaje, $data['url'], $token, $graphVersion);
                    $resIg['pagina'] = $page->name;
                    if (!empty($resIg['ok'])) {
                        $publicados++;
                    }
                    $resultados[] = $resIg;
                    $registrar($page, !empty($resIg['ok']), $resIg['fb_post_id'] ?? null,
                        $resIg['permalink'] ?? null, $resIg['error'] ?? null, 'instagram');
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
     * Pide a Facebook un token fresco de la página usando el token del
     * Usuario de Sistema de Business Manager (FACEBOOK_SYSTEM_USER_TOKEN).
     * Es el mismo mecanismo de respaldo que usan las métricas de editus.
     */
    private function tokenViaUsuarioSistema(string $pageId, string $graphVersion): ?string
    {
        $sys = config('services.facebook.system_user_token');
        if (!$sys) {
            return null;
        }

        try {
            $r = Http::timeout(15)->get("https://graph.facebook.com/{$graphVersion}/{$pageId}", [
                'fields' => 'access_token',
                'access_token' => $sys,
            ]);
            return $r->ok() ? data_get($r->json(), 'access_token') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Publicar una imagen con caption en Instagram Business.
     * Flujo oficial: crear contenedor (/media) y publicarlo
     * (/media_publish), con un reintento si el contenedor tarda.
     */
    private function publicarEnInstagram(string $igId, string $imagenUrl, string $mensaje, string $urlArticulo, string $token, string $graphVersion): array
    {
        try {
            // IG no permite links clicables en el caption; la URL va como texto
            $caption = mb_substr($mensaje . "\n\n🔗 " . $urlArticulo, 0, 2100);

            $r1 = Http::asForm()->timeout(30)->connectTimeout(8)
                ->post("https://graph.facebook.com/{$graphVersion}/{$igId}/media", [
                    'image_url' => $imagenUrl,
                    'caption' => $caption,
                    'access_token' => $token,
                ]);

            if (!$r1->ok()) {
                return ['red' => 'instagram', 'ok' => false, 'error' => mb_substr($r1->body(), 0, 5000)];
            }

            $creationId = data_get($r1->json(), 'id');

            $publicar = fn() => Http::asForm()->timeout(30)->connectTimeout(8)
                ->post("https://graph.facebook.com/{$graphVersion}/{$igId}/media_publish", [
                    'creation_id' => $creationId,
                    'access_token' => $token,
                ]);

            $r2 = $publicar();
            if (!$r2->ok()) {
                sleep(4); // el contenedor puede tardar unos segundos en procesarse
                $r2 = $publicar();
            }

            if (!$r2->ok()) {
                return ['red' => 'instagram', 'ok' => false, 'error' => mb_substr($r2->body(), 0, 5000)];
            }

            $mediaId = data_get($r2->json(), 'id');

            $permalink = null;
            try {
                $r3 = Http::timeout(10)->get("https://graph.facebook.com/{$graphVersion}/{$mediaId}", [
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
