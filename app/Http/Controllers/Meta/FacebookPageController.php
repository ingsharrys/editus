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
use App\Jobs\PublishToInstagram;
use App\Jobs\RefreshMetaPermalink;
use Illuminate\Support\Facades\Cache;

class FacebookPageController extends Controller
{
    protected FacebookGraph $fb;

    public function __construct(FacebookGraph $fb)
    {
        $this->fb = $fb;
    }
    // app/Http/Controllers/FacebookPageController.php

    public function show(MetaPage $facebook_page)
    {
        $user = Auth::user();
        $facebook_page->load('users'); // para revisar pivots/permiso

        $isAdmin = $user->isAdmin();
        $myPivot = $facebook_page->users->firstWhere('id', $user->id)?->pivot;

        $canView = $isAdmin || ($myPivot && $myPivot->is_active && !empty($myPivot->page_access_token));
        abort_unless($canView, 403, __('No tienes permiso para ver esta página.'));

        // Publicaciones de esta página (agregamos fb_permalink_url y link)
        $posts = \App\Models\MetaPost::where('meta_page_id', $facebook_page->id)
            ->latest('published_at')
            ->get([
                'id',
                'type',            // 👈 necesario
                'fb_post_id',      // 👈 necesario
                'message',
                'published_at',
                'alcance',
                'visualizaciones',
                'interacciones',
                'fb_permalink_url',
                'link',
            ]);

        // Totales
        $totals = [
            'publicaciones' => $posts->count(),
            'alcance' => (int) $posts->sum('alcance'),
            'visualizaciones' => (int) $posts->sum('visualizaciones'),
            'interacciones' => (int) $posts->sum('interacciones'),
        ];

        return view('meta.pages.show', [
            'page' => $facebook_page,
            'posts' => $posts,
            'totals' => $totals,
        ]);
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

            $pages = $query->latest()->get();
        } else {
            $owners = collect();
            $pages = $user->metaPages()
                ->with('users')
                ->latest()
                ->get();
        }

        // IDs favoritos del usuario autenticado
        $favIds = DB::table('meta_page_favorites')
            ->where('user_id', $user->id)
            ->pluck('meta_page_id')
            ->all();

        // Campañas seleccionables al publicar (activas y no de sistema) y las páginas de sus medios,
        // que se marcan solas al elegir la campaña (el usuario puede desmarcar las que quiera excluir)
        $campaigns = \App\Models\Campaign::selectable()->with('perfil.paginas')->get();
        $campaignPages = app(\App\Services\CampanasService::class)->mapaPaginas($campaigns);
        // Campañas con plan de medios: subcampañas, tipos de pieza y cupos por página (para el certificado y las métricas)
        $campaignPiezas = [];
        if (\App\Services\PlanMediosService::disponible()) {
            $plan = app(\App\Services\PlanMediosService::class);
            foreach ($campaigns->where('plan_medios', true) as $c) {
                $campaignPiezas[$c->id] = [
                    'productos' => [], // cada publicación es una pieza nueva (se crea sola al publicar)
                    'subs' => $c->subcampanas()->where('is_active', true)->get()->map(fn($x) => ['id' => $x->id, 'nombre' => $x->nombre, 'tipos' => $plan->tiposDe($c, $x)])->values()->all(),
                    'tipos' => $plan->tipos($c),
                    'cupos' => $plan->cuposParaPublicar($c),
                ];
            }
        }
        // Publicar desde una subcampaña: la campaña y la subcampaña quedan fijas y solo salen las páginas de la campaña
        $fijo = $request->attributes->get('plan_fijo');
        if ($fijo) {
            $idsCampana = $campaignPages[$fijo['campana']->id] ?? [];
            $pages = $pages->whereIn('id', $idsCampana)->values();
        }
        $planes = app(\App\Services\PlanService::class);
        $esCliente = !$planes->sinLimites($user);
        $planInfo = $esCliente ? ['limites' => $planes->limites($user), 'permitidas' => $planes->paginasPermitidas($user)->pluck('id')->map(fn($v) => (int) $v)->all()] : null;
        if ($esCliente) $campaigns = collect();
        return view('meta.pages.index', compact('pages', 'owners', 'ownerId', 'favIds', 'campaigns', 'campaignPages', 'campaignPiezas', 'esCliente', 'planInfo', 'fijo'));
    }
    public function saveFavorites(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'page_ids' => ['array'],
            'page_ids.*' => [Rule::exists('meta_pages', 'id')],
        ]);

        $pageIds = $data['page_ids'] ?? [];

        \DB::transaction(function () use ($user, $pageIds) {
            \DB::table('meta_page_favorites')->where('user_id', $user->id)->delete();

            if (!empty($pageIds)) {
                $rows = array_map(fn($id) => [
                    'user_id' => $user->id,
                    'meta_page_id' => $id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $pageIds);
                \DB::table('meta_page_favorites')->insert($rows);
            }
        });

        return response()->json(['ok' => true, 'fav_ids' => $pageIds]);
    }

    public function toggleFavorite(Request $request)
    {
        $data = $request->validate([
            'page_id' => ['required', 'exists:meta_pages,id'],
            'favorite' => ['required', 'boolean'],
        ]);

        $user = Auth::user();

        if ($data['favorite']) {
            $user->favoritePages()->syncWithoutDetaching([$data['page_id']]);
        } else {
            $user->favoritePages()->detach($data['page_id']);
        }

        return response()->json(['ok' => true]);
    }





    /** Programa, subprograma, tipo de pieza y subcampaña elegidos al publicar (solo si hay campaña). */
    private function piezaDe(Request $request): array
    {
        if (!$request->filled('campaign_id')) return [];
        $v = fn($k, $max) => ($t = trim((string) $request->input($k, ''))) !== '' ? mb_substr($t, 0, $max) : null;
        return array_filter([
            'programa' => $v('programa', 190), 'subprograma' => $v('subprograma', 190), 'tipo_pieza' => $v('tipo_pieza', 60),
            'subcampana_id' => $request->filled('subcampana_id') && \App\Services\PlanMediosService::disponible() ? (int) $request->input('subcampana_id') : null,
            'producto_id' => $request->filled('producto_id') && \App\Services\ProductosService::disponible() ? (int) $request->input('producto_id') : null,
        ], fn($x) => $x !== null);
    }

    /**
     * En campañas con plan de medios hay que decir en qué subcampaña (tema) y de qué tipo de publicación es.
     * Cada publicación queda como una pieza nueva del informe (se crea al terminar de publicar). Ninguna página
     * puede pasarse de su cupo de ese tipo. Deja en la petición los datos normalizados. Devuelve los errores o null.
     */
    private function validarPlanDeMedios(Request $request): ?array
    {
        if (!\App\Services\PlanMediosService::disponible()) return null;
        $c = \App\Models\Campaign::find((int) $request->input('campaign_id'));
        if (!$c || !$c->plan_medios) {
            $request->request->remove('subcampana_id');
            $request->request->remove('producto_id');
            return null;
        }
        $plan = app(\App\Services\PlanMediosService::class);
        $subs = $c->subcampanas()->where('is_active', true)->get();
        $sub = $request->filled('subcampana_id') ? $subs->firstWhere('id', (int) $request->input('subcampana_id')) : null;
        if ($subs->isNotEmpty() && !$sub) return ['subcampana_id' => __('Elige la subcampaña de esta publicación.')];
        $tipo = null;
        foreach ($plan->tiposDe($c, $sub) as $t) if (\App\Services\PlanMediosService::clave($t) === \App\Services\PlanMediosService::clave($request->input('tipo_pieza'))) $tipo = $t;
        if (!$tipo) return ['tipo_pieza' => __('Elige el tipo de pieza de esta publicación.')];
        $excedidos = $plan->excedidos($c, (array) $request->input('page_ids', []), $tipo);
        if ($excedidos) {
            $lista = collect($excedidos)->map(fn($e) => __(':pagina (:usados de :cupo)', $e))->implode(', ');
            return ['page_ids' => __('Estas páginas ya completaron su cupo de «:tipo»: :lista. Quítalas de la publicación o aumenta el cupo.', ['tipo' => $tipo, 'lista' => $lista])];
        }
        $request->request->remove('producto_id');
        $request->merge(['tipo_pieza' => $tipo] + $plan->camposDe($sub, $tipo) + ['subcampana_id' => null]);
        return null;
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

    /**
     * Vincular/desvincular una página con un medio de esnoticia.
     * Cuando el sistema de noticias publica un artículo de ese medio,
     * lo replica automáticamente en esta página (FB + IG conectado).
     */
    public function updateMedio(Request $request, MetaPage $metaPage)
    {
        $medios = array_keys(config('services.editus.medios', []));

        $request->validate([
            'medio_slug' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_merge([''], $medios))],
        ]);

        $slug = $request->input('medio_slug') ?: null;
        $metaPage->update(['medio_slug' => $slug]);
        app(\App\Services\CampanasService::class)->sincronizarTodas();

        $nombreMedio = $slug ? (config('services.editus.medios')[$slug] ?? $slug) : null;

        return back()->with('success', $slug
            ? __('«:pagina» quedó vinculada al medio :medio: los artículos de ese medio se publicarán aquí automáticamente.', ['pagina' => $metaPage->name, 'medio' => $nombreMedio])
            : __('«:pagina» quedó sin medio: ya no recibirá publicaciones automáticas.', ['pagina' => $metaPage->name]));
    }

    public function publish(Request $request)
    {
        // Diagnóstico: registra qué llega realmente en cada intento de publicación.
        // Revisar con: tail -50 storage/logs/laravel.log
        Log::info('[FB] publish payload', [
            'user_id' => auth()->id(),
            'campos_recibidos' => array_keys($request->all()),
            'type' => $request->input('type'),
            'page_ids' => $request->input('page_ids'),
            'tiene_fotos' => $request->hasFile('photos'),
            'tiene_video' => $request->hasFile('video'),
            'content_length' => $request->server('CONTENT_LENGTH'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ]);

        // 0) Plan del cliente (el equipo interno y los administradores no tienen límites)
        $planes = app(\App\Services\PlanService::class);
        $esCliente = !$planes->sinLimites(auth()->user());
        if ($esCliente && ($motivo = $planes->validarPaginas(auth()->user(), (array) $request->input('page_ids', [])))) {
            return back()->withErrors(['page_ids' => $motivo])->withInput();
        }

        // 1) Validación base
        $request->validate([
            'campaign_id' => [
                $esCliente ? 'nullable' : 'required',
                Rule::exists('campaigns', 'id')->where('is_active', 1)->where('is_system', 0),
            ],
            'type' => ['required', 'in:text,photo,video'],
            'page_ids' => ['required', 'array', 'min:1'],
            'page_ids.*' => [Rule::exists('meta_pages', 'id')],
            'message' => ['nullable', 'string', 'max:63206'],
            'link' => ['nullable', 'url'],
            // Pieza de la campaña (llena la tabla del certificado de cada medio)
            'programa' => ['nullable', 'string', 'max:190'],
            'subprograma' => ['nullable', 'string', 'max:190'],
            'tipo_pieza' => ['nullable', 'string', 'max:60'],
            'subcampana_id' => ['nullable', 'integer'],
            'producto_id' => ['nullable', 'integer'],

            'photos' => ['nullable', 'array', 'max:50'],
            'photos.*' => ['file', 'image', 'max:10240'], // 10MB

            // Estricto: MP4/MOV
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime', 'mimes:mp4,mov', 'max:1024000'],
        ]);

        // Plan de medios: subcampaña y tipo de pieza obligatorios, y cupos de piezas por página
        if ($request->filled('campaign_id') && ($error = $this->validarPlanDeMedios($request))) {
            return back()->withErrors($error)->withInput();
        }

        // Redes destino: facebook (por defecto) y/o instagram
        $networks = array_values(array_intersect(
            (array) $request->input('networks', ['facebook']),
            ['facebook', 'instagram']
        ));
        if (empty($networks)) {
            $networks = ['facebook'];
        }

        // Instagram no acepta publicaciones de solo texto
        if ($request->type === 'text' && !in_array('facebook', $networks, true)) {
            return back()
                ->withErrors(['networks' => __('Instagram no permite publicaciones de solo texto. Selecciona Facebook o agrega una foto/video.')])
                ->withInput();
        }

        if ($request->type === 'text') {
            $request->validate(['message' => ['required', 'string', 'max:63206']]);
        } elseif ($request->type === 'photo') {
            if (!$request->hasFile('photos')) {
                return back()->withErrors(['photos' => __('Selecciona al menos una imagen.')])->withInput();
            }
        } elseif ($request->type === 'video') {
            if (!$request->hasFile('video')) {
                return back()->withErrors(['video' => __('Selecciona un video.')])->withInput();
            }
        }

        // 3) Páginas
        $pages = MetaPage::whereIn('id', $request->page_ids)
            ->with(['users' => fn($q) => $q->wherePivot('is_active', true)])
            ->get();

        if ($pages->isEmpty()) {
            return back()->withErrors(['page_ids' => __('No se encontraron páginas válidas.')]);
        }

        $results = [];
        $batch = (string) Str::uuid();

        foreach ($pages as $page) {
            $token = $this->resolvePublishToken($page);
            if (!$token) {
                $results[] = [
                    'page' => $page->name,
                    'ok' => false,
                    'error' => 'Sin token activo (y el Usuario de Sistema tampoco tiene acceso a esta página). El administrador de la página en Facebook debe conectar su cuenta y sincronizar, o agrega la página al Business Manager.',
                ];
                continue;
            }

            $pageId = $page->page_id;

            $doFb = in_array('facebook', $networks, true);
            $doIg = in_array('instagram', $networks, true) && $request->type !== 'text';

            // ===== INSTAGRAM (foto/video, en cola) =====
            if ($doIg) {
                $this->queueInstagramPost($request, $page, $token, $batch, $results);
                if (!$doFb) {
                    continue;
                }
            }

            $postData = [
                'batch_uuid' => $batch,
                'user_id' => auth()->id(),
                'campaign_id' => $request->filled('campaign_id') ? (int) $request->campaign_id : null,
                'meta_page_id' => $page->id,
                ...$this->piezaDe($request),
                'type' => $request->type,
                'network' => 'facebook',
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

                    $resp = Http::asForm()->timeout(20)->connectTimeout(8)
                        ->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);

                    $ok = $resp->ok();
                    $body = $resp->json();

                    // Token guardado vencido (190): reintento único con token
                    // fresco derivado del Usuario de Sistema (Business Manager).
                    if (!$ok && (int) data_get($body, 'error.code') === 190) {
                        $fresh = $this->resolvePublishToken($page, forceFresh: true);
                        if ($fresh && $fresh !== $token) {
                            $token = $fresh;
                            $payload['access_token'] = $fresh;
                            $resp = Http::asForm()->timeout(20)->connectTimeout(8)
                                ->post("https://graph.facebook.com/v23.0/{$pageId}/feed", $payload);
                            $ok = $resp->ok();
                            $body = $resp->json();
                        }
                    }

                    if ($ok) {
                        $postId = data_get($body, 'id');
                        $postData['status'] = 'success';
                        $postData['fb_post_id'] = $postId;
                        $postData['published_at'] = now();
                    } else {
                        $postData['status'] = 'fail';
                        $postData['error'] = $resp->body();
                    }

                    $metaPost = MetaPost::create($postData);

                    // El permalink se resuelve en segundo plano: hacerlo aquí
                    // bloqueaba el request con hasta 3 llamadas HTTP por página.
                    if ($ok) {
                        RefreshMetaPermalink::dispatch([
                            'meta_post_id' => $metaPost->id,
                            'page_token' => $token,
                        ])->onQueue('default')->delay(now()->addSeconds(20));
                    }
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
                            $postData['error'] = $res['error'] ?? __('No se pudo guardar una imagen en público.');
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
                        $postData['error'] = __('No se pudo preparar ninguna imagen.');
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
                            UPLOAD_ERR_INI_SIZE => __('El archivo supera upload_max_filesize (php.ini).'),
                            UPLOAD_ERR_FORM_SIZE => __('El archivo supera MAX_FILE_SIZE (formulario).'),
                            UPLOAD_ERR_PARTIAL => __('El archivo se subió parcialmente.'),
                            UPLOAD_ERR_NO_FILE => __('No se subió ningún archivo.'),
                            UPLOAD_ERR_NO_TMP_DIR => __('Falta upload_tmp_dir.'),
                            UPLOAD_ERR_CANT_WRITE => __('No se pudo escribir en disco.'),
                            UPLOAD_ERR_EXTENSION => __('Una extensión detuvo la subida.'),
                            default => __('Fallo de subida (código :codigo).', ['codigo' => $code]),
                        };

                        $postData['status'] = 'fail';
                        $postData['error'] = __('Error de subida: :detalle', ['detalle' => $msg]);
                        MetaPost::create($postData);
                        $results[] = ['page' => $page->name, 'ok' => false, 'error' => $postData['error']];
                        continue;
                    }

                    // 1) Guardar a URL pública temporal
                    $pub = $this->storeVideoPublicTmp($file);
                    if (!($pub['ok'] ?? false)) {
                        $postData['status'] = 'fail';
                        $postData['error'] = $pub['error'] ?? __('No se pudo guardar el video en público.');
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

        // Plan de medios: el lote publicado queda como una pieza del informe (con la primera foto como muestra)
        if ($request->filled('campaign_id') && \App\Services\ProductosService::disponible()) {
            try {
                $camp = \App\Models\Campaign::find((int) $request->campaign_id);
                if ($camp?->plan_medios) {
                    $prod = app(\App\Services\ProductosService::class);
                    $prod->asegurarPiezas($camp);
                    $pieza = \App\Models\Producto::whereIn('id', MetaPost::where('batch_uuid', $batch)->whereNotNull('producto_id')->select('producto_id'))->first();
                    $foto = $request->file('photos')[0] ?? null;
                    if ($pieza && !$pieza->muestra_path && $foto instanceof \Illuminate\Http\UploadedFile && $foto->isValid()) $prod->guardarMuestra($pieza, $foto);
                }
            } catch (\Throwable $e) {
                Log::warning('[plan de medios] no se pudo crear la pieza del lote', ['batch' => $batch, 'error' => $e->getMessage()]);
            }
        }

        $mensaje = match (true) {
            $published > 0 && $queued > 0 => __('Listo: publicados :publicados y en cola :cola. Fallidos: :fallidos.', ['publicados' => $published, 'cola' => $queued, 'fallidos' => $fails]),
            $published > 0 => __('Listo: publicados :publicados. Fallidos: :fallidos.', ['publicados' => $published, 'fallidos' => $fails]),
            $queued > 0 => __('Tus videos quedaron en cola (:cola). Fallidos: :fallidos.', ['cola' => $queued, 'fallidos' => $fails]),
            default => __('No se pudo procesar nada. Revisa los detalles.'),
        };

        return back()
            ->with('success', $mensaje)
            ->with('publish_results', $results);
    }



    /**
     * Token para publicar en una página: usa la pivote activa más reciente;
     * si no hay token (o con forceFresh, p. ej. tras un error 190), lo deriva
     * del Usuario de Sistema de Business Manager y lo persiste en la pivote
     * del admin actual para los próximos envíos.
     */
    private function resolvePublishToken(MetaPage $page, bool $forceFresh = false): ?string
    {
        if (!$forceFresh) {
            // El token activo MÁS RECIENTE de cualquier usuario (no el primero
            // por orden de inserción, que suele ser el más viejo y vencido).
            $token = DB::table('meta_page_user')
                ->where('meta_page_id', $page->id)
                ->where('is_active', 1)
                ->whereNotNull('page_access_token')
                ->where('page_access_token', '!=', '')
                ->orderByDesc('updated_at')
                ->value('page_access_token');

            if ($token) {
                return $token;
            }
        }

        $sys = config('services.facebook.system_user_token');
        if (!$sys) {
            return null;
        }

        $fresh = $this->derivePageToken((string) $page->page_id, $sys);
        if (!$fresh) {
            return null;
        }

        try {
            auth()->user()->metaPages()->syncWithoutDetaching([
                $page->id => [
                    'page_access_token' => $fresh,
                    'is_active' => 1,
                    'updated_at' => now(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::debug('[publish] no se pudo persistir token fresco', ['page_id' => $page->id, 'err' => $e->getMessage()]);
        }

        Log::info('[publish] token fresco derivado del Usuario de Sistema', ['meta_page_id' => $page->id]);

        return $fresh;
    }

    /**
     * Crea el MetaPost de Instagram y despacha el job de publicación.
     * Instagram exige medios (foto/video) con URL pública, e imágenes JPEG.
     */
    private function queueInstagramPost(Request $request, MetaPage $page, string $token, string $batch, array &$results): void
    {
        $label = $page->name . ' (Instagram)';

        if (empty($page->instagram_business_account_id)) {
            $results[] = ['page' => $label, 'ok' => false, 'error' => __('La página no tiene Instagram Business conectado. Sincroniza para detectarlo.')];
            return;
        }

        $postData = [
            'batch_uuid' => $batch,
            'user_id' => auth()->id(),
            'campaign_id' => $request->filled('campaign_id') ? (int) $request->campaign_id : null,
            'meta_page_id' => $page->id,
            ...$this->piezaDe($request),
            'type' => $request->type,
            'network' => 'instagram',
            'message' => $request->message,
            'status' => 'pending',
        ];

        try {
            if ($request->type === 'photo') {
                $urls = [];
                $rels = [];
                $abss = [];

                foreach ($request->file('photos', []) as $file) {
                    $res = $this->storePhotoForInstagramTmp($file);
                    if (!($res['ok'] ?? false)) {
                        $results[] = ['page' => $label, 'ok' => false, 'error' => $res['error'] ?? __('No se pudo preparar una imagen para Instagram.')];
                        return;
                    }
                    $urls[] = $res['url'];
                    $rels[] = $res['cleanup_rel'] ?? null;
                    $abss[] = $res['cleanup_abs'] ?? null;
                }

                if (empty($urls)) {
                    $results[] = ['page' => $label, 'ok' => false, 'error' => __('No se pudo preparar ninguna imagen para Instagram.')];
                    return;
                }

                $postData['local_media'] = json_encode(['photo_urls' => $urls]);
                $metaPost = MetaPost::create($postData);

                PublishToInstagram::dispatch([
                    'meta_post_id' => $metaPost->id,
                    'ig_user_id' => (string) $page->instagram_business_account_id,
                    'page_token' => $token,
                    'kind' => 'photos',
                    'photo_urls' => $urls,
                    'caption' => $request->message,
                    'cleanup_rel' => $rels,
                    'cleanup_abs' => $abss,
                ])->onQueue('default');

                $results[] = ['page' => $label, 'ok' => true, 'body' => ['queued' => true, 'meta_post_id' => $metaPost->id]];
                return;
            }

            if ($request->type === 'video') {
                // Copia propia del video para IG (los jobs limpian sus archivos al terminar)
                $pub = $this->storeVideoPublicTmp($request->file('video'));
                if (!($pub['ok'] ?? false)) {
                    $results[] = ['page' => $label, 'ok' => false, 'error' => $pub['error'] ?? __('No se pudo preparar el video para Instagram.')];
                    return;
                }

                $postData['local_media'] = json_encode(['public_url' => $pub['url']]);
                $metaPost = MetaPost::create($postData);

                PublishToInstagram::dispatch([
                    'meta_post_id' => $metaPost->id,
                    'ig_user_id' => (string) $page->instagram_business_account_id,
                    'page_token' => $token,
                    'kind' => 'video',
                    'video_url' => $pub['url'],
                    'caption' => $request->message,
                    'cleanup_rel' => array_filter([$pub['cleanup_rel'] ?? null]),
                    'cleanup_abs' => array_filter([$pub['cleanup_abs'] ?? null]),
                ])->onQueue('default');

                $results[] = ['page' => $label, 'ok' => true, 'body' => ['queued' => true, 'meta_post_id' => $metaPost->id]];
                return;
            }
        } catch (\Throwable $e) {
            Log::error('[IG] queueInstagramPost: exception', ['err' => $e->getMessage()]);
            $postData['status'] = 'fail';
            $postData['error'] = $e->getMessage();
            MetaPost::create($postData);
            $results[] = ['page' => $label, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Guarda una foto como JPEG público temporal (Instagram solo acepta JPEG).
     */
    private function storePhotoForInstagramTmp(\Illuminate\Http\UploadedFile $file): array
    {
        try {
            $name = (string) \Illuminate\Support\Str::uuid() . '.jpg';
            $path = 'images/tmp/' . $name;
            $disk = \Illuminate\Support\Facades\Storage::disk('public');

            if (!$disk->exists('images/tmp')) {
                $disk->makeDirectory('images/tmp');
            }

            // Convertir a JPEG con Intervention (ya instalado en el proyecto)
            $jpeg = \Intervention\Image\Laravel\Facades\Image::read($file->getRealPath())
                ->toJpeg(quality: 88);

            if (!$disk->put($path, (string) $jpeg)) {
                return ['ok' => false, 'error' => __('No se pudo escribir la imagen JPEG para Instagram.')];
            }

            return [
                'ok' => true,
                'url' => url(\Illuminate\Support\Facades\Storage::url($path)),
                'cleanup_rel' => 'public/' . $path,
                'cleanup_abs' => null,
            ];
        } catch (\Throwable $e) {
            // Fallback: usa el almacenamiento normal (funciona si la foto ya es JPEG)
            $res = $this->storePhotoPublicTmp($file);
            if (($res['ok'] ?? false) && in_array(strtolower($file->getClientOriginalExtension()), ['jpg', 'jpeg'], true)) {
                return $res;
            }
            return ['ok' => false, 'error' => __('No se pudo convertir la imagen a JPEG: :detalle', ['detalle' => $e->getMessage()])];
        }
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
            return ['ok' => false, 'error' => __('El archivo llegó con tamaño 0 (parcial o bloqueado).')];
        }

        // Extensión normalizada (mp4/mov)
        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        if (!in_array($ext, ['mp4', 'mov', 'm4v'], true)) {
            // Acepta extensiones típicas; fuerza mp4 si no se detecta algo válido
            $ext = 'mp4';
        }

        $name = (string) \Illuminate\Support\Str::uuid() . '.' . $ext;

        // 1) Intentar disco "public" (storage/app/public → public/storage)
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');

            // Comprobar/crear carpeta
            if (!$disk->exists('videos/tmp')) {
                $disk->makeDirectory('videos/tmp');
            }

            // Guardar usando putFileAs (maneja streams del UploadedFile)
            $path = 'videos/tmp/' . $name;
            $saved = $disk->putFileAs('videos/tmp', $file, $name);

            if (!$saved) {
                return ['ok' => false, 'error' => __('Falló escribir en storage/app/public/videos/tmp.')];
            }

            // Asegurar visibilidad pública (evita 403 desde Facebook)
            $disk->setVisibility($path, 'public');

            // Paths y URL estables
            $abs = storage_path('app/public/' . $path);
            if (!file_exists($abs)) {
                return ['ok' => false, 'error' => __('No se encontró el archivo guardado en storage (permisos).')];
            }

            // Construir URL estable sin depender de asset()/APP_URL
            $url = url(\Illuminate\Support\Facades\Storage::url($path));

            return [
                'ok' => true,
                'url' => $url,
                'rel' => 'public/' . $path, // para Storage::delete()
                'abs' => $abs,
                'cleanup_rel' => 'public/' . $path,
                'cleanup_abs' => null,
                // Opcional: info útil para logs/diagnóstico en el Job
                'mime' => $file->getMimeType(),
                'size' => $size,
            ];
        } catch (\Throwable $e) {
            // sigue a fallback
        }

        // 2) Fallback a public/uploads/tmp
        $dir = public_path('uploads/tmp');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => __('No se pudo crear public/uploads/tmp (permisos).')];
        }
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => __('public/uploads/tmp no es escribible (permisos).')];
        }

        $abs = $dir . '/' . $name;
        try {
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('No se pudo mover a public/uploads/tmp: :detalle', ['detalle' => $e->getMessage()])];
        }

        if (!file_exists($abs)) {
            return ['ok' => false, 'error' => __('El archivo no quedó en public/uploads/tmp.')];
        }

        $url = url('uploads/tmp/' . $name);

        return [
            'ok' => true,
            'url' => $url,
            'rel' => null,
            'abs' => $abs,
            'cleanup_rel' => null,
            'cleanup_abs' => $abs,
            // Opcional: info
            'mime' => mime_content_type($abs) ?: null,
            'size' => filesize($abs) ?: $size,
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
            return ['ok' => false, 'error' => __('El archivo llegó con tamaño 0 (parcial/bloqueado).')];
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
                return ['ok' => false, 'error' => __('No se pudo abrir el archivo temporal del upload.')];
            }

            $path = 'images/tmp/' . $name;
            $saved = \Illuminate\Support\Facades\Storage::disk('public')->put($path, $stream);
            if (is_resource($stream))
                fclose($stream);

            if (!$saved)
                return ['ok' => false, 'error' => __('Falló escribir en storage/app/public/images/tmp.')];

            $abs = storage_path('app/public/' . $path);
            if (!file_exists($abs))
                return ['ok' => false, 'error' => __('No se encontró el archivo guardado en storage.')];

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
            return ['ok' => false, 'error' => __('No se pudo crear public/uploads/tmp.')];
        }
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => __('public/uploads/tmp no es escribible.')];
        }

        $abs = $dir . '/' . $name;
        try {
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('No se pudo mover a public/uploads/tmp: :detalle', ['detalle' => $e->getMessage()])];
        }

        if (!file_exists($abs))
            return ['ok' => false, 'error' => __('El archivo no quedó en public/uploads/tmp.')];

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
        $redirectUrl = config('services.facebook.link_redirect') ?: route('facebook.link.callback');
        $params = ['auth_type' => 'rerequest'];

        if ($cid = config('services.facebook.link_config_id')) {
            $params['config_id'] = $cid;
            $params['response_type'] = 'code';
            $params['override_default_response_type'] = 'true';

            return Socialite::driver('facebook')
                ->with($params)
                ->redirectUrl($redirectUrl)
                ->redirect();
        }

        $scopes = config('services.facebook.link_scopes', []);

        return Socialite::driver('facebook')
            ->scopes($scopes)
            ->with($params)
            ->redirectUrl($redirectUrl)
            ->redirect();
    }

    public function linkCallback(Request $request)
    {
        $redirectUrl = config('services.facebook.link_redirect') ?: route('facebook.link.callback');

        // Conexión iniciada desde la app del editor (sin sesión de editus): la resuelve CuentasAppController
        if ($vinculo = $request->session()->get(\App\Http\Controllers\Web\CuentasAppController::SESION)) {
            return app(\App\Http\Controllers\Web\CuentasAppController::class)->callbackFacebook($request, (array) $vinculo, $redirectUrl, app(\App\Services\CuentasAppService::class));
        }
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', __('Inicia sesión en editus para conectar páginas.'));
        }

        // Si Facebook devolvió un error (usuario canceló, permisos denegados, etc.)
        if ($request->has('error')) {
            $desc = $request->get('error_description')
                ?: $request->get('error_reason')
                ?: $request->get('error');

            Log::warning('[FB] linkCallback: error de Facebook', ['query' => $request->query()]);

            return redirect()->route('meta.pages.index')
                ->with('error', __('Facebook devolvió un error: :detalle', ['detalle' => $desc]));
        }

        // Si no te falla state, puedes dejar ->user() normal.
        // Si te ha fallado con "Invalid state", usa stateless.
        try {
            $fbUser = Socialite::driver('facebook')
                ->redirectUrl($redirectUrl)
                ->stateless()
                ->user();
        } catch (\Throwable $e) {
            Log::error('[FB] linkCallback: fallo al obtener el usuario de Facebook', [
                'err' => $e->getMessage(),
                'redirect_url' => $redirectUrl,
            ]);

            return redirect()->route('meta.pages.index')
                ->with('error', __('No se pudo completar la conexión con Facebook. Verifica que la URL de callback esté registrada en la app de Meta e inténtalo de nuevo. Detalle: :detalle', ['detalle' => $e->getMessage()]));
        }

        // Intercambia por token long-lived (recomendado)
        $version = config('services.facebook.version', 'v23.0');
        $ex = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $fbUser->token,
        ]);

        $userAccessToken = $fbUser->token;
        $expiresAt = isset($fbUser->expiresIn) ? now()->addSeconds((int) $fbUser->expiresIn) : null;

        if ($ex->ok()) {
            $j = $ex->json();
            $userAccessToken = $j['access_token'] ?? $userAccessToken;
            $expiresAt = !empty($j['expires_in']) ? now()->addSeconds((int) $j['expires_in']) : $expiresAt;
        }

        $current = Auth::user();

        $social = SocialAccount::updateOrCreate(
            [
                'user_id' => $current->id,
                'provider' => 'facebook',
                'provider_user_id' => $fbUser->getId(),
            ],
            [
                'name' => $fbUser->getName(),
                'avatar' => $fbUser->getAvatar(),
                'access_token' => $userAccessToken, // <-- long-lived si se pudo
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at' => $expiresAt,
                'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]
        );

        try {
            $count = $this->performSync($current, $social);
        } catch (\Throwable $e) {
            Log::error('[FB] linkCallback: fallo en performSync', ['err' => $e->getMessage()]);

            return redirect()
                ->route('meta.pages.index')
                ->with('error', __('Cuenta conectada, pero la sincronización de páginas falló: :detalle', ['detalle' => $e->getMessage()]));
        }

        return redirect()
            ->route('meta.pages.index')
            ->with('success', __('Páginas sincronizadas: :n', ['n' => $count]));
    }


    public function sync(Request $request)
    {
        $user = Auth::user();

        $social = SocialAccount::where('user_id', $user->id)
            ->where('provider', 'facebook')
            ->first();

        if (!$social) {
            return redirect()->route('facebook.connect')
                ->with('info', __('Primero conecta tu cuenta de Facebook para poder sincronizar páginas.'));
        }

        try {
            $count = $this->performSync($user, $social);
        } catch (\Throwable $e) {
            Log::error('[FB] sync: fallo en performSync', [
                'user_id' => $user->id,
                'err' => $e->getMessage(),
            ]);

            if ($this->isExpiredTokenError($e->getMessage())) {
                return redirect()->route('facebook.connect')
                    ->with('error', __('Tu sesión de Facebook expiró. Vuelve a conectar tu cuenta.'));
            }

            return back()->with('error', __('No se pudieron sincronizar las páginas: :detalle', ['detalle' => $e->getMessage()]));
        }

        return back()->with('success', __('Páginas sincronizadas: :n', ['n' => $count]));
    }

    /**
     * Detecta si el error de Graph corresponde a un token de usuario
     * expirado/invalidado (OAuthException código 190).
     */
    private function isExpiredTokenError(string $message): bool
    {
        return str_contains($message, '"code":190')
            || str_contains($message, 'Session has expired')
            || str_contains($message, 'The session has been invalidated')
            || str_contains($message, 'Error validating access token');
    }

    private function performSync(User $user, SocialAccount $social): int
    {
        // Misma lógica para la web y para las cuentas conectadas desde la app (CuentasAppService)
        return app(\App\Services\CuentasAppService::class)->sincronizarPaginas($social, $user->id, null);
    }

    public function startRepairTokens(Request $request)
    {
        $user = Auth::user();
        $isAdmin = (int) ($user->role_id ?? 0) === 1;

        // Universo: pivots del usuario (o todos si admin)
        $pivots = DB::table('meta_page_user')
            ->join('meta_pages', 'meta_pages.id', '=', 'meta_page_user.meta_page_id')
            ->select([
                'meta_page_user.id as pivot_id',
                'meta_page_user.meta_page_id',
                'meta_page_user.user_id',
                'meta_page_user.social_account_id',
                'meta_page_user.page_access_token',
                'meta_page_user.is_active',
                'meta_pages.page_id as fb_page_id',
            ])
            ->when(!$isAdmin, fn($q) => $q->where('meta_page_user.user_id', $user->id))
            ->orderBy('meta_page_user.id')
            ->get();

        if ($pivots->isEmpty()) {
            return response()->json(['ok' => false, 'error' => __('No hay páginas vinculadas.')], 404);
        }

        // Guardar ids en cache como "cola" simple
        $batchKey = "repairTokens:{$user->id}:state";
        $listKey = "repairTokens:{$user->id}:list";

        $list = $pivots->map(fn($p) => [
            'pivot_id' => (int) $p->pivot_id,
            'meta_page_id' => (int) $p->meta_page_id,
            'user_id' => (int) $p->user_id,
            'social_account_id' => (int) ($p->social_account_id ?? 0),
            'fb_page_id' => (string) $p->fb_page_id,
        ])->values()->all();

        $state = [
            'total' => count($list),
            'done' => 0,
            'fixed' => 0,
            'kept' => 0,
            'errors' => 0,
            'cursor' => 0,
            'finished' => count($list) === 0,
            'started_at' => now()->toIso8601String(),
            'finished_at' => count($list) === 0 ? now()->toIso8601String() : null,
            'is_admin' => $isAdmin,
        ];

        Cache::put($batchKey, $state, now()->addHours(2));
        Cache::put($listKey, $list, now()->addHours(2));

        return response()->json(['ok' => true, 'state' => $state]);
    }
    public function repairTokensStep(Request $request)
    {
        $user = Auth::user();
        $isAdmin = (int) ($user->role_id ?? 0) === 1;
        $limit = max(1, min((int) $request->input('limit', 25), 100));

        $batchKey = "repairTokens:{$user->id}:state";
        $listKey = "repairTokens:{$user->id}:list";

        $state = Cache::get($batchKey);
        $list = Cache::get($listKey);

        if (!$state || !$list) {
            return response()->json(['ok' => false, 'error' => __('No hay sesión de reparación inicializada.')], 400);
        }

        if (!empty($state['finished'])) {
            return response()->json(['ok' => true, 'state' => $state, 'done' => true]);
        }

        $cursor = (int) $state['cursor'];
        $slice = array_slice($list, $cursor, $limit);

        if (empty($slice)) {
            $state['finished'] = true;
            $state['finished_at'] = now()->toIso8601String();
            Cache::put($batchKey, $state, now()->addHours(2));
            return response()->json(['ok' => true, 'state' => $state, 'done' => true]);
        }

        foreach ($slice as $item) {
            try {
                // 1) Cargar pivot + social account
                $pivot = DB::table('meta_page_user')->where('id', $item['pivot_id'])->first();
                if (!$pivot) {
                    $state['errors']++;
                    $state['done']++;
                    continue;
                }

                $pageId = $item['fb_page_id'];
                $pageTok = $pivot->page_access_token ?: null;

                // 2) Si el token actual sirve (read_insights), lo dejamos
                if ($pageTok && $this->hasReadInsights($pageId, $pageTok)) {
                    $state['kept']++;
                    $state['done']++;
                    continue;
                }

                // 3) Intentar derivar desde SocialAccount del pivot
                $newTok = null;
                if ($item['social_account_id']) {
                    $sa = SocialAccount::find($item['social_account_id']);
                    $userToken = $sa?->access_token ?: null;
                    if ($userToken) {
                        $newTok = $this->derivePageToken($pageId, $userToken);
                    }
                }

                // 4) Intentar derivar desde SYSTEM USER (opcional)
                if (!$newTok) {
                    $sysTok = config('services.facebook.system_user_token') ?? env('FACEBOOK_SYSTEM_USER_TOKEN');
                    if ($sysTok) {
                        $newTok = $this->derivePageToken($pageId, $sysTok);
                    }
                }

                // 5) Validar y persistir en pivot
                if ($newTok && $this->hasReadInsights($pageId, $newTok)) {
                    DB::table('meta_page_user')->where('id', $item['pivot_id'])->update([
                        'page_access_token' => $newTok,
                        'is_active' => 1,
                        'updated_at' => now(),
                    ]);
                    $state['fixed']++;
                } else {
                    // Si no logramos uno válido, marcar inactivo para que lo atiendan luego
                    DB::table('meta_page_user')->where('id', $item['pivot_id'])->update([
                        'is_active' => 0,
                        'updated_at' => now(),
                    ]);
                    $state['errors']++;
                }

                $state['done']++;
            } catch (\Throwable $e) {
                Log::warning('[repairTokens] step.error', ['pivot_id' => $item['pivot_id'], 'err' => $e->getMessage()]);
                $state['errors']++;
                $state['done']++;
            }
        }

        $state['cursor'] = $cursor + count($slice);
        if ($state['done'] >= (int) $state['total']) {
            $state['finished'] = true;
            $state['finished_at'] = now()->toIso8601String();
        }

        Cache::put($batchKey, $state, now()->addHours(2));

        return response()->json(['ok' => true, 'state' => $state]);
    }

    // ==== Helpers privados en el mismo controller ====

    private function derivePageToken(string $pageId, string $userOrSystemToken): ?string
    {
        try {
            $r = Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                ->withToken($userOrSystemToken)
                ->get("https://graph.facebook.com/v23.0/{$pageId}", ['fields' => 'access_token']);
            return $r->ok() ? (data_get($r->json(), 'access_token') ?: null) : null;
        } catch (\Throwable $e) {
            Log::debug('[repairTokens] derive.error', ['page_id' => $pageId, 'err' => $e->getMessage()]);
            return null;
        }
    }

    private function hasReadInsights(string $pageId, string $pageToken): bool
    {
        try {
            $r = Http::timeout(20)->connectTimeout(8)->retry(1, 600)
                ->withToken($pageToken)
                ->get("https://graph.facebook.com/v23.0/{$pageId}/insights", [
                    'metric' => 'page_impressions',
                    'period' => 'day',
                ]);

            if ($r->ok())
                return true;

            $msg = (string) (data_get($r->json(), 'error.message') ?? $r->body());
            // si falla por otra causa distinta a read_insights, no lo bloqueamos
            return !str_contains($msg, 'read_insights');
        } catch (\Throwable $e) {
            return false;
        }
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

        return back()->with('success', __('Facebook desvinculado de tu cuenta y tokens limpiados.'));
    }

    public function unlinkPage(Request $request, MetaPage $metaPage)
    {
        $user = auth()->user();
        $ownerId = $request->input('owner_id');

        if ($user->isAdmin()) {
            if ($ownerId) {

                $exists = $metaPage->users()->where('users.id', $ownerId)->exists();
                if (!$exists) {
                    return back()->with('error', __('Ese propietario no está asociado a esta página.'));
                }

                $metaPage->users()->updateExistingPivot($ownerId, [
                    'is_active' => false,
                    'page_access_token' => '',
                    'expires_at' => null,
                ]);

                return back()->with('success', __('Página «:pagina» desvinculada para el usuario seleccionado.', ['pagina' => $metaPage->name]));
            }

            $userIds = $metaPage->users()->pluck('users.id')->all();
            if (empty($userIds)) {
                return back()->with('success', __('Página «:pagina» no tenía vínculos activos.', ['pagina' => $metaPage->name]));
            }

            foreach ($userIds as $uid) {
                $metaPage->users()->updateExistingPivot($uid, [
                    'is_active' => false,
                    'page_access_token' => '',
                    'expires_at' => null,
                ]);
            }

            return back()->with('success', __('Página «:pagina» desvinculada para todos los usuarios.', ['pagina' => $metaPage->name]));
        }

        $exists = $user->metaPages()->where('meta_page_id', $metaPage->id)->exists();
        abort_unless($exists, 403);

        $user->metaPages()->updateExistingPivot($metaPage->id, [
            'is_active' => false,
            'page_access_token' => '',
            'expires_at' => null,
        ]);

        return back()->with('success', __('Página «:pagina» desvinculada.', ['pagina' => $metaPage->name]));
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
                return redirect()->route('facebook.connect')
                    ->with('info', __('Conecta tu Facebook y vuelve a intentar.'));
            }

            $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string) $metaPage->page_id);
            if (!$found || empty($found['access_token'])) {
                return back()->with('error', __('No se encontró token para esta página. Verifica tu rol y permisos (pages_manage_posts).'));
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

            return back()->with('success', __('Página «:pagina» vinculada correctamente.', ['pagina' => $metaPage->name]));
        }

        if ($ownerId) {
            $owner = User::find($ownerId);
            if (!$owner)
                return back()->with('error', __('El propietario especificado no existe.'));
            if (!$metaPage->users()->where('users.id', $owner->id)->exists()) {
                return back()->with('error', __('Ese propietario no tiene esta página asociada.'));
            }
            $social = SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();
            if (!$social)
                return back()->with('error', __('«:usuario» no tiene Facebook conectado.', ['usuario' => $owner->name]));
        } else {
            $owner = $metaPage->users()
                ->wherePivotNotNull('social_account_id')
                ->withPivot(['social_account_id'])
                ->first();

            if (!$owner)
                return back()->with('error', __('No hay un usuario propietario con Facebook conectado para esta página.'));

            $social = SocialAccount::find($owner->pivot->social_account_id)
                ?: SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();

            if (!$social)
                return back()->with('error', __('No se encontró el token del propietario.'));
        }

        $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string) $metaPage->page_id);
        if (!$found || empty($found['access_token'])) {
            return back()->with('error', __('El propietario no tiene permisos actuales sobre esta página o no hay token.'));
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

        return back()->with('success', __('Página «:pagina» vinculada usando la cuenta de «:usuario».', ['pagina' => $metaPage->name, 'usuario' => $owner->name]));
    }
}
