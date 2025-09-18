<?php

namespace App\Http\Controllers\Meta;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Access\AuthorizationException;
use App\Support\FacebookGraph;


class FacebookPageController extends Controller
{
    protected FacebookGraph $fb;

    public function __construct(FacebookGraph $fb)
    {
        $this->fb = $fb;
    }
    public function index(Request $request)
    {
        $user = Auth::user();
        $ownerId = $request->query('owner_id');

        if ($user->isAdmin()) {
            $owners = User::whereHas('metaPages')
                ->orderBy('name')
                ->get(['id', 'name']);

            $query = \App\Models\MetaPage::with('users');

            if ($ownerId) {
                $query->whereHas('users', function ($q) use ($ownerId) {
                    $q->where('users.id', $ownerId);
                });
            }

            // 👇 sin paginar
            $pages = $query->latest()->get();
        } else {
            $owners = collect();

            // 👇 si tu tabla/pivot no tiene created_at, usa ->orderByDesc('meta_pages.id')
            $pages = $user->metaPages()
                ->with('users')
                ->latest() // o ->orderByDesc('meta_pages.id')
                ->get();
        }

        return view('meta.pages.index', compact('pages', 'owners', 'ownerId'));
    }




    /**
     * Obtiene permalink_url si es posible (no es fatal si falla).
     */
    private function fetchPermalink(?string $objectId, string $token): ?string
    {
        if (!$objectId)
            return null;

        $endpoint = "https://graph.facebook.com/v20.0/{$objectId}";
        $params = [
            'fields' => 'permalink_url',
            'access_token' => $token,
        ];

        $maxAttempts = 3; // pequeño backoff por si FB aún no refleja el post
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $t0 = microtime(true);
                $resp = Http::timeout(30)
                    ->connectTimeout(10)
                    ->acceptJson()
                    ->get($endpoint, $params);

                $elapsed = round(microtime(true) - $t0, 3);

                if ($resp->ok()) {
                    $permalink = data_get($resp->json(), 'permalink_url');
                    if ($permalink) {
                        Log::info('FB fetchPermalink: ok', [
                            'object_id' => $objectId,
                            'attempt' => $attempt,
                            'elapsed_s' => $elapsed,
                            'permalink' => $permalink,
                        ]);
                        return $permalink;
                    }
                }

                // Log detallado de error/respuesta
                $body = $resp->body();
                $json = @json_decode($body, true) ?: [];
                Log::warning('FB fetchPermalink: failed', [
                    'object_id' => $objectId,
                    'attempt' => $attempt,
                    'status' => $resp->status(),
                    'elapsed_s' => $elapsed,
                    'error_message' => data_get($json, 'error.message'),
                    'error_type' => data_get($json, 'error.type'),
                    'error_code' => data_get($json, 'error.code'),
                    'error_subcode' => data_get($json, 'error.error_subcode'),
                    'fbtrace_id' => data_get($json, 'error.fbtrace_id'),
                    'body_snippet' => mb_substr($body, 0, 800),
                ]);

                // Reintenta en errores típicos/consistencia eventual
                if (in_array($resp->status(), [400, 404, 500, 502, 503, 504]) && $attempt < $maxAttempts) {
                    usleep(300_000); // 300ms
                    continue;
                }

                return null;
            } catch (\Throwable $e) {
                Log::error('FB fetchPermalink: exception', [
                    'object_id' => $objectId,
                    'attempt' => $attempt,
                    'msg' => $e->getMessage(),
                ]);
                if ($attempt < $maxAttempts) {
                    usleep(300_000);
                    continue;
                }
                return null;
            }
        }

        return null;
    }
    public function publish(Request $request)
    {
        // 1) Validación base
        $request->validate([
            'type' => ['required', 'in:text,photo,video'],
            'page_ids' => ['required', 'array', 'min:1'],
            'page_ids.*' => [Rule::exists('meta_pages', 'id')],
            'message' => ['nullable', 'string', 'max:63206'],
            'link' => ['nullable', 'url'],
            'photos' => ['nullable', 'array', 'max:50'],
            'photos.*' => ['file', 'image', 'max:10240'], // 10MB
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm', 'max:512000'], // ~500MB
        ]);

        if ($request->type === 'text') {
            $request->validate(['message' => ['required', 'string', 'max:63206']]);
        } elseif ($request->type === 'photo') {
            if (!$request->hasFile('photos')) {
                return back()->withErrors(['photos' => 'Selecciona al menos una imagen.'])->withInput();
            }
        } elseif ($request->type === 'video') {
            if (!$request->hasFile('video')) {
                return back()->withErrors(['video' => 'Selecciona un video.'])->withInput();
            }
        }

        // 2) Loggers: fb.log + laravel.log (doble por si fb.log no escribe)
        $fbLog = Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/fb.log'),
            'level' => 'debug',
        ]);

        $logResp = function (string $label, \Illuminate\Http\Client\Response $resp) use ($fbLog) {
            $payload = [
                'label' => $label,
                'status' => $resp->status(),
                'headers' => $resp->headers(),
                'json' => $resp->json(),
                'bodyRaw' => mb_substr($resp->body(), 0, 2000),
            ];
            $fbLog->debug($label, $payload);
            Log::debug("[FB] {$label}", $payload);
        };

        $logInfo = function (string $label, array $ctx = []) use ($fbLog) {
            $fbLog->info($label, $ctx);
            Log::info("[FB] {$label}", $ctx);
        };

        $logWarn = function (string $label, array $ctx = []) use ($fbLog) {
            $fbLog->warning($label, $ctx);
            Log::warning("[FB] {$label}", $ctx);
        };

        // 3) Páginas
        $pages = MetaPage::whereIn('id', $request->page_ids)
            ->with(['users' => fn($q) => $q->wherePivot('is_active', true)])
            ->get();

        if ($pages->isEmpty()) {
            return back()->withErrors(['page_ids' => 'No se encontraron páginas válidas.']);
        }

        $results = [];
        $batch = (string) \Illuminate\Support\Str::uuid();

        foreach ($pages as $page) {
            $pivot = $page->users->first()?->pivot;
            if (!$pivot?->page_access_token) {
                $results[] = ['page' => $page->name, 'ok' => false, 'error' => 'Sin token activo'];
                continue;
            }

            $pageId = $page->page_id;
            $token = $pivot->page_access_token;

            $postData = [
                'batch_uuid' => $batch,
                'user_id' => auth()->id(),
                'meta_page_id' => $page->id,
                'type' => $request->type,
                'message' => $request->message,
                'link' => $request->link,
                'local_media' => null,
                'fb_media_ids' => null,
                'status' => 'pending',
                'published_at' => null,
                'fb_post_id' => null,
                'fb_permalink_url' => null,
                'error' => null,
            ];

            try {
                // ===== TEXTO =====
                if ($request->type === 'text') {
                    $payload = ['message' => $request->message, 'access_token' => $token];
                    if ($request->filled('link'))
                        $payload['link'] = $request->link;

                    $logInfo('FEED text/link: begin', ['page' => $pageId, 'payload' => $payload]);

                    $resp = Http::asForm()->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);
                    $logResp('FEED text/link: response', $resp);

                    $ok = $resp->ok();
                    $body = $resp->json();

                    if ($ok) {
                        $postId = data_get($body, 'id');
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $postId;
                        $postData['published_at'] = now();

                        // Permalink opcional
                        try {
                            $permalink = $this->fetchPermalink($postId, $token);
                            $postData['fb_permalink_url'] = $permalink;
                        } catch (\Throwable $e) {
                            $logWarn('Permalink fetch skipped', ['post_id' => $postId, 'err' => $e->getMessage()]);
                        }
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error'] = $resp->body();
                    }

                    MetaPost::create($postData);
                    $results[] = ['page' => $page->name, 'ok' => $ok, 'body' => $body, 'error' => $ok ? null : $resp->body()];
                    continue;
                }

                // ===== FOTOS =====
                if ($request->type === 'photo') {
                    $media = [];
                    foreach ($request->file('photos', []) as $file) {
                        $real = $file->getRealPath();
                        $name = $file->getClientOriginalName();

                        $logInfo('PHOTO upload: begin', ['page' => $pageId, 'name' => $name, 'size' => $file->getSize()]);

                        $r = Http::attach('source', fopen($real, 'r'), $name)
                            ->asMultipart()
                            ->post("https://graph.facebook.com/v23.0/{$pageId}/photos", [
                                'published' => false,
                                'access_token' => $token,
                            ]);

                        $logResp('PHOTO upload: response', $r);

                        if ($r->ok() && ($id = data_get($r->json(), 'id'))) {
                            $media[] = ['media_fbid' => $id];
                        } else {
                            $logWarn('FB photo upload failed', ['page' => $pageId, 'status' => $r->status(), 'body' => $r->body()]);
                        }
                    }

                    if (empty($media)) {
                        $postData['status'] = 'fail';
                        $postData['error'] = 'No se pudieron subir las imágenes.';
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => 'No se pudieron subir las imágenes.'];
                        continue;
                    }

                    $postData['fb_media_ids'] = array_map(fn($m) => $m['media_fbid'], $media);

                    $payload = ['access_token' => $token];
                    if ($request->filled('message'))
                        $payload['message'] = $request->message;
                    foreach ($media as $i => $m)
                        $payload["attached_media[$i]"] = json_encode($m);

                    $logInfo('FEED publish photos: begin', ['page' => $pageId]);

                    $resp = Http::asForm()->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);
                    $logResp('FEED publish photos: response', $resp);

                    $ok = $resp->ok();
                    $body = $resp->json();

                    if ($ok) {
                        $postId = data_get($body, 'id');
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $postId;
                        $postData['published_at'] = now();

                        try {
                            $permalink = $this->fetchPermalink($postId, $token);
                            $postData['fb_permalink_url'] = $permalink;
                        } catch (\Throwable $e) {
                            $logWarn('Permalink fetch skipped', ['post_id' => $postId, 'err' => $e->getMessage()]);
                        }
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error'] = $resp->body();
                    }

                    MetaPost::create($postData);
                    $results[] = ['page' => $page->name, 'ok' => $ok, 'body' => $body, 'error' => $ok ? null : $resp->body()];
                    continue;
                }

                // ===== VIDEO =====
                if ($request->type === 'video') {
                    @set_time_limit(0);

                    $file = $request->file('video');
                    $real = $file->getRealPath();
                    $size = (int) $file->getSize(); // más fiable
                    $name = $file->getClientOriginalName();
                    $mime = $file->getMimeType();

                    $logInfo('VIDEO: begin', ['page' => $pageId, 'name' => $name, 'size' => $size, 'mime' => $mime]);

                    // helper para guardar resultado
                    $commit = function (array $postData, bool $ok, $errOrBody = null) use (&$results, $page) {
                        MetaPost::create($postData);
                        $results[] = [
                            'page' => $page->name,
                            'ok' => $ok,
                            'error' => $ok ? null : (is_string($errOrBody) ? $errOrBody : json_encode($errOrBody)),
                            'body' => $ok ? (is_array($errOrBody) ? $errOrBody : null) : null,
                        ];
                    };

                    // Camino A: simple (≤25MB)
                    if ($size > 0 && $size <= 25 * 1024 * 1024) {
                        // validar fopen para loguear si falla
                        $handle = @fopen($real, 'r');
                        if ($handle === false) {
                            $postData['status'] = 'fail';
                            $postData['error'] = 'fopen() falló sobre el archivo tmp del video.';
                            $logWarn('VIDEO simple: fopen failed', ['page' => $pageId, 'path' => $real]);
                            $commit($postData, false, 'No se pudo abrir el archivo temporal del video.');
                            continue;
                        }

                        $logInfo('VIDEO simple upload: POST /videos', ['page' => $pageId]);

                        $resp = Http::timeout(600)
                            ->asMultipart()
                            ->attach('source', $handle, $name)
                            ->post("https://graph.facebook.com/v23.0/{$pageId}/videos", array_filter([
                                'published' => true,
                                'description' => $request->message,
                                'access_token' => $token,
                            ], fn($v) => !is_null($v)));

                        @fclose($handle);

                        $logResp('VIDEO simple upload: response', $resp);

                        if ($resp->ok() && ($videoId = data_get($resp->json(), 'id'))) {
                            $postData['status'] = 'success';
                            $postData['fb_post_id'] = $videoId;
                            $postData['fb_media_ids'] = [$videoId];
                            $postData['published_at'] = now();

                            try {
                                $permalink = $this->fetchPermalink($videoId, $token);
                                $postData['fb_permalink_url'] = $permalink;
                            } catch (\Throwable $e) {
                                $logWarn('Permalink fetch skipped', ['post_id' => $videoId, 'err' => $e->getMessage()]);
                            }

                            $commit($postData, true, ['video_id' => $videoId]);
                        } else {
                            $postData['status'] = 'fail';
                            $postData['error'] = $resp->body();
                            $commit($postData, false, $resp->body());
                        }

                        continue;
                    }

                    // Camino B: resumable (>25MB)
                    $logInfo('VIDEO resumable: START', ['page' => $pageId, 'size' => $size]);
                    $start = Http::timeout(120)
                        ->asForm()
                        ->post("https://graph.facebook.com/v23.0/{$pageId}/videos", [
                            'upload_phase' => 'start',
                            'file_size' => $size,
                            'access_token' => $token,
                        ]);

                    $logResp('VIDEO resumable: START response', $start);

                    if (!$start->ok()) {
                        $postData['status'] = 'fail';
                        $postData['error'] = $start->body();
                        $commit($postData, false, $start->body());
                        continue;
                    }

                    $sessionId = data_get($start->json(), 'upload_session_id');
                    $startOffset = (int) data_get($start->json(), 'start_offset', 0);
                    $endOffset = (int) data_get($start->json(), 'end_offset', 0);

                    $fh = @fopen($real, 'rb');
                    if (!$fh) {
                        $postData['status'] = 'fail';
                        $postData['error'] = 'No se pudo abrir el archivo de video (resumable).';
                        $logWarn('VIDEO resumable: fopen failed', ['page' => $pageId, 'path' => $real]);
                        $commit($postData, false, 'No se pudo abrir el archivo de video.');
                        continue;
                    }

                    try {
                        while ($startOffset < $endOffset) {
                            $chunkLen = $endOffset - $startOffset;
                            fseek($fh, $startOffset);
                            $chunk = fread($fh, $chunkLen);
                            if ($chunk === false) {
                                $postData['status'] = 'fail';
                                $postData['error'] = 'Error leyendo chunk de video.';
                                $commit($postData, false, 'Error leyendo chunk de video.');
                                continue 2;
                            }

                            $transfer = Http::timeout(600)
                                ->asMultipart()
                                ->attach('video_file_chunk', $chunk, 'chunk.bin')
                                ->post("https://graph.facebook.com/v23.0/{$pageId}/videos", [
                                    ['name' => 'upload_phase', 'contents' => 'transfer'],
                                    ['name' => 'start_offset', 'contents' => (string) $startOffset],
                                    ['name' => 'upload_session_id', 'contents' => $sessionId],
                                    ['name' => 'access_token', 'contents' => $token],
                                ]);

                            $logResp('VIDEO resumable: TRANSFER response', $transfer);

                            if (!$transfer->ok()) {
                                $postData['status'] = 'fail';
                                $postData['error'] = $transfer->body();
                                $commit($postData, false, $transfer->body());
                                continue 2;
                            }

                            $startOffset = (int) data_get($transfer->json(), 'start_offset', 0);
                            $endOffset = (int) data_get($transfer->json(), 'end_offset', 0);
                        }
                    } finally {
                        @fclose($fh);
                    }

                    $finish = Http::timeout(300)
                        ->asForm()
                        ->post("https://graph.facebook.com/v23.0/{$pageId}/videos", array_filter([
                            'upload_phase' => 'finish',
                            'upload_session_id' => $sessionId,
                            'description' => $request->message,
                            'access_token' => $token,
                        ], fn($v) => !is_null($v)));

                    $logResp('VIDEO resumable: FINISH response', $finish);

                    if ($finish->ok()) {
                        $videoId = data_get($finish->json(), 'video_id');
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $videoId;
                        $postData['fb_media_ids'] = $videoId ? [$videoId] : null;
                        $postData['published_at'] = now();

                        try {
                            $permalink = $this->fetchPermalink($videoId, $token);
                            $postData['fb_permalink_url'] = $permalink;
                        } catch (\Throwable $e) {
                            $logWarn('Permalink fetch skipped', ['post_id' => $videoId, 'err' => $e->getMessage()]);
                        }

                        $commit($postData, true, ['video_id' => $videoId]);
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error'] = $finish->body();
                        $commit($postData, false, $finish->body());
                    }

                    continue;
                }

            } catch (\Throwable $e) {
                $postData['status'] = 'fail';
                $postData['error'] = $e->getMessage();
                Log::error('[FB] publish(): exception', ['err' => $e]);
                MetaPost::create($postData);
                $results[] = ['page' => $page->name, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $fails = collect($results)->where('ok', false)->count();
        $ok = collect($results)->where('ok', true)->count();

        return back()
            ->with('success', "Publicación enviada. OK: {$ok}, Fails: {$fails}")
            ->with('publish_results', $results);
    }







    protected function socialite()
    {
        return Socialite::driver('facebook')
            ->scopes(config('services.facebook.scopes', []))
            ->redirectUrl(route('facebook.link.callback'));
    }

    public function linkRedirect()
    {
        return Socialite::driver('facebook')
            ->scopes(config('services.facebook.scopes') ?? [])
            ->redirectUrl(route('facebook.link.callback'))
            ->redirect();
    }

    public function linkCallback()
    {
        $fbUser = Socialite::driver('facebook')
            ->redirectUrl(route('facebook.link.callback'))
            ->user();

        $fbUser = $this->socialite()->user();
        $current = Auth::user();

        $existing = SocialAccount::where('provider', 'facebook')
            ->where('provider_user_id', $fbUser->getId())
            ->first();

        if ($existing && $existing->user_id !== $current->id) {
            $existing->update([
                'user_id' => $current->id,
                'name' => $fbUser->getName(),
                'avatar' => $fbUser->getAvatar(),
                'access_token' => $fbUser->token,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at' => isset($fbUser->expiresIn) ? now()->addSeconds((int) $fbUser->expiresIn) : null,
                'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]);
            $social = $existing;
        } else {
            $social = SocialAccount::updateOrCreate(
                [
                    'user_id' => $current->id,
                    'provider' => 'facebook',
                    'provider_user_id' => $fbUser->getId(),
                ],
                [
                    'name' => $fbUser->getName(),
                    'avatar' => $fbUser->getAvatar(),
                    'access_token' => $fbUser->token,
                    'refresh_token' => $fbUser->refreshToken ?? null,
                    'expires_at' => isset($fbUser->expiresIn) ? now()->addSeconds((int) $fbUser->expiresIn) : null,
                    'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
                ]
            );
        }

        $count = $this->performSync($current, $social);

        return redirect()
            ->route('meta.pages.index')
            ->with('success', "Páginas sincronizadas: {$count}");
    }

    public function sync(Request $request)
    {
        $user = Auth::user();

        $social = SocialAccount::where('user_id', $user->id)
            ->where('provider', 'facebook')
            ->first();

        if (!$social) {
            return redirect()->route('facebook.redirect');
        }

        $count = $this->performSync($user, $social);

        return back()->with('success', "Páginas sincronizadas: {$count}");
    }

    private function performSync(User $user, SocialAccount $social): int
    {
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';

        $resp = Http::withToken($social->access_token)
            ->get('https://graph.facebook.com/v20.0/me/accounts', ['fields' => $fields]);

        if (!$resp->ok()) {
            Log::error('FB /me/accounts error', [
                'status' => $resp->status(),
                'body' => $resp->body()
            ]);
            throw new \RuntimeException('No se pudieron obtener las páginas: ' . $resp->body());
        }

        $pages = data_get($resp->json(), 'data', []);
        if (empty($pages)) {
            throw new \RuntimeException("No se encontraron páginas.
            - Acepta los permisos requeridos.
            - Verifica que la cuenta administre al menos una página.");
        }

        DB::transaction(function () use ($pages, $user, $social) {
            foreach ($pages as $page) {
                $pageId = (string) data_get($page, 'id');
                $name = data_get($page, 'name');
                $category = data_get($page, 'category');
                $picture = "https://graph.facebook.com/v20.0/{$pageId}/picture?type=normal";

                $tasks = data_get($page, 'tasks', []);
                if (!is_array($tasks)) {
                    $tasks = $tasks ? [$tasks] : [];
                }

                $metaPage = \App\Models\MetaPage::updateOrCreate(
                    ['page_id' => $pageId],
                    [
                        'name' => $name,
                        'category' => $category,
                        'instagram_business_account_id' => data_get($page, 'connected_instagram_business_account.id'),
                        'picture_url' => $picture,
                        'tasks' => array_values($tasks),
                    ]
                );

                $user->metaPages()->syncWithoutDetaching([
                    $metaPage->id => [
                        'page_access_token' => data_get($page, 'access_token'),
                        'social_account_id' => $social->id,
                        'expires_at' => null,
                        'is_active' => true,
                    ]
                ]);
            }
        });

        return count($pages);
    }
    public function unlinkAccount()
    {
        $user = Auth::user();

        DB::transaction(function () use ($user) {

            $user->metaPages()->updateExistingPivot(
                $user->metaPages()->pluck('meta_pages.id')->all(),
                ['is_active' => false, 'page_access_token' => null, 'expires_at' => null]
            );

            SocialAccount::where('user_id', $user->id)
                ->where('provider', 'facebook')
                ->delete();
        });

        return back()->with('success', 'Facebook desvinculado de tu cuenta y tokens limpiados.');
    }

    public function unlinkPage(Request $request, MetaPage $metaPage)
    {
        $user = auth()->user();
        $ownerId = $request->input('owner_id');

        if ($user->isAdmin()) {
            if ($ownerId) {

                $exists = $metaPage->users()->where('users.id', $ownerId)->exists();
                if (!$exists) {
                    return back()->with('error', 'Ese propietario no está asociado a esta página.');
                }

                $metaPage->users()->updateExistingPivot($ownerId, [
                    'is_active' => false,
                    'page_access_token' => '',
                    'expires_at' => null,
                ]);

                return back()->with('success', "Página «{$metaPage->name}» desvinculada para el usuario seleccionado.");
            }

            $userIds = $metaPage->users()->pluck('users.id')->all();
            if (empty($userIds)) {
                return back()->with('success', "Página «{$metaPage->name}» no tenía vínculos activos.");
            }

            foreach ($userIds as $uid) {
                $metaPage->users()->updateExistingPivot($uid, [
                    'is_active' => false,
                    'page_access_token' => '',
                    'expires_at' => null,
                ]);
            }

            return back()->with('success', "Página «{$metaPage->name}» desvinculada para todos los usuarios.");
        }

        $exists = $user->metaPages()->where('meta_page_id', $metaPage->id)->exists();
        abort_unless($exists, 403);

        $user->metaPages()->updateExistingPivot($metaPage->id, [
            'is_active' => false,
            'page_access_token' => '',
            'expires_at' => null,
        ]);

        return back()->with('success', "Página «{$metaPage->name}» desvinculada.");
    }

    public function linkSinglePage(Request $request, MetaPage $metaPage)
    {
        $user = auth()->user();
        $ownerId = $request->input('owner_id');

        if (!$user->isAdmin()) {
            $pivot = $user->metaPages()->where('meta_page_id', $metaPage->id)->first();
            abort_unless($pivot, 403);

            $social = SocialAccount::where('user_id', $user->id)
                ->where('provider', 'facebook')
                ->first();

            if (!$social) {
                return redirect()->route('facebook.redirect')
                    ->with('info', 'Conecta tu Facebook y vuelve a intentar.');
            }

            $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string) $metaPage->page_id);
            if (!$found || empty($found['access_token'])) {
                return back()->with('error', 'No se encontró token para esta página. Verifica tu rol y permisos (pages_manage_posts).');
            }

            $metaPage->update([
                'name' => $found['name'] ?? $metaPage->name,
                'category' => $found['category'] ?? $metaPage->category,
                'picture_url' => "https://graph.facebook.com/v20.0/{$metaPage->page_id}/picture?type=normal",
                'tasks' => is_array($found['tasks'] ?? null) ? array_values($found['tasks']) : $metaPage->tasks,
                'instagram_business_account_id' => data_get($found, 'connected_instagram_business_account.id', $metaPage->instagram_business_account_id),
            ]);


            $user->metaPages()->syncWithoutDetaching([
                $metaPage->id => [
                    'page_access_token' => $found['access_token'],
                    'social_account_id' => $social->id,
                    'expires_at' => null,
                    'is_active' => true,
                ]
            ]);

            return back()->with('success', "Página «{$metaPage->name}» vinculada correctamente.");
        }

        if ($ownerId) {
            $owner = User::find($ownerId);
            if (!$owner)
                return back()->with('error', 'El propietario especificado no existe.');
            if (!$metaPage->users()->where('users.id', $owner->id)->exists()) {
                return back()->with('error', 'Ese propietario no tiene esta página asociada.');
            }
            $social = SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();
            if (!$social)
                return back()->with('error', "«{$owner->name}» no tiene Facebook conectado.");
        } else {
            $owner = $metaPage->users()
                ->wherePivotNotNull('social_account_id')
                ->withPivot(['social_account_id'])
                ->first();

            if (!$owner)
                return back()->with('error', 'No hay un usuario propietario con Facebook conectado para esta página.');

            $social = SocialAccount::find($owner->pivot->social_account_id)
                ?: SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();

            if (!$social)
                return back()->with('error', 'No se encontró el token del propietario.');
        }

        $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string) $metaPage->page_id);
        if (!$found || empty($found['access_token'])) {
            return back()->with('error', 'El propietario no tiene permisos actuales sobre esta página o no hay token.');
        }

        $metaPage->update([
            'name' => $found['name'] ?? $metaPage->name,
            'category' => $found['category'] ?? $metaPage->category,
            'picture_url' => "https://graph.facebook.com/v20.0/{$metaPage->page_id}/picture?type=normal",
            'tasks' => is_array($found['tasks'] ?? null) ? array_values($found['tasks']) : $metaPage->tasks,
            'instagram_business_account_id' => data_get($found, 'connected_instagram_business_account.id', $metaPage->instagram_business_account_id),
        ]);

        $metaPage->users()->updateExistingPivot($owner->id, [
            'page_access_token' => $found['access_token'],
            'social_account_id' => $social->id,
            'expires_at' => null,
            'is_active' => true,
        ]);

        return back()->with('success', "Página «{$metaPage->name}» vinculada usando la cuenta de «{$owner->name}».");
    }
}
