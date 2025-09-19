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
use App\Jobs\PublishVideoToFacebook;
use App\Jobs\PublishPhotosToFacebook;


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

            $query = MetaPage::with('users');

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

            // Estricto: MP4/MOV
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime', 'mimes:mp4,mov', 'max:1024000'],
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

        // 3) Páginas
        $pages = MetaPage::whereIn('id', $request->page_ids)
            ->with(['users' => fn($q) => $q->wherePivot('is_active', true)])
            ->get();

        if ($pages->isEmpty()) {
            return back()->withErrors(['page_ids' => 'No se encontraron páginas válidas.']);
        }

        $results = [];
        $batch = (string) \Str::uuid();

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

                    $resp = Http::asForm()->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);

                    $ok = $resp->ok();
                    $body = $resp->json();

                    if ($ok) {
                        $postId = data_get($body, 'id');
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $postId;
                        $postData['published_at'] = now();

                        try {
                            $postData['fb_permalink_url'] = $this->fetchPermalink($postId, $token);
                        } catch (\Throwable $e) {
                        }
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error'] = $resp->body();
                    }

                    MetaPost::create($postData);
                    $results[] = ['page' => $page->name, 'ok' => $ok, 'body' => $body, 'error' => $ok ? null : $resp->body()];
                    continue;
                }

                // ===== FOTOS (en cola) =====
                if ($request->type === 'photo') {
                    // 1) guarda todas las fotos en URL pública temporal
                    $urls = [];
                    $rels = [];
                    $abss = [];

                    foreach ($request->file('photos', []) as $file) {
                        $res = $this->storePhotoPublicTmp($file); // ya lo tienes implementado
                        if (!($res['ok'] ?? false)) {
                            $postData['status'] = 'fail';
                            $postData['error'] = $res['error'] ?? 'No se pudo guardar una imagen en público.';
                            MetaPost::create($postData);
                            $results[] = ['page' => $page->name, 'ok' => false, 'error' => $postData['error']];
                            continue 2; // salta a la siguiente página del foreach ($pages as $page)
                        }
                        $urls[] = $res['url'];
                        $rels[] = $res['cleanup_rel'] ?? null;
                        $abss[] = $res['cleanup_abs'] ?? null;
                    }

                    if (empty($urls)) {
                        $postData['status'] = 'fail';
                        $postData['error'] = 'No se pudo preparar ninguna imagen.';
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => $postData['error']];
                        continue;
                    }

                    // 2) crea el MetaPost como pending (tu enum permite: pending|success|fail)
                    $postData['status'] = 'pending';
                    $postData['local_media'] = json_encode([
                        'photo_urls' => $urls,
                        'cleanup_rel' => $rels,
                        'cleanup_abs' => $abss,
                    ]);
                    $postData['fb_media_ids'] = null;
                    $postData['fb_post_id'] = null;
                    $postData['fb_permalink_url'] = null;

                    $metaPost = MetaPost::create($postData);

                    // 3) despacha el Job (opcional: añade ->delay() si quieres escalonar por página)
                    PublishPhotosToFacebook::dispatch([
                        'meta_post_id' => $metaPost->id,
                        'page_id' => $pageId,
                        'page_name' => $page->name,
                        'page_token' => $token,
                        'photo_urls' => $urls,
                        'cleanup_rel' => $rels,
                        'cleanup_abs' => $abss,
                        'caption' => $request->message,
                    ])->onQueue('default');

                    // 4) feedback inmediato
                    $results[] = [
                        'page' => $page->name,
                        'ok' => true,
                        'body' => ['queued' => true, 'meta_post_id' => $metaPost->id],
                    ];
                    continue;
                }

                // ===== VIDEO (en cola con file_url) =====
                if ($request->type === 'video') {
                    @set_time_limit(0);

                    $file = $request->file('video');
                    $message = $request->message;

                    if (!$file || !$file->isValid()) {
                        $code = $file?->getError() ?? UPLOAD_ERR_NO_FILE;
                        $msg = match ($code) {
                            UPLOAD_ERR_INI_SIZE => 'El archivo supera upload_max_filesize (php.ini).',
                            UPLOAD_ERR_FORM_SIZE => 'El archivo supera MAX_FILE_SIZE (formulario).',
                            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente.',
                            UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo.',
                            UPLOAD_ERR_NO_TMP_DIR => 'Falta upload_tmp_dir.',
                            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir en disco.',
                            UPLOAD_ERR_EXTENSION => 'Una extensión detuvo la subida.',
                            default => 'Fallo de subida (código ' . $code . ').',
                        };

                        $postData['status'] = 'fail';
                        $postData['error'] = 'Error de subida: ' . $msg;
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => $postData['error']];
                        continue;
                    }

                    // 1) Guardar a URL pública temporal
                    $pub = $this->storeVideoPublicTmp($file);
                    if (!($pub['ok'] ?? false)) {
                        $postData['status'] = 'fail';
                        $postData['error'] = $pub['error'] ?? 'No se pudo guardar el video en público.';
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => $postData['error']];
                        continue;
                    }

                    // 2) Crear MetaPost como "queued"
                    $postData['status'] = 'pending';
                    $postData['local_media'] = json_encode([
                        'public_url' => $pub['url'],
                        'cleanup_rel' => $pub['cleanup_rel'] ?? null,
                        'cleanup_abs' => $pub['cleanup_abs'] ?? null,
                    ]);
                    $postData['message'] = $message;
                    $postData['fb_media_ids'] = null;
                    $postData['fb_post_id'] = null;
                    $postData['fb_permalink_url'] = null;

                    $metaPost = MetaPost::create($postData);

                    // 3) Despachar Job
                    PublishVideoToFacebook::dispatch([
                        'meta_post_id' => $metaPost->id,
                        'page_id' => $pageId,
                        'page_name' => $page->name,
                        'page_token' => $token,
                        'public_url' => $pub['url'],
                        'cleanup_rel' => $pub['cleanup_rel'] ?? null,
                        'cleanup_abs' => $pub['cleanup_abs'] ?? null,
                        'caption' => $message,
                    ])->onQueue('default');

                    // 4) Feedback inmediato (no bloqueamos la request)
                    $results[] = [
                        'page' => $page->name,
                        'ok' => true,
                        'body' => ['queued' => true, 'meta_post_id' => $metaPost->id],
                    ];
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

        // —— Estadísticas finales (contando queued como éxito inmediato solo informativo) ——
        $published = collect($results)
            ->where('ok', true)
            ->filter(fn($r) => empty(data_get($r, 'body.queued')))
            ->count();

        $queued = collect($results)
            ->where('ok', true)
            ->filter(fn($r) => data_get($r, 'body.queued') === true)
            ->count();

        $fails = collect($results)->where('ok', false)->count();

        $mensaje = match (true) {
            $published > 0 && $queued > 0 => "Listo: publicados {$published} y en cola {$queued}. Fallidos: {$fails}.",
            $published > 0 => "Listo: publicados {$published}. Fallidos: {$fails}.",
            $queued > 0 => "Tus videos quedaron en cola ({$queued}). Fallidos: {$fails}.",
            default => "No se pudo procesar nada. Revisa los detalles.",
        };

        return back()
            ->with('success', $mensaje)
            ->with('publish_results', $results);
    }



    /**
     * Guarda el video en un URL público temporal.
     * - Intenta disco "public" (requiere storage:link). Si falla, cae a public/uploads/tmp.
     * - Hace chequeos de directorio y permisos. Si algo falla, devuelve ok=false con error.
     *
     * @return array{
     *   ok: bool,
     *   url?: string,
     *   rel?: string|null,
     *   abs?: string,
     *   cleanup_rel?: string|null,
     *   cleanup_abs?: string|null,
     *   error?: string
     * }
     */
    private function storeVideoPublicTmp(\Illuminate\Http\UploadedFile $file): array
    {
        // Sanidad previa
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'El archivo llegó con tamaño 0 (parcial o bloqueado).'];
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        $name = (string) \Illuminate\Support\Str::uuid() . '.' . $ext;

        // 1) Intentar disco "public" (storage/app/public → public/storage)
        try {
            // ¿existe el disk?
            \Illuminate\Support\Facades\Storage::disk('public')->exists('.');
            if (!\Illuminate\Support\Facades\Storage::disk('public')->exists('videos/tmp')) {
                \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('videos/tmp');
            }

            // guardar
            $stream = fopen($file->getRealPath(), 'r');
            if ($stream === false) {
                return ['ok' => false, 'error' => 'No se pudo abrir el archivo temporal del upload.'];
            }

            $path = 'videos/tmp/' . $name;
            $saved = \Illuminate\Support\Facades\Storage::disk('public')->put($path, $stream);
            if (is_resource($stream))
                fclose($stream);

            if (!$saved) {
                return ['ok' => false, 'error' => 'Falló escribir en storage/app/public/videos/tmp.'];
            }

            $abs = storage_path('app/public/' . $path);
            if (!file_exists($abs)) {
                return ['ok' => false, 'error' => 'No se encontró el archivo guardado en storage (permisos).'];
            }

            $url = asset('storage/' . $path);
            return [
                'ok' => true,
                'url' => $url,
                'rel' => 'public/' . $path, // para Storage::delete
                'abs' => $abs,
                'cleanup_rel' => 'public/' . $path,
                'cleanup_abs' => null,
            ];
        } catch (\Throwable $e) {
            // sigue a fallback
        }

        // 2) Fallback a public/uploads/tmp
        $dir = public_path('uploads/tmp');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'No se pudo crear public/uploads/tmp (permisos).'];
        }
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => 'public/uploads/tmp no es escribible (permisos).'];
        }

        $abs = $dir . '/' . $name;
        try {
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo mover a public/uploads/tmp: ' . $e->getMessage()];
        }

        if (!file_exists($abs)) {
            return ['ok' => false, 'error' => 'El archivo no quedó en public/uploads/tmp.'];
        }

        $url = url('uploads/tmp/' . $name);
        return [
            'ok' => true,
            'url' => $url,
            'rel' => null,
            'abs' => $abs,
            'cleanup_rel' => null,
            'cleanup_abs' => $abs,
        ];
    }

    /**
     * Guarda 1 foto en una URL pública temporal.
     * Retorna: ok, url, cleanup_rel/abs, error
     */
    private function storePhotoPublicTmp(\Illuminate\Http\UploadedFile $file): array
    {
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'El archivo llegó con tamaño 0 (parcial/bloqueado).'];
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name = (string) \Illuminate\Support\Str::uuid() . '.' . $ext;

        // 1) disco public (storage:link)
        try {
            \Illuminate\Support\Facades\Storage::disk('public')->exists('.');
            if (!\Illuminate\Support\Facades\Storage::disk('public')->exists('images/tmp')) {
                \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('images/tmp');
            }

            $stream = fopen($file->getRealPath(), 'r');
            if ($stream === false) {
                return ['ok' => false, 'error' => 'No se pudo abrir el archivo temporal del upload.'];
            }

            $path = 'images/tmp/' . $name;
            $saved = \Illuminate\Support\Facades\Storage::disk('public')->put($path, $stream);
            if (is_resource($stream))
                fclose($stream);

            if (!$saved)
                return ['ok' => false, 'error' => 'Falló escribir en storage/app/public/images/tmp.'];

            $abs = storage_path('app/public/' . $path);
            if (!file_exists($abs))
                return ['ok' => false, 'error' => 'No se encontró el archivo guardado en storage.'];

            $url = asset('storage/' . $path);
            return [
                'ok' => true,
                'url' => $url,
                'rel' => 'public/' . $path,   // para Storage::delete
                'abs' => $abs,
                'cleanup_rel' => 'public/' . $path,
                'cleanup_abs' => null,
            ];
        } catch (\Throwable $e) {
            // fallback
        }

        // 2) fallback a public/uploads/tmp
        $dir = public_path('uploads/tmp');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'No se pudo crear public/uploads/tmp.'];
        }
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => 'public/uploads/tmp no es escribible.'];
        }

        $abs = $dir . '/' . $name;
        try {
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo mover a public/uploads/tmp: ' . $e->getMessage()];
        }

        if (!file_exists($abs))
            return ['ok' => false, 'error' => 'El archivo no quedó en public/uploads/tmp.'];

        $url = url('uploads/tmp/' . $name);
        return [
            'ok' => true,
            'url' => $url,
            'rel' => null,
            'abs' => $abs,
            'cleanup_rel' => null,
            'cleanup_abs' => $abs,
        ];
    }


    /**
     * Sube un video a la Página usando file_url (Meta descarga el archivo desde tu dominio).
     * Requiere Page Access Token con pages_manage_posts.
     *
     * @return array{ok:bool, video_id?:string, permalink?:string, error?:string, body?:mixed}
     */
    private function uploadVideoByFileUrl(string $pageId, string $pageAccessToken, string $fileUrl, ?string $description): array
    {
        $endpoint = "https://graph-video.facebook.com/v23.0/{$pageId}/videos";

        $resp = Http::asForm()->post($endpoint, array_filter([
            'file_url' => $fileUrl,
            'description' => $description,
            'published' => true,
            'access_token' => $pageAccessToken,
        ], fn($v) => !is_null($v)));

        if (!$resp->ok()) {
            $body = $resp->json() ?? $resp->body();
            $msg = is_array($body) ? data_get($body, 'error.message') : (string) $body;
            return ['ok' => false, 'error' => $msg ?: 'Graph error', 'body' => $body];
        }

        $videoId = data_get($resp->json(), 'id');
        if (!$videoId) {
            return ['ok' => false, 'error' => 'Sin video_id en respuesta', 'body' => $resp->json()];
        }

        // Poll corto para diagnosticar procesamiento (ready/error)
        $permalink = null;
        try {
            $tries = 3;
            while ($tries-- > 0) {
                $s = Http::get("https://graph.facebook.com/v23.0/{$videoId}", [
                    'fields' => 'status,processing_progress,permalink_url',
                    'access_token' => $pageAccessToken,
                ])->json();

                $state = data_get($s, 'status.video_status');  // ready | processing | error
                $permalink = data_get($s, 'permalink_url');

                if ($state === 'ready')
                    break;
                if ($state === 'error') {
                    $reason = data_get($s, 'status.failure_reason') ?: 'processing_failed';
                    return ['ok' => false, 'error' => $reason, 'body' => $s];
                }
                sleep(6);
            }
        } catch (\Throwable $e) {
            // no-op
        }

        return ['ok' => true, 'video_id' => $videoId, 'permalink' => $permalink, 'body' => $resp->json()];
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

                $metaPage = MetaPage::updateOrCreate(
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
