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
    // CORREGIDO: siempre devuelve **Page Access Token** real; si el pivot está vacío o vencido, intercambia usando el user token
    protected function resolvePageToken(int $metaPageId): array
    {
        if (isset($this->pageTokenCache[$metaPageId])) {
            return $this->pageTokenCache[$metaPageId];
        }

        // page_id real (tabla meta_pages)
        $pageId = DB::table('meta_pages')->where('id', $metaPageId)->value('page_id');

        // 1) Page token del pivot (válido/no vencido)
        $row = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->orderByDesc('updated_at')
            ->first(['user_id', 'page_access_token', 'expires_at', 'social_account_id']);

        $notExpired = function ($expiresAt) {
            return empty($expiresAt) || now()->lt($expiresAt);
        };

        if ($row && !empty($row->page_access_token) && $notExpired($row->expires_at ?? null)) {
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

        // 2) Si no hay page token (o está vencido), intenta construirlo desde un user access token del pivot
        $socialRow = DB::table('meta_page_user')
            ->where('meta_page_id', $metaPageId)
            ->where('is_active', 1)
            ->whereNotNull('social_account_id')
            ->orderByDesc('updated_at')
            ->first(['social_account_id', 'user_id']);

        if ($socialRow && $socialRow->social_account_id) {
            $social = DB::table('social_accounts')->where('id', $socialRow->social_account_id)->first(['access_token']);
            if ($social && !empty($social->access_token) && !empty($pageId)) {
                $pageTok = $this->exchangeUserTokenForPageToken((string) $social->access_token, (string) $pageId);
                if ($pageTok) {
                    Log::info('[metrics][token] exchanged user->page', [
                        'meta_page_id' => $metaPageId,
                        'social_account_id' => $socialRow->social_account_id,
                    ]);

                    // Opcional: persistir el page token en el pivot para próximas corridas
                    DB::table('meta_page_user')
                        ->where('meta_page_id', $metaPageId)
                        ->where('is_active', 1)
                        ->where('social_account_id', $socialRow->social_account_id)
                        ->update(['page_access_token' => $pageTok, 'updated_at' => now()]);

                    $info = ['token' => $pageTok, 'source' => 'exchanged_from_user', 'user_id' => $socialRow->user_id];
                    return $this->pageTokenCache[$metaPageId] = $info;
                }
            }
        }

        // (Opcional) último intento: cualquier pivot activo con social_account_id, aunque no sea el último
        if (!empty($pageId)) {
            $anySocial = DB::table('meta_page_user')
                ->where('meta_page_id', $metaPageId)
                ->where('is_active', 1)
                ->whereNotNull('social_account_id')
                ->orderByDesc('updated_at')
                ->pluck('social_account_id');

            foreach ($anySocial as $sid) {
                $social = DB::table('social_accounts')->where('id', $sid)->first(['access_token']);
                if ($social && !empty($social->access_token)) {
                    $pageTok = $this->exchangeUserTokenForPageToken((string) $social->access_token, (string) $pageId);
                    if ($pageTok) {
                        Log::info('[metrics][token] exchanged user->page (fallback-any)', [
                            'meta_page_id' => $metaPageId,
                            'social_account_id' => $sid,
                        ]);

                        DB::table('meta_page_user')
                            ->where('meta_page_id', $metaPageId)
                            ->where('is_active', 1)
                            ->where('social_account_id', $sid)
                            ->update(['page_access_token' => $pageTok, 'updated_at' => now()]);

                        $info = ['token' => $pageTok, 'source' => 'exchanged_from_user_any', 'user_id' => null];
                        return $this->pageTokenCache[$metaPageId] = $info;
                    }
                }
            }
        }

        throw new \RuntimeException("No hay token utilizable para meta_page_id={$metaPageId}");
    }

    // NUEVO helper: cambia un user access token -> page access token para UNA page específica
    private function exchangeUserTokenForPageToken(string $userAccessToken, string $targetPageId): ?string
    {
        try {
            $resp = Http::withToken($userAccessToken)
                ->acceptJson()->timeout(25)->connectTimeout(10)
                ->get('https://graph.facebook.com/v23.0/me/accounts', [
                    'fields' => 'id,access_token'
                ]);

            if (!$resp->ok()) {
                Log::warning('[metrics][token] me/accounts.fail', [
                    'status' => $resp->status(),
                    'body' => $resp->body()
                ]);
                return null;
            }

            foreach ((array) data_get($resp->json(), 'data', []) as $pg) {
                if ((string) ($pg['id'] ?? '') === (string) $targetPageId && !empty($pg['access_token'])) {
                    return (string) $pg['access_token'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[metrics][token] me/accounts.exception', ['err' => $e->getMessage()]);
        }
        return null;
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

    // CORREGIDO: usa primero el breakdown de insights (si llega) para "interacciones" y evita duplicar llamadas a /reactions
    public function updatePostMetrics(MetaPost $post): bool
    {
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

        // 1) Page token real
        $tokInfo = $this->resolvePageToken($post->meta_page_id);
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

        // page_id real
        $pageIdReal = $this->resolveFacebookPageId($post->meta_page_id)
            ?? $this->resolvePageIdFromToken($pageToken);
        if (!$pageIdReal) {
            Log::warning('[metrics] page-id.unresolved', $ctx + ['hint' => 'No se pudo mapear meta_page_id -> page_id real']);
        }
        $ctx['page_id_real'] = $pageIdReal;

        // 2) Helper HTTP local
        $httpGet = function (string $url, array $params = []) use ($pageToken, $ctx) {
            try {
                $resp = Http::withToken($pageToken)
                    ->acceptJson()
                    ->timeout(40)->connectTimeout(10)
                    ->retry(3, 1000)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->get($url, $params);

                if (!$resp->ok()) {
                    Log::warning('[FB][GET] fail', $ctx + [
                        'url' => $url,
                        'status' => $resp->status(),
                        'body' => $resp->body(),
                    ]);
                    return [null, $resp];
                }
                return [$resp->json(), $resp];
            } catch (\Throwable $e) {
                Log::warning('[FB][GET].exception', $ctx + [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                return [null, null];
            }
        };

        // 3) Helpers de insights
        $fetchPostInsights = function (string $postId) use ($httpGet) {
            [$data, $resp] = $httpGet(
                "https://graph.facebook.com/v23.0/{$postId}/insights",
                ['metric' => 'post_impressions,post_impressions_unique,post_reactions_by_type_total', 'period' => 'lifetime']
            );
            if (!$data || !isset($data['data']))
                return [null, $resp];

            $out = [];
            foreach ($data['data'] as $m) {
                $name = $m['name'] ?? null;
                $val = $m['values'][0]['value'] ?? null;
                if ($name && $val !== null) {
                    $out[$name] = $name === 'post_reactions_by_type_total'
                        ? (array) $val
                        : (int) $val;
                }
            }
            return [$out, $resp];
        };

        $fetchVideoInsights = function (string $videoId) use ($httpGet) {
            [$data, $resp] = $httpGet(
                "https://graph-video.facebook.com/v23.0/{$videoId}/video_insights",
                ['metric' => 'total_video_impressions,total_video_views']
            );
            if (!$data || !isset($data['data']))
                return [null, $resp];

            $out = [];
            foreach ($data['data'] as $m) {
                $name = $m['name'] ?? null;
                $val = $m['values'][0]['value'] ?? null;
                if ($name && $val !== null) {
                    $out[$name] = (int) $val;
                }
            }
            return [$out, $resp];
        };

        // 4) Flags/iniciales
        $fbId = (string) $post->fb_post_id;
        $isFeedPostId = str_contains($fbId, '_');   // ej: 123_456
        $looksNumericId = ctype_digit($fbId);       // típico para videoId
        $isVideoType = strtolower((string) $post->type) === 'video';

        $before = [
            'alcance_before' => $post->alcance,
            'visualizaciones_before' => $post->visualizaciones,
            'interacciones_before' => $post->interacciones,
        ];
        $alcance = $post->alcance;
        $visualizaciones = $post->visualizaciones;
        $interacciones = $post->interacciones;

        $sources = [
            'post_insights' => false,
            'video_insights' => false,
            'reactions_from' => null,
            'resolved_post_id' => null,
            'token_source' => $tokenSource,
        ];

        // 5) Flujo alcance/visualizaciones + reacciones (insights primero)
        $reactionBreakdown = null;
        $ins = null;

        // 5.1 Post del feed
        if ($isFeedPostId) {
            [$ins, $r] = $fetchPostInsights($fbId);
            if (is_array($ins)) {
                $alcance = $ins['post_impressions_unique'] ?? $alcance;
                $visualizaciones = $ins['post_impressions'] ?? $visualizaciones;
                $sources['post_insights'] = true;
                Log::info('[metrics] post_insights.ok', $ctx + ['alc' => $alcance, 'vis' => $visualizaciones]);

                if (!empty($ins['post_reactions_by_type_total']) && is_array($ins['post_reactions_by_type_total'])) {
                    $reactionBreakdown = $ins['post_reactions_by_type_total'];
                    $sumRx = array_reduce($reactionBreakdown, fn($c, $v) => $c + (int) $v, 0);
                    $interacciones = (int) $sumRx;
                    $sources['reactions_from'] = 'insights:post_reactions_by_type_total';
                }
            } else {
                Log::warning('[metrics] post_insights.fail', $ctx + [
                    'status' => optional($r)->status(),
                    'body' => optional($r)->body()
                ]);
            }
        }

        // 5.2 Video (videoId puro) o post que en realidad es video
        if (!$sources['post_insights'] && ($isVideoType || ($looksNumericId && !$isFeedPostId))) {
            if ($looksNumericId && !$isFeedPostId) {
                [$vins, $vr] = $fetchVideoInsights($fbId);
                if (is_array($vins)) {
                    $visualizaciones = $vins['total_video_views'] ?? $visualizaciones;
                    $alcance = $vins['total_video_impressions'] ?? $alcance;
                    $sources['video_insights'] = true;
                    Log::info('[metrics] video_insights.ok', $ctx + ['alc' => $alcance, 'vis' => $visualizaciones]);
                } else {
                    Log::warning('[metrics] video_insights.fail', $ctx + [
                        'status' => optional($vr)->status(),
                        'body' => optional($vr)->body()
                    ]);
                }
            } else {
                // postId que representa un video -> intenta resolver el post del feed real
                $postId = $this->resolvePostIdFromVideo(
                    $fbId,
                    $post->meta_page_id,
                    optional($post->published_at)->toIso8601String(),
                    $pageToken
                );
                if ($postId) {
                    $sources['resolved_post_id'] = $postId;
                    [$ins2, $r2] = $fetchPostInsights($postId);
                    if (is_array($ins2)) {
                        $alcance = $ins2['post_impressions_unique'] ?? $alcance;
                        $visualizaciones = $ins2['post_impressions'] ?? $visualizaciones;
                        $sources['post_insights'] = true;
                        Log::info('[metrics] post_from_video.ok', $ctx + ['resolved_post_id' => $postId, 'alc' => $alcance]);

                        if (!empty($ins2['post_reactions_by_type_total']) && is_array($ins2['post_reactions_by_type_total'])) {
                            $reactionBreakdown = $ins2['post_reactions_by_type_total'];
                            $sumRx = array_reduce($reactionBreakdown, fn($c, $v) => $c + (int) $v, 0);
                            $interacciones = (int) $sumRx;
                            $sources['reactions_from'] = 'insights:post_reactions_by_type_total';
                        }
                    } else {
                        Log::warning('[metrics] post_from_video.fail', $ctx + [
                            'resolved_post_id' => $postId,
                            'status' => optional($r2)->status(),
                            'body' => optional($r2)->body()
                        ]);
                    }
                } else {
                    Log::info('[metrics] post_id.not_found_from_video', $ctx);
                }
            }
        }

        // === Reacciones (solo si NO las obtuvimos por insights) ===
        $engagementPostId = null;
        if (empty($sources['reactions_from'])) {
            if ($isFeedPostId) {
                $engagementPostId = $fbId;
            } else {
                // 1) creation_story{id}
                if ($looksNumericId && ($isVideoType || true)) {
                    $resolved = $this->resolvePostIdFromVideoLight($fbId, $pageToken);
                    if ($resolved) {
                        $engagementPostId = $resolved;
                        $sources['resolved_post_id'] = $resolved;
                        Log::info('[metrics] engagement.post_id.resolved', $ctx + ['resolved_post_id' => $resolved]);
                    } elseif (!empty($pageIdReal)) {
                        // 2) scan feed
                        $resolved2 = $this->resolvePostIdFromVideoByScanningFeed(
                            (string) $pageIdReal,
                            $fbId,
                            optional($post->published_at)->toIso8601String() ?? $post->created_at?->toIso8601String(),
                            $pageToken
                        );
                        if ($resolved2) {
                            $engagementPostId = $resolved2;
                            $sources['resolved_post_id'] = $resolved2;
                            Log::info('[metrics] engagement.post_id.resolved.scan', $ctx + ['resolved_post_id' => $resolved2]);
                        }
                    }
                }
            }

            if ($engagementPostId) {
                $rx = $this->fetchReactionsTotal($engagementPostId, $pageToken, $ctx, true);
                if ($rx !== null) {
                    $interacciones = (int) $rx;
                    $sources['reactions_from'] = $engagementPostId;
                }
            } else {
                Log::warning('[metrics] reactions.post_id.not_found', $ctx + [
                    'hint' => 'videoId sin post/story; no hay /reactions en Video'
                ]);
            }
        }

        // 6) Persistir (y guardar breakdown si tienes columna JSON)
        $changed = false;

        if ($alcance !== null && $alcance !== $post->alcance) {
            $post->alcance = $alcance;
            $changed = true;
        }
        if ($visualizaciones !== null && $visualizaciones !== $post->visualizaciones) {
            $post->visualizaciones = $visualizaciones;
            $changed = true;
        }
        if ($interacciones !== null && $interacciones !== $post->interacciones) {
            $post->interacciones = $interacciones;
            $changed = true;
        }

        if ($reactionBreakdown && Schema::hasColumn('meta_posts', 'reacciones_json')) {
            $post->reacciones_json = $reactionBreakdown;
            $changed = true;
        }

        if (
            ($sources['post_insights'] || $sources['video_insights'] || $sources['reactions_from']) &&
            Schema::hasColumn('meta_posts', 'last_insights_at')
        ) {
            $post->last_insights_at = now();
            $changed = true;
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
