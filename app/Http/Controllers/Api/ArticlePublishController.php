<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\User;
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
 * activo. Se crea un MetaPost por página (mismo flujo y métricas que
 * las publicaciones hechas desde la interfaz).
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

        // ── Usuario al que se atribuyen los posts creados por API ───
        $apiUserId = (int) config('services.editus.api_user_id', 0);
        if ($apiUserId <= 0 || !User::whereKey($apiUserId)->exists()) {
            $apiUserId = (int) (User::orderBy('id')->value('id') ?? 0);
        }
        if ($apiUserId <= 0) {
            return response()->json(['success' => false, 'error' => 'No hay usuario para atribuir el post'], 500);
        }

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

        foreach ($pages as $page) {
            $pivot = $page->users->first()?->pivot;
            if (!$pivot?->page_access_token) {
                $resultados[] = ['pagina' => $page->name, 'ok' => false, 'error' => 'Sin token activo'];
                continue;
            }

            $token = $pivot->page_access_token;

            $postData = [
                'batch_uuid' => $batch,
                'user_id' => $apiUserId,
                'meta_page_id' => $page->id,
                'type' => 'text',
                'message' => $mensaje,
                'link' => $data['url'],
                'local_media' => null,
                'fb_media_ids' => null,
                'status' => 'pending',
                'published_at' => null,
                'fb_post_id' => null,
                'fb_permalink_url' => null,
                'error' => null,
            ];

            try {
                $resp = Http::asForm()->post("https://graph.facebook.com/{$graphVersion}/{$page->page_id}/feed", [
                    'message' => $mensaje,
                    'link' => $data['url'],
                    'access_token' => $token,
                ]);

                if ($resp->ok()) {
                    $postId = data_get($resp->json(), 'id');
                    $postData['status'] = 'success';
                    $postData['fb_post_id'] = $postId;
                    $postData['published_at'] = now();
                    $postData['fb_permalink_url'] = $this->fetchPermalink($postId, $token);
                    $publicados++;
                    $resultados[] = ['pagina' => $page->name, 'ok' => true, 'fb_post_id' => $postId];
                } else {
                    $postData['status'] = 'fail';
                    $postData['error'] = $resp->body();
                    $resultados[] = ['pagina' => $page->name, 'ok' => false, 'error' => $resp->body()];
                }
            } catch (\Throwable $e) {
                $postData['status'] = 'fail';
                $postData['error'] = $e->getMessage();
                $resultados[] = ['pagina' => $page->name, 'ok' => false, 'error' => $e->getMessage()];
            }

            MetaPost::create($postData);
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
