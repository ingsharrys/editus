<?php

namespace App\Support;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookGraph
{
    /**
     * Permisos que editus usa en las páginas de los medios, en este orden. Al conectar normal solo se piden estos (de los
     * que traiga FACEBOOK_LINK_SCOPES): nada de «business_management», «pages_manage_metadata» ni permisos de anuncios,
     * que Facebook puede negarle a quien solo es editor o administrador de la página.
     */
    public const PERMISOS_PAGINAS = [
        'email', 'public_profile', 'pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'read_insights',
        'pages_read_user_content', 'publish_video',
        'instagram_basic', 'instagram_content_publish', 'instagram_manage_comments', 'instagram_manage_insights',
    ];

    /** Sin estos no se publica ni se miden las publicaciones: si la persona los quita en la ventana de Facebook, se le avisa. */
    public const PERMISOS_ESENCIALES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'read_insights'];

    /** Cómo se le nombra cada permiso a la persona (el nombre técnico va entre paréntesis, para soporte). */
    public const NOMBRES_PERMISOS = [
        'pages_show_list' => 'ver tus páginas',
        'pages_manage_posts' => 'publicar y transmitir',
        'pages_read_engagement' => 'leer las publicaciones',
        'read_insights' => 'estadísticas y demografía',
        'pages_read_user_content' => 'leer comentarios',
        'instagram_basic' => 'Instagram',
        'instagram_content_publish' => 'publicar en Instagram',
        'instagram_manage_insights' => 'estadísticas de Instagram',
        'instagram_manage_comments' => 'comentarios de Instagram',
        'business_management' => 'portafolio comercial',
    ];

    /** «estadísticas y demografía (read_insights), publicar y transmitir (pages_manage_posts)» */
    public static function nombresPermisos(array $permisos): string
    {
        return implode(', ', array_map(fn($p) => isset(self::NOMBRES_PERMISOS[$p]) ? __(self::NOMBRES_PERMISOS[$p]) . " ({$p})" : $p, $permisos));
    }

    /**
     * Permisos para conectar páginas. Basta con ser editor o administrador de la página, así que no se pide
     * «business_management»: Facebook se lo niega a quien no está en el portafolio comercial dueño de la página
     * y entonces niega todo. Con $portafolio se pide lo de siempre (toda la lista y el portafolio), para quien
     * maneja sus páginas solo desde un portafolio comercial (Business Manager) y no le aparecen.
     */
    public static function permisosPaginas(bool $portafolio = false): array
    {
        $base = (array) config('services.facebook.link_scopes', []) ?: ['email', 'public_profile', 'pages_show_list'];
        if ($portafolio) return array_values(array_unique(array_merge(array_diff($base, ['business_management']), ['business_management'])));
        return array_values(array_unique(array_intersect($base, self::PERMISOS_PAGINAS)));
    }

    /**
     * ID de configuración de «Facebook Login for Business» que se manda en cada botón, o null para la ventana clásica
     * de Facebook con la lista de permisos de arriba. Con una configuración, Facebook pide lo que ella diga (ignora la
     * lista) y si la persona no puede conceder algo, niega todo.
     *  - Conectar mis páginas (web y app, $flujo 'link' o 'app'): FACEBOOK_PAGINAS_CONFIG_ID si existe; si no, la ventana
     *    clásica. Nunca una del portafolio (configsDePortafolio): le exigen a la persona estar en el portafolio comercial
     *    dueño de la página.
     *  - Desde el portafolio ($portafolio): FACEBOOK_PORTAFOLIO_CONFIG_ID o, si no hay, FACEBOOK_LINK_CONFIG_ID (lo de siempre).
     *  - Continuar con Facebook ($flujo 'login'): FACEBOOK_LOGIN_CONFIG_ID (con $portafolio, la del portafolio si hay).
     */
    public static function configId(string $flujo, bool $portafolio = false): ?string
    {
        $v = fn(string $clave): ?string => trim((string) config('services.facebook.' . $clave)) ?: null;
        if ($flujo === 'login') return ($portafolio ? $v('portafolio_config_id') : null) ?? $v('login_config_id');
        if ($portafolio) return self::configPortafolio();
        $paginas = $v('paginas_config_id');
        return $paginas !== null && !in_array($paginas, self::configsDePortafolio(), true) ? $paginas : null;
    }

    /** La configuración de «desde el portafolio»: FACEBOOK_PORTAFOLIO_CONFIG_ID o FACEBOOK_LINK_CONFIG_ID. */
    public static function configPortafolio(): ?string
    {
        return self::configsDePortafolio()[0] ?? null;
    }

    /**
     * Las configuraciones que piden el portafolio comercial: FACEBOOK_PORTAFOLIO_CONFIG_ID y FACEBOOK_LINK_CONFIG_ID (la que
     * usaba «Conectar mis páginas» hasta ahora). Ninguna sirve para quien solo es editor o administrador de la página.
     */
    public static function configsDePortafolio(): array
    {
        return array_values(array_unique(array_filter([
            trim((string) config('services.facebook.portafolio_config_id')),
            trim((string) config('services.facebook.link_config_id')),
        ])));
    }

    /**
     * El error con que Facebook devolvió a la persona, o null si no hay. Facebook usa ?error=…&error_description=… (p. ej.
     * cuando la persona cancela) o ?error_code=…&error_message=… (errores de la ventana, como el 1349246 «No se concedieron
     * los permisos solicitados a los siguientes activos comerciales»).
     */
    public static function errorDeLaVentana(\Illuminate\Http\Request $request): ?string
    {
        if (!$request->hasAny(['error', 'error_code'])) return null;
        $campo = fn(string $k): string => is_string($v = $request->input($k)) ? trim($v) : ''; // ?error[]=… no tumba la página
        $texto = $campo('error_description') ?: $campo('error_message') ?: $campo('error_reason') ?: $campo('error');
        $codigo = $campo('error_code');
        if ($codigo !== '' && !str_contains($texto, $codigo)) $texto = trim($texto . " (#{$codigo})");
        return $texto !== '' ? $texto : 'error';
    }

    /**
     * Qué abre cada botón (para editus:doctor): tipo de ventana, configuración y permisos que se piden.
     * @return array<string, array{config_id: ?string, permisos: ?array, portafolio: bool}> permisos null = los define la configuración en Meta
     */
    public static function flujos(): array
    {
        $flujos = [];
        foreach ([
            'Conectar mis páginas (web)' => ['link', false],
            'Conectar desde la app del editor' => ['app', false],
            'Desde el portafolio comercial (web y app)' => ['link', true],
            'Continuar con Facebook (ingreso)' => ['login', false],
        ] as $nombre => [$flujo, $portafolio]) {
            $cid = self::configId($flujo, $portafolio);
            $flujos[$nombre] = ['config_id' => $cid, 'permisos' => $cid ? null : self::permisosPaginas($portafolio), 'portafolio' => $portafolio];
        }
        return $flujos;
    }

    /**
     * Qué hacer cuando Facebook no le da acceso a editus (la persona canceló o Facebook negó permisos). El caso más común:
     * «No se concedieron los permisos solicitados a los siguientes activos comerciales»: Facebook pidió algo que esa cuenta
     * no puede conceder sobre una página o portafolio comercial que quedó elegido en la ventana de Facebook.
     * $portafolio: el error vino de la opción «desde el portafolio»; $ingreso: del ingreso con Facebook (pantalla de
     * ingreso, sin el botón «Conectar mis páginas» a la vista).
     */
    public static function ayudaPermisos(?string $detalle = null, bool $portafolio = false, bool $ingreso = false): string
    {
        $texto = match (true) {
            $ingreso => __('Facebook no le dio acceso a editus. Si viste «No se concedieron los permisos solicitados a los siguientes activos comerciales»: basta con ser editor o administrador de la página, sin pertenecer al portafolio comercial (Business Manager). Vuelve a ingresar con Facebook y, en la ventana de Facebook, marca solo tus páginas (ningún portafolio comercial) y deja activados los permisos. Si ya lo habías intentado, primero quita editus en Facebook → Configuración y privacidad → Configuración → Integraciones comerciales. También puedes ingresar con tu correo y contraseña y conectar tus páginas desde «Mis páginas».'),
            $portafolio => __('Facebook no le dio acceso a editus. La opción «desde el portafolio» solo funciona para quien administra el portafolio comercial (Business Manager) dueño de la página; si no es tu caso, Facebook responde «No se concedieron los permisos solicitados a los siguientes activos comerciales». Si eres editor o administrador de la página, usa «Conectar mis páginas»: no necesitas pertenecer al portafolio.'),
            default => __('Facebook no le dio acceso a editus. Si viste «No se concedieron los permisos solicitados a los siguientes activos comerciales»: para conectar basta con ser editor o administrador de la página, sin pertenecer al portafolio comercial (Business Manager). Vuelve a «Conectar mis páginas» y, en la ventana de Facebook, marca solo tus páginas (ningún portafolio comercial) y deja activados los permisos. Si ya lo habías intentado, primero quita editus en Facebook → Configuración y privacidad → Configuración → Integraciones comerciales. La opción «desde el portafolio» es solo para quien administra el portafolio dueño de la página.'),
        };
        $detalle = trim((string) $detalle);
        return $detalle !== '' ? $texto . ' ' . __('(Detalle de Facebook: :d)', ['d' => \Illuminate\Support\Str::limit($detalle, 200)]) : $texto;
    }

    /**
     * Busca una página por ID en /me/accounts usando el token de usuario.
     * Recorre paginación (limit=200 + cursor "after"). Si no está ahí (Facebook no lista en /me/accounts las páginas de
     * un portafolio comercial al que la persona no pertenece), la lee directamente: trae token si la persona tiene un
     * rol en la página.
     */
    public function getPageDataFromMeAccounts(string $userAccessToken, string $targetPageId): ?array
    {
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';
        $params = ['fields' => $fields, 'limit' => 200];

        while (true) {
            $resp = Http::withToken($userAccessToken)
                ->get(self::url('me/accounts'), $params); // <— usa url()

            if (!$resp->ok()) {
                Log::warning('FB /me/accounts error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'trace' => $resp->header('x-fb-trace-id'),
                ]);
                break;
            }

            $json = $resp->json();
            $pages = $json['data'] ?? [];
            $found = collect($pages)->firstWhere('id', (string) $targetPageId);
            if ($found) {
                return $found;
            }

            $after = data_get($json, 'paging.cursors.after');
            if (!$after)
                break;
            $params['after'] = $after;
        }

        foreach (['id,name,category,access_token,connected_instagram_business_account', 'id,name,category,access_token'] as $campos) {
            $resp = Http::withToken($userAccessToken)->get(self::url((string) $targetPageId), ['fields' => $campos]);
            if ($resp->ok()) return data_get($resp->json(), 'access_token') ? (array) $resp->json() : null;
        }
        Log::warning('FB página leída directamente: error', ['page_id' => $targetPageId, 'status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300)]);
        return null;
    }

    public static function url(string $path, bool $video = false): string
    {
        $v = config('services.facebook.version', 'v23.0');
        $host = $video ? 'https://graph-video.facebook.com' : 'https://graph.facebook.com';
        return "{$host}/{$v}/{$path}";
    }
}
