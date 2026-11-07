<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\MetaPageUser;
use App\Models\SocialAccount;
use App\Models\YoutubeCanal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas conectadas por los usuarios de la app del editor.
 *
 * Cada usuario de la app (id del backend de esnoticia = usuario_app) vincula
 * sus propias páginas de Facebook y canales de YouTube con un enlace firmado
 * que abre el navegador; editus guarda los tokens con su usuario_app y la
 * app solo ve (y transmite a) lo suyo más lo que la organización conectó
 * desde la web de editus (usuario_app NULL, marcado como visible en la app).
 */
class CuentasAppService
{
    /** Vigencia máxima (segundos) del enlace firmado para abrir la conexión. */
    public const ENLACE_VIGENCIA = 900;

    /**
     * Verifica la firma del backend: sig = HMAC-SHA256("{u}|{exp}", EDITUS_INGEST_TOKEN).
     * Devuelve el usuario de la app o null si la firma no sirve o venció.
     */
    public static function usuarioFirmado(Request $request): ?string
    {
        $u = trim((string) $request->input('u', ''));
        $exp = (int) $request->input('exp', 0);
        $sig = (string) $request->input('sig', '');
        $token = (string) config('services.editus.ingest_token');
        if ($token === '' || $u === '' || $exp <= 0 || $sig === '' || $exp < time()) return null;
        if (!hash_equals(hash_hmac('sha256', "{$u}|{$exp}", $token), $sig)) return null;
        return $u;
    }

    /** Esquema de la app (deep link) al que vuelve el navegador al terminar. */
    public static function urlVolverApp(string $destino = 'cuentas', array $datos = []): string
    {
        $esquema = trim((string) config('services.editor_app.scheme', 'editor')) ?: 'editor';
        return $esquema . '://' . ltrim($destino, '/') . ($datos ? '?' . http_build_query($datos) : '');
    }

    // ------------------------------------------------------------------
    // Qué ve cada usuario
    // ------------------------------------------------------------------

    /**
     * Páginas de Facebook disponibles para un usuario de la app: las suyas
     * (conectadas desde la app) más las de la organización visibles en el editor.
     * Sin usuario → solo las de la organización (comportamiento anterior).
     */
    public function paginasDe(?string $usuarioApp, ?string $usuarioNombre = null): Collection
    {
        $conUsuarioApp = Schema::hasColumn('meta_page_user', 'usuario_app');
        $visible = Schema::hasColumn('meta_pages', 'visible_en_editor');
        $activo = fn($q) => $q->where('is_active', 1)->whereNotNull('page_access_token');

        $q = MetaPage::query()->where(function ($w) use ($usuarioApp, $conUsuarioApp, $visible, $activo) {
            // De la organización (visible en la app)
            $w->where(function ($o) use ($conUsuarioApp, $visible, $activo) {
                if ($visible) $o->where('visible_en_editor', 1);
                $o->whereHas('vinculos', function ($v) use ($conUsuarioApp, $activo) {
                    $activo($v);
                    if ($conUsuarioApp) $v->whereNull('usuario_app');
                });
            });
            // Las propias del usuario
            if ($usuarioApp !== null && $usuarioApp !== '' && $conUsuarioApp) {
                $w->orWhereHas('vinculos', fn($v) => $activo($v)->where('usuario_app', $usuarioApp));
            }
        });

        $propias = ($usuarioApp !== null && $usuarioApp !== '' && $conUsuarioApp)
            ? MetaPageUser::where('usuario_app', $usuarioApp)->where('is_active', 1)->whereNotNull('page_access_token')->pluck('meta_page_id')->flip()
            : collect();

        $enApp = $usuarioApp !== null && $usuarioApp !== '';
        return $q->orderBy('name')->get()
            ->each(fn(MetaPage $p) => $p->setAttribute('propia', $propias->has($p->id)))
            // Las de la organización pueden estar limitadas a ciertos usuarios de la app (Admin → App del editor)
            ->filter(fn(MetaPage $p) => $p->getAttribute('propia') || !$enApp || $p->visibleParaUsuarioApp($usuarioNombre))
            ->values();
    }

    /** Ids (page_id de Facebook) accesibles para el usuario. */
    public function puedeUsarPaginas(?string $usuarioApp, array $pageIds, ?string $usuarioNombre = null): bool
    {
        if ($usuarioApp === null || $usuarioApp === '') return true;
        $permitidas = $this->paginasDe($usuarioApp, $usuarioNombre)->pluck('page_id')->map(fn($x) => (string) $x)->flip();
        foreach ($pageIds as $id) {
            if (!$permitidas->has((string) $id)) return false;
        }
        return true;
    }

    /** Canales de YouTube disponibles: los propios más los de la organización visibles. */
    public function canalesDe(?string $usuarioApp, ?string $usuarioNombre = null): Collection
    {
        if (!Schema::hasTable('youtube_canales')) return collect();
        $conUsuarioApp = Schema::hasColumn('youtube_canales', 'usuario_app');
        $enApp = $usuarioApp !== null && $usuarioApp !== '';
        return YoutubeCanal::query()->where(function ($w) use ($usuarioApp, $conUsuarioApp) {
            $w->where(function ($o) use ($conUsuarioApp) {
                $o->where('visible_en_editor', true);
                if ($conUsuarioApp) $o->whereNull('usuario_app');
            });
            if ($usuarioApp !== null && $usuarioApp !== '' && $conUsuarioApp) {
                $w->orWhere('usuario_app', $usuarioApp);
            }
        })->orderBy('titulo')->get()
            ->filter(fn(YoutubeCanal $c) => !$enApp || (string) $c->usuario_app === $usuarioApp || $c->visibleParaUsuarioApp($usuarioNombre))
            ->values();
    }

    public function puedeUsarCanales(?string $usuarioApp, array $canalIds, ?string $usuarioNombre = null): bool
    {
        if ($usuarioApp === null || $usuarioApp === '' || !$canalIds) return true;
        $permitidos = $this->canalesDe($usuarioApp, $usuarioNombre)->pluck('id')->flip();
        foreach ($canalIds as $id) {
            if (!$permitidos->has((int) $id)) return false;
        }
        return true;
    }

    /** Resumen de las cuentas del usuario para la pantalla "Mis cuentas" de la app. */
    public function resumen(string $usuarioApp, ?string $usuarioNombre = null): array
    {
        $social = Schema::hasColumn('social_accounts', 'usuario_app')
            ? SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->latest('updated_at')->first()
            : null;
        $paginas = $this->paginasDe($usuarioApp, $usuarioNombre)->map(fn(MetaPage $p) => [
            'id' => $p->id,
            'page_id' => (string) $p->page_id,
            'nombre' => (string) $p->name,
            'foto' => $p->pictureUrl('small'),
            'instagram' => !empty($p->instagram_business_account_id),
            'propia' => (bool) $p->getAttribute('propia'),
        ])->values();
        $canales = $this->canalesDe($usuarioApp, $usuarioNombre)->map(fn(YoutubeCanal $c) => $c->paraApi($usuarioApp))->values();

        return [
            'facebook' => [
                'configurado' => (string) config('services.facebook.client_id') !== '',
                'conectada' => (bool) $social,
                'nombre' => $social?->name,
                'foto' => $social?->avatar,
                'conectada_en' => $social?->updated_at?->toIso8601String(),
                'paginas' => $paginas,
            ],
            'youtube' => [
                'configurado' => (string) config('services.google.client_id') !== '',
                'canales' => $canales,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Facebook: sincronizar páginas de una cuenta conectada
    // ------------------------------------------------------------------

    /**
     * Lee las páginas que administra la cuenta de Facebook y guarda el token
     * de cada una (para un usuario de editus o para un usuario de la app).
     * Devuelve cuántas páginas quedaron vinculadas.
     */
    public function sincronizarPaginas(SocialAccount $social, ?int $userId, ?string $usuarioApp = null): int
    {
        $version = config('services.facebook.version', 'v23.0');
        $base = "https://graph.facebook.com/{$version}";
        // Sin el campo de Instagram: para tokens que aún no tienen instagram_basic,
        // Facebook rechaza la llamada completa si se pide ese campo.
        $fieldsFull = 'id,name,category,access_token,tasks,instagram_business_account,picture{url}';
        $fieldsBasic = 'id,name,category,access_token,tasks';
        $http = Http::withToken($social->access_token)->timeout(30)->connectTimeout(10);

        // 1) Páginas en las que la persona tiene un rol (/me/accounts)
        [$pages, $err] = $this->leerEdge($http, $base, 'accounts', [$fieldsFull, $fieldsBasic]);
        if ($err !== null) {
            Log::error('FB /me/accounts error', ['body' => $err]);
            // Con el código de Graph (190 = sesión de Facebook vencida: hay que volver a conectar)
            throw new \RuntimeException(__('No se pudieron obtener las páginas: :detalle', ['detalle' => $this->mensajeGraph($err)]), (int) data_get(json_decode($err, true), 'error.code'));
        }
        // 2) Las que eligió en la ventana de Facebook y /me/accounts no trae (p. ej. de un portafolio comercial al que no pertenece)
        $pages = array_merge($pages, $this->paginasElegidasQueFaltan($base, $social, array_map(fn($p) => (string) data_get($p, 'id'), $pages)));
        // 3) Asignadas desde un portafolio comercial (/me/assigned_pages; solo con business_management)
        if (empty($pages)) {
            [$pages, $err] = $this->leerEdge($http, $base, 'assigned_pages', [$fieldsFull, $fieldsBasic]);
            if ($err !== null) Log::warning('FB /me/assigned_pages error', ['body' => $err]);
        }
        if (empty($pages)) {
            throw new \RuntimeException(__("No se encontraron páginas.
- Vuelve a «Conectar mis páginas» y, en la ventana de Facebook, marca tus páginas una por una y deja activados los permisos. Basta con ser editor o administrador de la página: no hace falta pertenecer al portafolio comercial.
- Revisa que tu perfil tenga «acceso a Facebook» en la página (en la página: Configuración → Acceso a la página).
- Si ya lo habías intentado, quita editus en Facebook → Configuración y privacidad → Configuración → Integraciones comerciales y conecta otra vez.
- La opción «desde el portafolio» es solo para quien administra el portafolio comercial dueño de la página."));
        }

        // 4) Guardado página por página: un fallo en una no bloquea a las demás
        $guardadas = 0;
        foreach ($pages as $page) {
            try {
                $metaPageId = DB::transaction(function () use ($page, $userId, $usuarioApp, $social, $base, $http) {
                    $pageId = (string) data_get($page, 'id');
                    $tasks = data_get($page, 'tasks', []);
                    if (!is_array($tasks)) $tasks = $tasks ? [$tasks] : [];

                    $pageAccessToken = data_get($page, 'access_token');
                    if (!$pageAccessToken) {
                        $try = $http->get("{$base}/{$pageId}", ['fields' => 'access_token']);
                        if ($try->ok()) $pageAccessToken = data_get($try->json(), 'access_token');
                        else Log::warning('No page_access_token (fallback)', $try->json() ?? []);
                    }

                    // La página es de todos los que la conectan: el Instagram se guarda si viene, pero nunca se borra (esta
                    // conexión puede no incluir Instagram); las tareas solo se cambian si Facebook las mandó
                    $metaPage = MetaPage::firstOrNew(['page_id' => $pageId]);
                    $metaPage->fill([
                        'name' => data_get($page, 'name') ?? $metaPage->name,
                        'category' => data_get($page, 'category') ?? $metaPage->category,
                        'picture_url' => "{$base}/{$pageId}/picture?type=normal",
                    ]);
                    if ($ig = data_get($page, 'instagram_business_account.id') ?: data_get($page, 'connected_instagram_business_account.id')) {
                        $metaPage->instagram_business_account_id = (string) $ig;
                    }
                    if (array_key_exists('tasks', (array) $page) || !$metaPage->exists) $metaPage->tasks = array_values($tasks);
                    $metaPage->save();

                    $clave = ['meta_page_id' => $metaPage->id, 'user_id' => $userId];
                    if (Schema::hasColumn('meta_page_user', 'usuario_app')) $clave['usuario_app'] = $usuarioApp;
                    $vinculo = MetaPageUser::query()->where($clave)->first() ?: new MetaPageUser($clave);
                    $vinculo->fill([
                        'page_access_token' => $pageAccessToken,
                        'social_account_id' => $social->id,
                        'expires_at' => null,
                        'is_active' => $pageAccessToken ? 1 : 0,
                    ]);
                    $vinculo->save();

                    if (!$pageAccessToken) {
                        Log::warning('Sin token de página: revisar rol/permisos del usuario en la página', ['page_id' => $pageId, 'tasks' => $tasks]);
                    } elseif (!in_array('ANALYZE', $tasks, true)) {
                        Log::info('Token OK pero tasks sin ANALYZE (insights pueden fallar)', ['page_id' => $pageId, 'tasks' => $tasks]);
                    }
                    return $metaPage->id;
                });
                $guardadas++;
                $this->cederElTurno($social, (int) $metaPageId, $userId, $usuarioApp);
            } catch (\Throwable $e) {
                Log::warning('[sync] página no guardada', ['page' => data_get($page, 'id'), 'name' => data_get($page, 'name'), 'err' => $e->getMessage()]);
            }
        }

        return $guardadas;
    }

    /**
     * Publicar y medir usan la conexión activa más reciente de la página, sea de quien sea. Si a esta conexión le faltan
     * permisos esenciales (los quitó en la ventana de Facebook):
     *  - si la página tiene otra activa que sí funciona (token válido y con esos permisos, de otra persona), esta queda
     *    justo detrás de esa: sigue activa (la persona no pierde su página), pero no la desplaza;
     *  - si estaba detrás y la otra ya no funciona, vuelve adelante (algo puede hacer; la otra, nada).
     * Si no se puede comprobar la otra (Facebook no responde), no se cambia nada.
     */
    public function cederElTurno(SocialAccount $social, int $metaPageId, ?int $userId, ?string $usuarioApp): void
    {
        if (!$this->permisosFaltantes($social)) return;
        $conApp = Schema::hasColumn('meta_page_user', 'usuario_app');
        $activas = MetaPageUser::query()->where('meta_page_id', $metaPageId)->where('is_active', 1)
            ->whereNotNull('page_access_token')->where('page_access_token', '!=', '')
            ->orderByDesc('updated_at')->get();
        $esDeEsta = fn(MetaPageUser $v) => ($v->user_id === null ? $userId === null : (int) $v->user_id === (int) $userId)
            && (!$conApp || (string) ($v->usuario_app ?? '') === (string) ($usuarioApp ?? ''));
        $propia = $activas->first($esDeEsta);
        $otra = $activas->first(fn(MetaPageUser $v) => !$esDeEsta($v));
        if (!$propia || !$otra || !$otra->updated_at) return;

        $detras = $propia->updated_at && $propia->updated_at->lt($otra->updated_at);
        $otraSirve = $this->conexionCompleta((string) $otra->page_access_token);
        if ($otraSirve === null || $otraSirve === $detras) return;

        $hora = $otraSirve ? $otra->updated_at->copy()->subSecond() : now();
        DB::table('meta_page_user')->where('id', $propia->getAttribute('id'))->update(['updated_at' => $hora]);
        Log::info($otraSirve ? '[sync] conexión sin permisos esenciales: queda detrás de la que funciona' : '[sync] conexión sin permisos esenciales: vuelve adelante porque la otra ya no funciona',
            ['meta_page_id' => $metaPageId, 'faltan' => $this->permisosFaltantes($social)]);
    }

    /** ¿El token (de usuario o de página) es válido y tiene los permisos esenciales? null si Facebook no lo dice. */
    private function conexionCompleta(string $token): ?bool
    {
        $info = $this->infoToken($token);
        if (!$info || !array_key_exists('is_valid', $info)) return null;
        return !empty($info['is_valid']) && !array_diff($this->esencialesPedidos(), (array) ($info['scopes'] ?? []));
    }

    /** Los permisos esenciales que editus pide (los de FacebookGraph::PERMISOS_ESENCIALES que estén en la lista configurada). */
    private function esencialesPedidos(): array
    {
        return array_values(array_intersect(\App\Support\FacebookGraph::PERMISOS_ESENCIALES, \App\Support\FacebookGraph::permisosPaginas(true)));
    }

    /** Máximo de páginas que se leen una por una en cada sincronización. */
    private const MAX_PAGINAS_ELEGIDAS = 50;

    /**
     * Páginas que la persona eligió en la ventana de Facebook pero /me/accounts no devuelve (Facebook no lista ahí las
     * páginas de un portafolio comercial al que la persona no pertenece, aunque sea su editora o administradora).
     * Se sacan del token (debug_token → granular_scopes de los permisos de páginas) y se leen una por una: Facebook
     * entrega el token de la página a quien tenga un rol en ella.
     */
    private function paginasElegidasQueFaltan(string $base, SocialAccount $social, array $yaEstan): array
    {
        $ids = [];
        foreach ((array) data_get($this->infoToken((string) $social->access_token), 'granular_scopes', []) as $g) {
            if (!str_starts_with((string) data_get($g, 'scope'), 'pages_')) continue;
            foreach ((array) data_get($g, 'target_ids', []) as $id) $ids[] = (string) $id;
        }
        $faltan = array_values(array_diff(array_unique(array_filter($ids)), $yaEstan));
        if (count($faltan) > self::MAX_PAGINAS_ELEGIDAS) {
            Log::warning('[sync] demasiadas páginas elegidas fuera de /me/accounts: solo se leen las primeras', ['total' => count($faltan), 'leidas' => self::MAX_PAGINAS_ELEGIDAS]);
            $faltan = array_slice($faltan, 0, self::MAX_PAGINAS_ELEGIDAS);
        }
        if (!$faltan) return [];

        // Es un extra: con esperas cortas y sin tumbar la sincronización (las de /me/accounts se guardan igual)
        $http = Http::withToken($social->access_token)->timeout(15)->connectTimeout(8);
        $paginas = [];
        foreach ($faltan as $id) {
            try {
                foreach (['id,name,category,access_token,instagram_business_account', 'id,name,category,access_token'] as $i => $campos) {
                    $r = $http->get("{$base}/{$id}", ['fields' => $campos]);
                    if ($r->ok()) {
                        if (data_get($r->json(), 'access_token')) $paginas[] = (array) $r->json();
                        else Log::info('[sync] página elegida sin token: la persona no tiene un rol en ella', ['page_id' => $id]);
                        break;
                    }
                    if ($i === 0 && $this->esErrorDeCampo($r->body())) continue;
                    Log::warning('[sync] no se pudo leer una página elegida', ['page_id' => $id, 'body' => mb_substr($r->body(), 0, 300)]);
                    break;
                }
            } catch (\Throwable $e) {
                // Facebook no responde: no se sigue esperando página por página
                Log::warning('[sync] se dejaron de leer las páginas elegidas: Facebook no respondió', ['page_id' => $id, 'err' => preg_replace('/\?\S*/', '?…', $e->getMessage())]);
                break;
            }
        }
        if ($paginas) Log::info('[sync] páginas leídas por fuera de /me/accounts', ['ids' => array_column($paginas, 'id')]);
        return $paginas;
    }

    /** Lo que Facebook dice del token de usuario (debug_token): permisos concedidos y a qué páginas aplica cada uno. */
    private array $infoTokens = [];

    private function infoToken(string $token): ?array
    {
        $clave = hash('sha256', $token);
        if (array_key_exists($clave, $this->infoTokens)) return $this->infoTokens[$clave];
        $id = (string) config('services.facebook.client_id');
        $secreto = (string) config('services.facebook.client_secret');
        if ($token === '' || $id === '' || $secreto === '') return $this->infoTokens[$clave] = null;

        $version = config('services.facebook.version', 'v23.0');
        try {
            $r = Http::timeout(15)->connectTimeout(8)->get("https://graph.facebook.com/{$version}/debug_token", ['input_token' => $token, 'access_token' => "{$id}|{$secreto}"]);
            $datos = $r->ok() ? (array) data_get($r->json(), 'data', []) : null;
            if ($datos === null) Log::info('[FB] debug_token no respondió', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
        } catch (\Throwable $e) {
            // Sin la URL completa: lleva el token de la persona y el secreto de la app
            Log::info('[FB] debug_token falló', ['err' => preg_replace('/\?\S*/', '?…', $e->getMessage())]);
            $datos = null;
        }
        return $this->infoTokens[$clave] = $datos;
    }

    /**
     * Permisos sin los que no se publica o no se mide (FacebookGraph::PERMISOS_ESENCIALES) que la persona no concedió
     * al conectar (en la ventana clásica de Facebook se pueden desactivar). Vacío si no se sabe.
     */
    public function permisosFaltantes(SocialAccount $social): array
    {
        $info = $this->infoToken((string) $social->access_token);
        if (!$info || !isset($info['scopes'])) return [];
        return array_values(array_diff($this->esencialesPedidos(), (array) $info['scopes']));
    }

    /** Aviso para la persona si quitó permisos esenciales en la ventana de Facebook (null si no falta ninguno). */
    public function avisoPermisos(SocialAccount $social): ?string
    {
        $faltan = $this->permisosFaltantes($social);
        if (!$faltan) return null;
        return __('En la ventana de Facebook quedaron desactivados permisos que editus necesita: :permisos. Sin ellos no se puede publicar o medir en tus páginas. Vuelve a «Conectar mis páginas» y deja activados todos los permisos.', ['permisos' => \App\Support\FacebookGraph::nombresPermisos($faltan)]);
    }

    /**
     * Recorre un edge paginado de Graph. Si Facebook rechaza los campos completos
     * (error #100 por campo/permiso), reintenta con los básicos.
     * @return array{0: array, 1: ?string} [páginas, error]
     */
    private function leerEdge($http, string $base, string $edge, array $intentos): array
    {
        foreach ($intentos as $i => $fields) {
            $rows = [];
            $url = "{$base}/me/{$edge}?fields={$fields}&limit=100";
            $fallo = null;
            while ($url) {
                $resp = $http->get($url);
                if (!$resp->ok()) { $fallo = $resp->body(); break; }
                $json = $resp->json();
                $rows = array_merge($rows, (array) data_get($json, 'data', []));
                $url = data_get($json, 'paging.next');
            }
            if ($fallo === null) return [$rows, null];

            if ($i < count($intentos) - 1 && $this->esErrorDeCampo($fallo)) {
                Log::info('[sync] campos completos rechazados, reintentando con básicos', ['edge' => $edge]);
                continue;
            }
            return [[], $fallo];
        }
        return [[], null];
    }

    /** ¿Facebook rechazó un campo pedido (#100, o el de Instagram sin instagram_basic)? */
    private function esErrorDeCampo(string $cuerpo): bool
    {
        return (int) data_get(json_decode($cuerpo, true), 'error.code') === 100 || str_contains($cuerpo, 'nonexisting field') || str_contains($cuerpo, 'instagram');
    }

    /** Mensaje legible de un error de la Graph API: «Permisos insuficientes (#200)». */
    private function mensajeGraph(string $body): string
    {
        $e = (array) data_get(json_decode($body, true), 'error', []);
        $msg = trim((string) ($e['error_user_msg'] ?? $e['message'] ?? ''));
        if ($msg === '') return mb_substr(trim(strip_tags($body)), 0, 300) ?: __('error desconocido');
        return $msg . (isset($e['code']) ? " (#{$e['code']})" : '');
    }

    /** Aviso de permisos de la cuenta de Facebook del usuario de la app (null si no falta ninguno). */
    public function avisoPermisosDeLaApp(string $usuarioApp): ?string
    {
        $social = SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->latest('updated_at')->first();
        return $social ? $this->avisoPermisos($social) : null;
    }

    /** Vuelve a leer las páginas de la cuenta de Facebook del usuario de la app. */
    public function resincronizarFacebook(string $usuarioApp): int
    {
        $social = SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->latest('updated_at')->first();
        if (!$social) throw new \RuntimeException('Primero conecta tu cuenta de Facebook desde la app.');
        return $this->sincronizarPaginas($social, null, $usuarioApp);
    }

    /** Borra los tokens y la cuenta de Facebook del usuario de la app. */
    public function desconectarFacebook(string $usuarioApp): int
    {
        return DB::transaction(function () use ($usuarioApp) {
            $n = MetaPageUser::where('usuario_app', $usuarioApp)->delete();
            SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->delete();
            return $n;
        });
    }

    /** Desconecta un canal de YouTube solo si lo conectó ese usuario. */
    public function desconectarCanal(YoutubeCanal $canal, string $usuarioApp): void
    {
        if ((string) $canal->usuario_app !== $usuarioApp) {
            throw new \RuntimeException('Ese canal lo conectó la organización: solo se puede desconectar desde la web de editus.');
        }
        $canal->delete();
    }
}
