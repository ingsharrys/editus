<?php

namespace App\Services;

use App\Models\MetaPost;
use App\Support\FacebookGraph;
use App\Support\FacebookGraph as FG;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Models\MetaPage;

class MetaInsightsService
{   /**
    * Obtiene el page access token para la página del post.
    * 1) Usa meta_page_user.page_access_token (si existe y no ha expirado).
    * 2) Si falta, usa social_accounts.access_token del usuario para llamar /me/accounts y guardar el token de página en la pivote.
    */
    protected array $pageTokenCache = [];
    protected function resolvePageToken(int $metaPageId): array
    {
        if (isset($this->pageTokenCache[$metaPageId])) {
            return $this->pageTokenCache[$metaPageId];
        }

        // 1) Page token del pivot (el más reciente)
        $row = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->orderByDesc('updated_at')
            ->first();

        if ($row && !empty($row->page_access_token)) {
            $info = [
                'token' => $row->page_access_token,
                'source' => 'page_pivot',
                'user_id' => $row->user_id,
            ];
            $this->pageTokenCache[$metaPageId] = $info;

            Log::info('[metrics][token] using page token', [
                'meta_page_id' => $metaPageId,
                'user_id' => $row->user_id,
                'len' => strlen($row->page_access_token),
                'source' => 'page_pivot',
            ]);

            return $info;
        }

        // 2) Si no hay page token, intenta social de alguien con esa página activa
        $socialId = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('social_account_id')
            ->orderByDesc('updated_at')
            ->value('social_account_id');

        if ($socialId) {
            $social = DB::table('social_accounts')->where('id', $socialId)->first();
            if ($social && !empty($social->access_token)) {
                Log::warning('[metrics][token] fallback social_any', [
                    'meta_page_id' => $metaPageId,
                    'social_account_id' => $socialId,
                ]);
                $info = ['token' => $social->access_token, 'source' => 'social_any', 'user_id' => null];
                $this->pageTokenCache[$metaPageId] = $info;
                return $info;
            }
        }

        throw new \RuntimeException("No hay token utilizable para meta_page_id={$metaPageId}");
    }
    protected function fbGet(string $url, array $params, string $token)
    {
        try {
            $resp = Http::withToken($token)
                ->timeout(40)
                ->retry(3, 1000)       // 3 intentos, 1s backoff
                ->acceptJson()
                ->get($url, $params);

            if (!$resp->ok()) {
                // Log detallado SIEMPRE con status y body
                Log::warning('[FB][GET] fail', [
                    'url' => $url,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return [null, $resp]; // devuelve el response para que el caller decida
            }
            return [$resp->json(), $resp];
        } catch (\Throwable $e) {
            Log::warning('[FB][GET].exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [null, null];
        }
    }

    /**
     * Devuelve social_accounts.access_token.
     * Prioriza el social_account_id de la pivote; si no, el más reciente de provider facebook/meta; si no, cualquiera.
     */
    private function getUserAccessToken(int $userId, ?int $socialAccountId): ?string
    {
        if ($socialAccountId) {
            $tok = DB::table('social_accounts')
                ->where('id', $socialAccountId)
                ->value('access_token');
            if ($tok)
                return $tok;
        }

        $tok = DB::table('social_accounts')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('provider', 'facebook')->orWhere('provider', 'meta');
            })
            ->orderByDesc('id')
            ->value('access_token');
        if ($tok)
            return $tok;

        return DB::table('social_accounts')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('access_token');
    }

    /**
     * Actualiza alcance / visualizaciones / interacciones para un post.
     * Aplica estrategia: post_insights → video_insights (mismo id) → resolve post desde video → object_id (video_id) desde post.
     */

    private function fetchEngagementCounts(string $postId, string $pageToken): ?int
    {
        try {
            // 1er intento: summary(true)
            $r = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/v23.0/{$postId}", [
                    'fields' => 'reactions.limit(0).summary(true),comments.limit(0).summary(true),shares',
                    'access_token' => $pageToken,
                ]);

            $j = $r->json();
            if ($r->ok() && is_array($j) && empty($j['error'])) {
                $reac = (int) (\Illuminate\Support\Arr::get($j, 'reactions.summary.total_count') ?? 0);
                $comms = (int) (\Illuminate\Support\Arr::get($j, 'comments.summary.total_count') ?? 0);
                $shares = (int) (\Illuminate\Support\Arr::get($j, 'shares.count') ?? 0);
                return $reac + $comms + $shares;
            }

            // 2do intento (algunas apps usan este formato): summary(total_count)
            $r2 = Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get("https://graph.facebook.com/v23.0/{$postId}", [
                    'fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares',
                    'access_token' => $pageToken,
                ]);

            $j2 = $r2->json();
            if ($r2->ok() && is_array($j2) && empty($j2['error'])) {
                $reac = (int) (\Illuminate\Support\Arr::get($j2, 'reactions.summary.total_count') ?? 0);
                $comms = (int) (\Illuminate\Support\Arr::get($j2, 'comments.summary.total_count') ?? 0);
                $shares = (int) (\Illuminate\Support\Arr::get($j2, 'shares.count') ?? 0);
                return $reac + $comms + $shares;
            }

            // Si llega error (p.ej. code 10), regresa null para que puedas loguearlo arriba
            return null;
        } catch (\Throwable $e) {
            Log::debug('[metrics][engagement:error]', ['err' => $e->getMessage(), 'post' => $postId]);
            return null;
        }
    }

    private function getGraphErrorCode(?\Illuminate\Http\Client\Response $resp): ?int
    {
        if (!$resp)
            return null;
        $json = $resp->json();
        return (int) data_get($json, 'error.code');
    }

    /**
     * Dado un videoId numérico, intenta devolver el post_id del feed si existe.
     * Usa creation_story{id}. Si no existe, devuelve null.
     */
    private function resolvePostIdFromVideoLight(string $videoId, string $pageToken): ?string
    {
        try {
            $resp = Http::withToken($pageToken)
                ->acceptJson()->timeout(25)->connectTimeout(10)
                ->get("https://graph.facebook.com/v23.0/{$videoId}", [
                    'fields' => 'id,creation_story{id}'
                ]);

            if ($resp->ok()) {
                $pid = data_get($resp->json(), 'creation_story.id');
                return $pid ? (string) $pid : null;
            }
        } catch (\Throwable $e) {
            Log::debug('[metrics] resolvePostIdFromVideoLight.exception', ['video_id' => $videoId, 'err' => $e->getMessage()]);
        }

        return null;
    }



    /**
     * Obtiene total de reacciones (likes y variantes) desde cualquier objeto (post o video).
     * Requiere pages_read_engagement. Para POST además podemos tener fallback por insights.
     */
    private function fetchReactionsTotal(string $objectId, string $pageToken, array $logCtx, bool $isPostId): ?int
    {
        // Guard: nunca consultes reacciones en Video
        if (!$isPostId) {
            Log::info('[metrics] reactions.skip.video', $logCtx + ['object_id' => $objectId]);
            return null;
        }

        // 1) Intento directo con /reactions
        try {
            $rx = Http::withToken($pageToken)
                ->acceptJson()->timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/v23.0/{$objectId}/reactions", [
                    'summary' => 'total_count',
                    'limit' => 0,
                ]);

            if ($rx->ok()) {
                $sum = (int) data_get($rx->json(), 'summary.total_count', 0);
                Log::info('[metrics] reactions.edge.ok', $logCtx + ['object_id' => $objectId, 'sum' => $sum]);
                return $sum;
            }

            $code = (int) data_get($rx->json(), 'error.code');
            Log::warning('[metrics] reactions.edge.fail', $logCtx + [
                'object_id' => $objectId,
                'status' => $rx->status(),
                'code' => $code,
                'body' => $rx->body(),
            ]);

            // Solo para POST: intenta insights si fue error típico de permisos
            if (!in_array($code, [10, 200], true)) {
                return null; // otros errores: no insistir
            }
        } catch (\Throwable $e) {
            Log::warning('[metrics] reactions.edge.exception', $logCtx + [
                'object_id' => $objectId,
                'err' => $e->getMessage()
            ]);
            // seguimos al fallback por insights
        }

        // 2) SOLO PARA POSTS: fallback por insights con breakdown
        try {
            $ins = Http::withToken($pageToken)
                ->acceptJson()->timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/v23.0/{$objectId}/insights", [
                    'metric' => 'post_reactions_by_type_total',
                    'period' => 'lifetime',
                ]);

            if ($ins->ok()) {
                $map = (array) data_get($ins->json(), 'data.0.values.0.value', []);
                $sum = array_reduce($map, fn($c, $v) => $c + (int) $v, 0);
                Log::info('[metrics] reactions.insights.ok', $logCtx + [
                    'object_id' => $objectId,
                    'sum' => $sum,
                    'breakdown' => $map
                ]);
                return $sum;
            }

            Log::warning('[metrics] reactions.insights.fail', $logCtx + [
                'object_id' => $objectId,
                'status' => $ins->status(),
                'code' => (int) data_get($ins->json(), 'error.code'),
                'body' => $ins->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('[metrics] reactions.insights.exception', $logCtx + [
                'object_id' => $objectId,
                'err' => $e->getMessage()
            ]);
            return null;
        }
    }


    /**
     * Fallback: busca en el feed de la Page alrededor de la hora de publicación para
     * encontrar el post del feed que referencia al videoId (por object_id o attachments.target.id).
     */
    private function resolvePostIdFromVideoByScanningFeed(
        string $pageIdReal,
        string $videoId,
        ?string $publishedAtIso,
        string $pageToken
    ): ?string {
        $published = $publishedAtIso ? \Carbon\Carbon::parse($publishedAtIso) : now();

        // Ventana más corta para evitar scans largos
        $since = $published->copy()->subHours(12)->timestamp;
        $until = $published->copy()->addHours(24)->timestamp;

        $endpoint = "https://graph.facebook.com/v23.0/{$pageIdReal}/published_posts";
        $params = [
            'fields' => 'id,created_time,permalink_url',
            'since' => $since,
            'until' => $until,
            'limit' => 50, // antes 100
        ];

        // Circuit breakers
        $maxTries = 5;   // antes 10
        $maxScanned = 250; // tope global de posts inspeccionados

        try {
            $url = $endpoint;
            $tries = 0;
            $scanned = 0;

            while ($url && $tries < $maxTries && $scanned < $maxScanned) {
                $tries++;

                $resp = Http::withToken($pageToken)
                    ->acceptJson()
                    ->timeout(20)->connectTimeout(8) // más agresivo
                    ->get($url, $params);

                if (!$resp->ok()) {
                    Log::debug('[metrics] feed.scan.fail', [
                        'page_id' => $pageIdReal,
                        'video_id' => $videoId,
                        'status' => $resp->status(),
                        'code' => (int) data_get($resp->json(), 'error.code'),
                    ]);
                    return null; // corta en fallo de página
                }

                $json = $resp->json();
                $data = (array) data_get($json, 'data', []);

                foreach ($data as $post) {
                    if ($scanned >= $maxScanned) {
                        Log::debug('[metrics] feed.scan.cutoff', [
                            'page_id' => $pageIdReal,
                            'video_id' => $videoId,
                            'scanned' => $scanned,
                        ]);
                        return null;
                    }

                    $pid = $post['id'] ?? null;
                    if (!$pid) {
                        $scanned++;
                        continue;
                    }

                    // ÚNICO intento: EDGE /{post_id}/attachments?fields=target{id}
                    try {
                        $attResp = Http::withToken($pageToken)
                            ->acceptJson()
                            ->timeout(20)->connectTimeout(8)
                            ->get("https://graph.facebook.com/v23.0/{$pid}/attachments", [
                                'fields' => 'target{id}',
                                'limit' => 5,
                            ]);

                        if ($attResp->ok()) {
                            foreach ((array) data_get($attResp->json(), 'data', []) as $att) {
                                $targetId = data_get($att, 'target.id');
                                if ($targetId && (string) $targetId === (string) $videoId) {
                                    Log::info('[metrics] feed.scan.match.attachment.edge', [
                                        'page_id' => $pageIdReal,
                                        'video_id' => $videoId,
                                        'post_id' => $pid,
                                    ]);
                                    return (string) $pid;
                                }
                            }
                        } else {
                            Log::debug('[metrics] feed.scan.attachments.fail', [
                                'post_id' => $pid,
                                'status' => $attResp->status(),
                                'code' => (int) data_get($attResp->json(), 'error.code'),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::debug('[metrics] feed.scan.attachments.exception', [
                            'post_id' => $pid,
                            'err' => $e->getMessage(),
                        ]);
                    }

                    $scanned++;
                }

                // paginación
                $url = (string) data_get($json, 'paging.next', '');
                if ($url === '') {
                    $url = null;
                }
                $params = []; // el "next" ya trae sus propios query params
            }
        } catch (\Throwable $e) {
            Log::warning('[metrics] feed.scan.exception', [
                'page_id' => $pageIdReal,
                'video_id' => $videoId,
                'err' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Resuelve el page_id REAL usando el Page Access Token.
     * Con un Page Token, /me devuelve la Page.
     */
    private function resolvePageIdFromToken(string $pageToken): ?string
    {
        try {
            $resp = Http::withToken($pageToken)
                ->acceptJson()->timeout(20)->connectTimeout(10)
                ->get('https://graph.facebook.com/v23.0/me', ['fields' => 'id,name']);
            if (!$resp->ok()) {
                Log::warning('[metrics] page-id.resolve.fail', [
                    'status' => $resp->status(),
                    'body' => $resp->body()
                ]);
                return null;
            }
            return (string) data_get($resp->json(), 'id');
        } catch (\Throwable $e) {
            Log::warning('[metrics] page-id.resolve.exception', ['err' => $e->getMessage()]);
            return null;
        }
    }
    private function resolveFacebookPageId(int|string $metaPageId): ?string
    {
        $row = MetaPage::query()->select('page_id')->find($metaPageId);
        $pid = $row?->page_id;
        return $pid ? (string) $pid : null;
    }

    public function updatePostMetrics(MetaPost $post): bool
    {
        // 0) Guard: no hay fb_post_id
        if (empty($post->fb_post_id)) {
            return false;
        }

        $ctx = [
            'post_id' => $post->id,
            'meta_page_id' => $post->meta_page_id,
            'fb_post_id' => (string) $post->fb_post_id,
            'published_at' => optional($post->published_at)->toIso8601String()
                ?? $post->created_at?->toIso8601String(),
        ];

        // 1) Page token
        try {
            $tokInfo = $this->resolvePageToken($post->meta_page_id);
        } catch (\Throwable $e) {
            Log::warning('[metrics] no-page-token-exception', $ctx + [
                'author_user_id' => $post->user_id,
                'err' => $e->getMessage(),
            ]);
            return false;
        }

        if (!$tokInfo) {
            Log::warning('[metrics] no-page-token', $ctx + ['author_user_id' => $post->user_id]);
            return false;
        }

        $pageToken = is_array($tokInfo) ? ($tokInfo['token'] ?? null) : (string) $tokInfo;
        $tokenSource = is_array($tokInfo) ? ($tokInfo['source'] ?? 'unknown') : 'unknown';

        if (empty($pageToken)) {
            Log::warning('[metrics] empty-page-token', $ctx + ['source' => $tokenSource]);
            return false;
        }

        Log::info('[metrics][token] using page token', $ctx + ['source' => $tokenSource]);

        // 2) Info del fb_post_id
        $fbId = (string) $post->fb_post_id;
        $isFeedPostId = str_contains($fbId, '_');          // ej: 123456789_987654321
        $looksNumeric = ctype_digit($fbId);                // típico para video/reel
        $isVideoType = strtolower((string) $post->type) === 'video';

        // 3) Valores "before" (para logs)
        $before = [
            'alcance_before' => $post->alcance,
            'visualizaciones_before' => $post->visualizaciones,
            'interacciones_before' => $post->interacciones,
        ];

        // Valores de trabajo
        $alcance = $post->alcance;
        $visualizaciones = $post->visualizaciones;
        $interacciones = $post->interacciones;

        $sources = [
            'post_insights' => false,
            'video_insights' => false, // por compatibilidad
            'reactions_from' => null,
            'resolved_post_id' => null,
            'token_source' => $tokenSource,
        ];

        $changed = false;

        /**
         * 4) INTENTAR ALCANCE / VISUALIZACIONES REALES (insights) PARA POSTS DEL FEED
         *    - alcance  = post_impressions_unique (reach)
         *    - visualiz = post_impressions (impressions)
         */
        if ($isFeedPostId) {
            $insErr = null;
            $ins = $this->fetchPostInsights($fbId, $pageToken, $insErr);

            if (is_array($ins)) {
                if (isset($ins['post_impressions_unique'])) {
                    $alcance = (int) $ins['post_impressions_unique'];
                }
                if (isset($ins['post_impressions'])) {
                    $visualizaciones = (int) $ins['post_impressions'];
                }

                $sources['post_insights'] = true;

                Log::info('[metrics] post_insights.ok', $ctx + [
                    'alcance' => $alcance,
                    'visualizaciones' => $visualizaciones,
                ]);
            } elseif ($insErr) {
                // Por ejemplo, falta read_insights → lo registramos y luego usamos fallback
                Log::info('[metrics] post_insights.skip', $ctx + [
                    'err' => $insErr,
                ]);
            }
        }

        /**
         * 5) DECIDIR SOBRE QUÉ ID MEDIR ENGAGEMENT (reacciones+comentarios+shares)
         *    - Fotos / posts normales: el propio fb_post_id
         *    - Videos/Reels: resolver el post del feed que referencia al video y usar ese ID
         */
        $engagementTargetId = null;

        if ($isFeedPostId) {
            // Caso normal: ya es un post del feed
            $engagementTargetId = $fbId;
        } elseif ($looksNumeric && $isVideoType) {
            // Video/Reel: intentamos resolver el post del feed
            $resolvedPostId = $this->resolvePostIdFromVideo(
                $fbId,
                (int) $post->meta_page_id,
                $ctx['published_at'],
                $pageToken
            );

            if ($resolvedPostId) {
                $engagementTargetId = $resolvedPostId;
                $sources['resolved_post_id'] = $resolvedPostId;

                Log::info('[metrics] engagement.post_id.resolved.from_video', $ctx + [
                    'video_id' => $fbId,
                    'post_id' => $resolvedPostId,
                ]);
            } else {
                Log::warning('[metrics] engagement.video.no-post', $ctx + [
                    'video_id' => $fbId,
                    'hint' => 'Video/Reel sin post de feed asociado; se omiten métricas de engagement',
                ]);
            }
        } else {
            // ID raro pero lo intentamos como si fuera post
            $engagementTargetId = $fbId;
        }

        /**
         * 6) LEER ENGAGEMENT (likes + comments + shares)
         */
        if ($engagementTargetId) {
            $engagement = $this->fetchEngagementCounts($engagementTargetId, $pageToken);

            if ($engagement !== null) {
                $interacciones = (int) $engagement;
                $sources['reactions_from'] = $engagementTargetId;

                // Si NO logramos insights reales, usamos engagement como aproximación
                if (!$sources['post_insights']) {
                    $alcance = $interacciones;
                    $visualizaciones = $interacciones;
                }
            } else {
                // Falló el endpoint de engagement (permiso, error 400, etc.)
                $sources['reactions_from'] = $engagementTargetId;

                Log::warning('[metrics] engagement.fetch.null', $ctx + [
                    'sources' => $sources,
                ]);
            }
        } else {
            Log::info('[metrics] no-engagement-target', $ctx + [
                'sources' => $sources,
            ]);
        }

        /**
         * 7) Persistir cambios SOLO si algo cambió
         */
        if ($alcance !== $post->alcance) {
            $post->alcance = $alcance;
            $changed = true;
        }

        if ($visualizaciones !== $post->visualizaciones) {
            $post->visualizaciones = $visualizaciones;
            $changed = true;
        }

        if ($interacciones !== $post->interacciones) {
            $post->interacciones = $interacciones;
            $changed = true;
        }

        if ($changed && Schema::hasColumn('meta_posts', 'last_insights_at')) {
            $post->last_insights_at = now();
        }

        if ($changed) {
            $post->saveQuietly();

            Log::info('[metrics] updated', $ctx + $before + [
                'alcance_after' => $post->alcance,
                'visualizaciones_after' => $post->visualizaciones,
                'interacciones_after' => $post->interacciones,
                'sources' => $sources,
            ]);
        } else {
            Log::info('[metrics] no-change', $ctx + $before + [
                'alcance_after' => $post->alcance,
                'visualizaciones_after' => $post->visualizaciones,
                'interacciones_after' => $post->interacciones,
                'sources' => $sources,
            ]);
        }

        return $changed;
    }






    /**
     * Señales CLARAS de que el error es “objeto Video”.
     * NO tratamos (#100) "The value must be a valid insights metric" como video.
     */
    private function looksLikeVideoError(?string $err): bool
    {
        if (!$err)
            return false;
        $e = strtolower($err);

        if (str_contains($e, 'node type (video)'))
            return true;
        if (str_contains($e, 'video_insights'))
            return true;
        if (preg_match('/\bobject_id\b/', $e))
            return true;

        return false;
    }




    /** ===== Helpers de Graph ===== */

    private function safeGet(string $path, array $params, string $pageToken, ?string &$err = null, bool $video = false): ?array
    {
        $err = null;
        try {
            $resp = Http::acceptJson()
                ->connectTimeout(15)
                ->timeout(30)
                ->withToken($pageToken)
                ->get(FG::url($path, $video), $params);

            if ($resp->successful()) {
                return $resp->json();
            }

            $this->logGraphError('GET', $resp);
            $err = "HTTP request returned status code {$resp->status()}:\n" . $resp->body();
            return null;

        } catch (\Throwable $e) {
            Log::warning('[FB][GET].exception', ['url' => FG::url($path, $video), 'error' => $e->getMessage()]);
            $err = $e->getMessage();
            return null;
        }
    }

    /** POST insights (post_impressions, post_impressions_unique, post_engaged_users) */
    public function fetchPostInsights(string $postId, string $pageToken, ?string &$err = null): ?array
    {
        $err = null;

        $params = [
            'metric' => 'post_impressions,post_impressions_unique',
            'period' => 'lifetime',
        ];

        $json = $this->safeGet("{$postId}/insights", $params, $pageToken, $err, false);
        if (!$json || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }

        $out = [];
        foreach ($json['data'] as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;

            // Asegura que value sea escalar numérico (int o string numérica)
            if ($name && (is_int($value) || (is_string($value) && is_numeric($value)))) {
                $out[$name] = (int) $value;
            }
        }

        // Normaliza: si no hay nada útil, vuelve null
        if (empty($out)) {
            return null;
        }

        // Solo regresa las claves que te interesan (por si Meta devuelve extras)
        return array_intersect_key($out, array_flip([
            'post_impressions',
            'post_impressions_unique',
            'post_engaged_users',
        ])) ?: null;
    }


    /** VIDEO insights (total_video_impressions, total_video_views) */
    public function fetchVideoInsights(string $videoId, string $pageToken, ?string &$err = null): ?array
    {
        $err = null;
        $params = [
            'metric' => 'total_video_impressions,total_video_views',
        ];
        $json = $this->safeGet("{$videoId}/video_insights", $params, $pageToken, $err, true);
        if (!$json)
            return null;

        $out = [];
        foreach ($json['data'] ?? [] as $row) {
            $name = $row['name'] ?? null;
            $value = $row['values'][0]['value'] ?? null;
            if ($name && $value !== null) {
                $out[$name] = (int) $value;
            }
        }
        return $out ?: null;
    }

    /** De un POST sacar object_id (suele ser el video_id si embebe un video). */
    public function fetchPostObjectId(string $postId, string $pageToken, ?string &$err = null): ?string
    {
        $err = null;
        $json = $this->safeGet($postId, ['fields' => 'object_id,created_time'], $pageToken, $err, false);
        if (!$json)
            return null;

        $obj = $json['object_id'] ?? null;
        if ($obj) {
            Log::info('[metrics] post.object_id', ['post_id' => $postId, 'object_id' => $obj]);
        }
        return $obj;
    }


    /**
     * Dado un videoId (incluye Reels), intenta resolver el POST-ID del feed.
     * 1) Intento "light": /{videoId}?fields=creation_story{id}
     * 2) Si no hay creation_story, escanea el feed de la Page en la ventana de tiempo.
     *
     * @param string      $videoId        ID numérico del video/reel (p.ej. "3362584647213282")
     * @param int         $metaPageId     ID interno para mapear a page_id real (tabla meta_pages)
     * @param string|null $publishedAtIso ISO8601 de publicación (optimiza ventana de escaneo)
     * @param string      $pageToken      Page Access Token con pages_read_engagement
     */
    public function resolvePostIdFromVideo(
        string $videoId,
        int $metaPageId,
        ?string $publishedAtIso,
        string $pageToken
    ): ?string {
        // 1) Intento rápido: creation_story{id} en el objeto VIDEO
        try {
            $resp = Http::withToken($pageToken)
                ->acceptJson()
                ->timeout(30)->connectTimeout(10)
                ->get("https://graph.facebook.com/v23.0/{$videoId}", [
                    'fields' => 'id,creation_story{id}',
                ]);

            if ($resp->ok()) {
                $creationPostId = data_get($resp->json(), 'creation_story.id');
                if (!empty($creationPostId)) {
                    Log::info('[metrics] creation_story.match', [
                        'video_id' => $videoId,
                        'post_id' => $creationPostId,
                    ]);
                    return (string) $creationPostId;
                }
            } else {
                Log::warning('[metrics] creation_story.fail', [
                    'video_id' => $videoId,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[metrics] creation_story.exception', [
                'video_id' => $videoId,
                'err' => $e->getMessage(),
            ]);
        }

        // 2) Fallback: escanear feed de la Page alrededor de la hora de publicación
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');
        if (empty($pageId)) {
            Log::warning('[metrics] page-id.unresolved', [
                'video_id' => $videoId,
                'meta_page_id' => $metaPageId,
            ]);
            return null;
        }

        return $this->resolvePostIdFromVideoByScanningFeed(
            (string) $pageId,
            $videoId,
            $publishedAtIso,
            $pageToken
        );
    }

    private function pickPivotForPage(int $metaPageId, int $userId): array
    {
        // 1) Pivote del MISMO usuario
        $pivotUser = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first(['page_access_token', 'expires_at', 'social_account_id', 'user_id', 'updated_at']);

        if ($pivotUser && !empty($pivotUser->page_access_token)) {
            if (empty($pivotUser->expires_at) || now()->lt($pivotUser->expires_at)) {
                return [$pivotUser, 'user'];
            }
        }

        // 2) Cualquier pivote ACTIVA de esa página (por si el post lo ve otro admin)
        $pivotAny = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->orderByDesc('updated_at')
            ->first(['page_access_token', 'expires_at', 'social_account_id', 'user_id', 'updated_at']);

        if ($pivotAny && !empty($pivotAny->page_access_token)) {
            if (empty($pivotAny->expires_at) || now()->lt($pivotAny->expires_at)) {
                return [$pivotAny, 'any'];
            }
        }

        return [null, null];
    }

    /** Heurística para decidir si el error de post_insights sugiere que es un video. */

    private function logGraphError(string $where, $resp): void
    {
        try {
            $body = $resp->json();
        } catch (\Throwable) {
            $body = $resp->body();
        }
        Log::warning("[FB][$where] {$resp->status()}", [
            'body' => $body,
            'trace_id' => $resp->header('x-fb-trace-id'),
        ]);
    }
}
