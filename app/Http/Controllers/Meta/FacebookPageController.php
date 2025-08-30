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

            $pages = $query->latest()
                ->paginate(18)
                ->appends($request->query());
        } else {
            $owners = collect();
            $pages = $user->metaPages()
                ->with('users')
                ->paginate(18);
        }

        return view('meta.pages.index', compact('pages', 'owners', 'ownerId'));
    }

    private function uploadVideoSimple(string $pageId, string $token, string $absPath, string $filename, ?string $description = null): ?string
    {
        $payload = [
            'access_token' => $token,
            'published'    => true,
        ];
        if ($description) $payload['description'] = $description;

        $resp = Http::attach('source', fopen($absPath, 'r'), $filename)
            ->post("https://graph.facebook.com/v20.0/{$pageId}/videos", $payload);

        if ($resp->ok()) {
            // algunas respuestas traen { id: "video_id" }
            return data_get($resp->json(), 'id');
        }
        Log::warning('FB simple video upload failed', ['page' => $pageId, 'resp' => $resp->body()]);
        return null;
    }

    /**
     * Upload reanudable (chunked) para videos grandes. Devuelve video_id o null.
     */
    private function uploadVideoResumable(string $pageId, string $token, string $absPath, ?string $description = null): ?string
    {
        $fileSize   = @filesize($absPath) ?: 0;
        if ($fileSize <= 0) return null;

        $endpoint   = "https://graph.facebook.com/v20.0/{$pageId}/videos";

        // Usa chunks más pequeños para no agotar la sesión (2 MB)
        $chunkSize  = 2 * 1024 * 1024; // 2MB

        // Cliente con timeouts altos y reintentos
        $clientBase = Http::timeout(300)          // hasta 5 min por request
            ->connectTimeout(30)    // 30s para conectar
            ->retry(3, 2000);       // 3 reintentos con 2s entre intentos

        // 1) START
        $start = $clientBase->asForm()->post($endpoint, [
            'access_token' => $token,
            'upload_phase' => 'start',
            'file_size'    => $fileSize,
        ]);

        if (!$start->ok()) {
            Log::warning('FB video start failed', ['resp' => $start->body()]);
            return null;
        }

        $sessionId   = (string) data_get($start->json(), 'upload_session_id');
        $startOffset = (int) data_get($start->json(), 'start_offset', 0);
        $endOffset   = (int) data_get($start->json(), 'end_offset', 0);

        // 2) TRANSFER (loop)
        $fh = fopen($absPath, 'rb');
        if (!$fh) return null;

        try {
            while ($startOffset < $endOffset) {
                // Facebook te dice el siguiente rango; acótalo a chunkSize para no tardar
                $length = min($endOffset - $startOffset, $chunkSize);

                // Lee el trozo
                fseek($fh, $startOffset);
                $data = fread($fh, $length);
                if ($data === false || strlen($data) === 0) {
                    Log::warning('FB video read chunk failed', ['offset' => $startOffset, 'length' => $length]);
                    return null;
                }

                // Sube el chunk con reintento y timeout alto
                $transfer = $clientBase
                    ->attach('video_file_chunk', $data, 'chunk.bin')
                    ->asForm()
                    ->post($endpoint, [
                        'access_token'      => $token,
                        'upload_phase'      => 'transfer',
                        'upload_session_id' => $sessionId,
                        'start_offset'      => $startOffset,
                    ]);

                if (!$transfer->ok()) {
                    // Intenta detectar error transitorio de sesión agotada
                    $json = json_decode($transfer->body(), true);
                    $sub  = data_get($json, 'error.error_subcode');
                    $transient = (bool) data_get($json, 'error.is_transient');

                    // Si es transitorio o 1363030, reintenta el MISMO chunk una vez más manualmente
                    if ($transient || $sub === 1363030) {
                        Log::warning('FB video transfer transient, retrying same chunk', [
                            'offset' => $startOffset,
                            'length' => $length,
                            'resp' => $transfer->body()
                        ]);

                        // Un reintento manual extra
                        $transfer = $clientBase
                            ->attach('video_file_chunk', $data, 'chunk.bin')
                            ->asForm()
                            ->post($endpoint, [
                                'access_token'      => $token,
                                'upload_phase'      => 'transfer',
                                'upload_session_id' => $sessionId,
                                'start_offset'      => $startOffset,
                            ]);

                        if (!$transfer->ok()) {
                            Log::warning('FB video transfer failed after retry', ['resp' => $transfer->body()]);
                            return null;
                        }
                    } else {
                        Log::warning('FB video transfer failed', ['resp' => $transfer->body()]);
                        return null;
                    }
                }

                // Actualiza offsets; sigue hasta que start_offset == end_offset
                $startOffset = (int) data_get($transfer->json(), 'start_offset', 0);
                $endOffset   = (int) data_get($transfer->json(), 'end_offset', 0);
            }
        } finally {
            fclose($fh);
        }

        // 3) FINISH
        $finishParams = [
            'access_token'      => $token,
            'upload_phase'      => 'finish',
            'upload_session_id' => $sessionId,
            'published'         => true,
        ];
        if ($description) $finishParams['description'] = $description;

        $finish = $clientBase->asForm()->post($endpoint, $finishParams);
        if ($finish->ok()) {
            return data_get($finish->json(), 'video_id') ?? null;
        }

        Log::warning('FB video finish failed', ['resp' => $finish->body()]);
        return null;
    }

    /**
     * Intento de resolver el video_id usando la sesión (fallback).
     */
    private function resolveVideoIdFromSession(string $sessionId, string $token): ?string
    {
        // Algunos entornos no exponen endpoint público para recuperar por sesión.
        // Como fallback, devolvemos null. Puedes implementar un log/tabla temporal si lo necesitas.
        return null;
    }

    /**
     * Obtiene permalink_url si es posible (no es fatal si falla).
     */
    private function fetchPermalink(?string $objectId, string $token): ?string
    {
        if (!$objectId) return null;
        try {
            $r = Http::get("https://graph.facebook.com/v20.0/{$objectId}", [
                'fields'       => 'permalink_url',
                'access_token' => $token,
            ]);
            return $r->ok() ? data_get($r->json(), 'permalink_url') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    public function publish(Request $request)
    {
        // 1) Validación base
        $request->validate([
            'type'        => ['required', 'in:text,photo,video'],
            'page_ids'    => ['required', 'array', 'min:1'],
            'page_ids.*'  => [Rule::exists('meta_pages', 'id')],
            'message'     => ['nullable', 'string', 'max:63206'],
            'link'        => ['nullable', 'url'],
            'photos'      => ['nullable', 'array', 'max:50'],
            'photos.*'    => ['file', 'image', 'max:10240'], // 10MB
            'video'       => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm', 'max:512000'], // ~500MB
        ]);

        // Validación condicional
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

        // 2) Páginas destino
        $pages = MetaPage::whereIn('id', $request->page_ids)
            ->with(['users' => fn($q) => $q->wherePivot('is_active', true)])
            ->get();

        $results = [];

        foreach ($pages as $page) {
            $pivot = $page->users->first()?->pivot;
            if (!$pivot?->page_access_token) {
                $results[] = ['page' => $page->name, 'ok' => false, 'error' => 'Sin token activo'];
                continue;
            }

            $pageId = $page->page_id;
            $token  = $pivot->page_access_token;

            // Registro base para histórico
            $postData = [
                'user_id'            => auth()->id(),
                'meta_page_id'       => $page->id,
                'type'               => $request->type,
                'message'            => $request->message,
                'link'               => $request->link,
                'local_media'        => null,
                'fb_media_ids'       => null,
                'status'             => 'pending',
                'published_at'       => null,
                'fb_post_id'         => null,
                'fb_permalink_url'   => null,
                'error'              => null,
            ];

            try {
                if ($request->type === 'text') {
                    // === TEXTO/ENLACE ===
                    $payload = ['message' => $request->message, 'access_token' => $token];
                    if ($request->filled('link')) $payload['link'] = $request->link;

                    $resp = Http::asForm()->post("https://graph.facebook.com/v20.0/{$pageId}/feed", $payload);
                    $ok   = $resp->ok();
                    $body = $resp->json();

                    if ($ok) {
                        $postId    = data_get($body, 'id');
                        $permalink = $this->fetchPermalink($postId, $token);
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $postId;
                        $postData['fb_permalink_url'] = $permalink;
                        $postData['published_at'] = now();
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error']  = $resp->body();
                    }

                    MetaPost::create($postData);
                    $results[] = ['page' => $page->name, 'ok' => $ok, 'body' => $body, 'error' => $ok ? null : $resp->body()];
                } elseif ($request->type === 'photo') {
                    // === FOTOS (archivos) ===
                    // 1) Guardar local
                    $savedPaths = [];
                    foreach ($request->file('photos', []) as $file) {
                        $path = $file->store('posts/' . now()->format('Y/m/d'), 'public');
                        $savedPaths[] = $path;
                    }
                    $postData['local_media'] = $savedPaths;

                    // 2) Subir unpublished y recolectar media_fbid
                    $media = [];
                    foreach ($savedPaths as $rel) {
                        $abs = storage_path('app/public/' . $rel);
                        $h = @fopen($abs, 'r');
                        if ($h === false) {
                            Log::warning('No se pudo abrir imagen', ['path' => $abs]);
                            continue;
                        }
                        $r = Http::attach('source', $h, basename($abs))
                            ->post("https://graph.facebook.com/v20.0/{$pageId}/photos", [
                                'published'    => false,
                                'access_token' => $token,
                            ]);
                        if ($r->ok() && ($id = data_get($r->json(), 'id'))) {
                            $media[] = ['media_fbid' => $id];
                        } else {
                            Log::warning('FB photo upload failed', ['page' => $pageId, 'resp' => $r->body()]);
                        }
                    }
                    if (empty($media)) {
                        $postData['status'] = 'fail';
                        $postData['error']  = 'No se pudieron subir las imágenes.';
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => 'No se pudieron subir las imágenes.'];
                        continue;
                    }
                    $postData['fb_media_ids'] = array_column($media, 'media_fbid');

                    // 3) Crear post con attached_media[index]
                    $payload = ['access_token' => $token];
                    if ($request->filled('message')) $payload['message'] = $request->message;
                    foreach ($media as $i => $m) $payload["attached_media[$i]"] = json_encode($m);

                    $resp = Http::asForm()->post("https://graph.facebook.com/v20.0/{$pageId}/feed", $payload);
                    $ok   = $resp->ok();
                    $body = $resp->json();

                    if ($ok) {
                        $postId    = data_get($body, 'id');
                        $permalink = $this->fetchPermalink($postId, $token);
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $postId;
                        $postData['fb_permalink_url'] = $permalink;
                        $postData['published_at'] = now();
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error']  = $resp->body();
                    }

                    MetaPost::create($postData);
                    $results[] = ['page' => $page->name, 'ok' => $ok, 'body' => $body, 'error' => $ok ? null : $resp->body()];
                } elseif ($request->type === 'video') {
                    // === VIDEO (archivo) ===
                    $file  = $request->file('video');
                    $local = $file->store('videos/' . now()->format('Y/m/d'), 'public');
                    $postData['local_media'] = [$local];

                    $abs  = storage_path('app/public/' . $local);
                    $size = @filesize($abs) ?: 0;

                    // threshold para usar chunked
                    $threshold = 8 * 1024 * 1024; // 8MB
                    $videoId = $size <= $threshold
                        ? $this->uploadVideoSimple($pageId, $token, $abs, basename($abs), $request->message)
                        : $this->uploadVideoResumable($pageId, $token, $abs, $request->message);

                    if ($videoId) {
                        $permalink = $this->fetchPermalink($videoId, $token);
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $videoId;
                        $postData['fb_media_ids'] = [$videoId];
                        $postData['fb_permalink_url'] = $permalink;
                        $postData['published_at'] = now();

                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => true, 'body' => ['video_id' => $videoId], 'error' => null];
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error']  = 'No se pudo subir el video.';
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => 'No se pudo subir el video.'];
                    }
                }
            } catch (\Throwable $e) {
                $postData['status'] = 'fail';
                $postData['error']  = $e->getMessage();
                MetaPost::create($postData);
                $results[] = ['page' => $page->name, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $fails = collect($results)->where('ok', false)->count();
        $ok    = collect($results)->where('ok', true)->count();

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
                'user_id'       => $current->id,
                'name'          => $fbUser->getName(),
                'avatar'        => $fbUser->getAvatar(),
                'access_token'  => $fbUser->token,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at'    => isset($fbUser->expiresIn) ? now()->addSeconds((int)$fbUser->expiresIn) : null,
                'raw'           => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]);
            $social = $existing;
        } else {
            $social = SocialAccount::updateOrCreate(
                [
                    'user_id'          => $current->id,
                    'provider'         => 'facebook',
                    'provider_user_id' => $fbUser->getId(),
                ],
                [
                    'name'          => $fbUser->getName(),
                    'avatar'        => $fbUser->getAvatar(),
                    'access_token'  => $fbUser->token,
                    'refresh_token' => $fbUser->refreshToken ?? null,
                    'expires_at'    => isset($fbUser->expiresIn) ? now()->addSeconds((int)$fbUser->expiresIn) : null,
                    'raw'           => method_exists($fbUser, 'user') ? $fbUser->user : null,
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
                'body'   => $resp->body()
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
                $pageId   = (string) data_get($page, 'id');
                $name     = data_get($page, 'name');
                $category = data_get($page, 'category');
                $picture  = "https://graph.facebook.com/v20.0/{$pageId}/picture?type=normal";

                $tasks = data_get($page, 'tasks', []);
                if (!is_array($tasks)) {
                    $tasks = $tasks ? [$tasks] : [];
                }

                $metaPage = \App\Models\MetaPage::updateOrCreate(
                    ['page_id' => $pageId],
                    [
                        'name'        => $name,
                        'category'    => $category,
                        'instagram_business_account_id' => data_get($page, 'connected_instagram_business_account.id'),
                        'picture_url' => $picture,
                        'tasks'       => array_values($tasks),
                    ]
                );

                $user->metaPages()->syncWithoutDetaching([
                    $metaPage->id => [
                        'page_access_token' => data_get($page, 'access_token'),
                        'social_account_id' => $social->id,
                        'expires_at'        => null,
                        'is_active'         => true,
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
        $user    = auth()->user();
        $ownerId = $request->input('owner_id');

        if ($user->isAdmin()) {
            if ($ownerId) {

                $exists = $metaPage->users()->where('users.id', $ownerId)->exists();
                if (!$exists) {
                    return back()->with('error', 'Ese propietario no está asociado a esta página.');
                }

                $metaPage->users()->updateExistingPivot($ownerId, [
                    'is_active'         => false,
                    'page_access_token' => '',
                    'expires_at'        => null,
                ]);

                return back()->with('success', "Página «{$metaPage->name}» desvinculada para el usuario seleccionado.");
            }

            $userIds = $metaPage->users()->pluck('users.id')->all();
            if (empty($userIds)) {
                return back()->with('success', "Página «{$metaPage->name}» no tenía vínculos activos.");
            }

            foreach ($userIds as $uid) {
                $metaPage->users()->updateExistingPivot($uid, [
                    'is_active'         => false,
                    'page_access_token' => '',
                    'expires_at'        => null,
                ]);
            }

            return back()->with('success', "Página «{$metaPage->name}» desvinculada para todos los usuarios.");
        }

        $exists = $user->metaPages()->where('meta_page_id', $metaPage->id)->exists();
        abort_unless($exists, 403);

        $user->metaPages()->updateExistingPivot($metaPage->id, [
            'is_active'         => false,
            'page_access_token' => '',
            'expires_at'        => null,
        ]);

        return back()->with('success', "Página «{$metaPage->name}» desvinculada.");
    }

    public function linkSinglePage(Request $request, MetaPage $metaPage)
    {
        $user    = auth()->user();
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

            $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string)$metaPage->page_id);
            if (!$found || empty($found['access_token'])) {
                return back()->with('error', 'No se encontró token para esta página. Verifica tu rol y permisos (pages_manage_posts).');
            }

            $metaPage->update([
                'name'        => $found['name'] ?? $metaPage->name,
                'category'    => $found['category'] ?? $metaPage->category,
                'picture_url' => "https://graph.facebook.com/v20.0/{$metaPage->page_id}/picture?type=normal",
                'tasks'       => is_array($found['tasks'] ?? null) ? array_values($found['tasks']) : $metaPage->tasks,
                'instagram_business_account_id' => data_get($found, 'connected_instagram_business_account.id', $metaPage->instagram_business_account_id),
            ]);


            $user->metaPages()->syncWithoutDetaching([
                $metaPage->id => [
                    'page_access_token' => $found['access_token'],
                    'social_account_id' => $social->id,
                    'expires_at'        => null,
                    'is_active'         => true,
                ]
            ]);

            return back()->with('success', "Página «{$metaPage->name}» vinculada correctamente.");
        }

        if ($ownerId) {
            $owner = User::find($ownerId);
            if (!$owner) return back()->with('error', 'El propietario especificado no existe.');
            if (!$metaPage->users()->where('users.id', $owner->id)->exists()) {
                return back()->with('error', 'Ese propietario no tiene esta página asociada.');
            }
            $social = SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();
            if (!$social) return back()->with('error', "«{$owner->name}» no tiene Facebook conectado.");
        } else {
            $owner = $metaPage->users()
                ->wherePivotNotNull('social_account_id')
                ->withPivot(['social_account_id'])
                ->first();

            if (!$owner) return back()->with('error', 'No hay un usuario propietario con Facebook conectado para esta página.');

            $social = SocialAccount::find($owner->pivot->social_account_id)
                ?: SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();

            if (!$social) return back()->with('error', 'No se encontró el token del propietario.');
        }

        $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string)$metaPage->page_id);
        if (!$found || empty($found['access_token'])) {
            return back()->with('error', 'El propietario no tiene permisos actuales sobre esta página o no hay token.');
        }

        $metaPage->update([
            'name'        => $found['name'] ?? $metaPage->name,
            'category'    => $found['category'] ?? $metaPage->category,
            'picture_url' => "https://graph.facebook.com/v20.0/{$metaPage->page_id}/picture?type=normal",
            'tasks'       => is_array($found['tasks'] ?? null) ? array_values($found['tasks']) : $metaPage->tasks,
            'instagram_business_account_id' => data_get($found, 'connected_instagram_business_account.id', $metaPage->instagram_business_account_id),
        ]);

        $metaPage->users()->updateExistingPivot($owner->id, [
            'page_access_token' => $found['access_token'],
            'social_account_id' => $social->id,
            'expires_at'        => null,
            'is_active'         => true,
        ]);

        return back()->with('success', "Página «{$metaPage->name}» vinculada usando la cuenta de «{$owner->name}».");
    }
}
