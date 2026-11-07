<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Meta\FacebookPageController;
use App\Models\SocialAccount;
use App\Services\CuentasAppService;
use App\Services\YouTubeLiveService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * Conexión de cuentas DESDE LA APP del editor (sin sesión de editus).
 *
 * La app pide al backend de esnoticia un enlace firmado (u, exp, sig) y lo abre
 * en el navegador del teléfono:
 *   GET /auth/app/facebook?u=..&exp=..&sig=..  → OAuth de Facebook → mismo callback de siempre
 *   GET /auth/app/youtube?u=..&exp=..&sig=..   → OAuth de Google   → mismo callback de siempre
 * El callback detecta en la sesión que la conexión la inició la app, guarda las
 * páginas / el canal a nombre de ese usuario (usuario_app) y muestra una página
 * que devuelve al usuario a la app (deep link editor://cuentas).
 */
class CuentasAppController extends Controller
{
    public const SESION = 'vinculo_app';

    private const SCOPES_YOUTUBE = ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'];

    public function facebook(Request $request, FacebookPageController $facebook): RedirectResponse|View
    {
        $u = CuentasAppService::usuarioFirmado($request);
        if ($u === null) return $this->resultado('facebook', false, __('El enlace para conectar venció o no es válido. Vuelve a la app e intenta de nuevo.'));
        if ((string) config('services.facebook.client_id') === '') return $this->resultado('facebook', false, __('editus no tiene configurada la app de Facebook (FACEBOOK_CLIENT_ID).'));

        // El enlace firmado se guarda para ofrecer «reintentar» si Facebook no da acceso
        $request->session()->put(self::SESION, [
            'u' => $u, 'red' => 'facebook', 'desde' => time(), 'volver' => $this->volver($request),
            'exp' => (int) $request->input('exp'), 'sig' => (string) $request->input('sig'),
        ]);
        // Basta con ser editor o administrador de la página; con «&portafolio=1», lo de siempre (con el portafolio comercial)
        return $facebook->linkRedirect($request, $request->boolean('portafolio'), 'app');
    }

    public function youtube(Request $request): RedirectResponse|View
    {
        $u = CuentasAppService::usuarioFirmado($request);
        if ($u === null) return $this->resultado('youtube', false, __('El enlace para conectar venció o no es válido. Vuelve a la app e intenta de nuevo.'));
        if ((string) config('services.google.client_id') === '') return $this->resultado('youtube', false, __('editus no tiene configurado el cliente de Google (GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET).'));

        $request->session()->put(self::SESION, ['u' => $u, 'red' => 'youtube', 'desde' => time(), 'volver' => $this->volver($request)]);
        return Socialite::driver('google')
            ->scopes(self::SCOPES_YOUTUBE)
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true'])
            ->redirectUrl((string) config('services.google.redirect'))
            ->redirect();
    }

    /**
     * Lo llama FacebookPageController::linkCallback cuando la conexión la inició la app. $portafolio: la ventana fue la
     * de «desde el portafolio». $estadoVerificado: el «state» de la ventana ya se comparó con su marca en la sesión (se
     * lee sin volver a compararlo: otra ventana abierta después pudo cambiar el de Socialite). Si algo falla, la página
     * ofrece reintentar (mientras el enlace firmado no venza).
     */
    public function callbackFacebook(Request $request, array $vinculo, string $redirectUrl, CuentasAppService $cuentas, bool $portafolio = false, bool $estadoVerificado = false): View
    {
        // Solo se borra el enlace de Facebook: si mientras tanto la app abrió YouTube, ese sigue esperando su callback
        if ((((array) $request->session()->get(self::SESION, []))['red'] ?? 'facebook') === 'facebook') $request->session()->forget(self::SESION);
        $u = (string) ($vinculo['u'] ?? '');
        if ($u === '') return $this->resultado('facebook', false, __('No se reconoció al usuario de la app. Vuelve a intentarlo desde la app.'));
        $reintentar = $this->reintentar($vinculo);

        // Facebook devolvió un error (la persona canceló, Facebook negó permisos…) o volvió sin código
        $desc = \App\Support\FacebookGraph::errorDeLaVentana($request);
        if ($desc !== null || !$request->filled('code')) {
            Log::warning('[FB] conexión desde la app: Facebook devolvió un error', ['usuario_app' => $u, 'portafolio' => $portafolio, 'query' => $request->query()]);
            return $this->resultado('facebook', false, \App\Support\FacebookGraph::ayudaPermisos($desc, $portafolio), [], $vinculo['volver'] ?? null, $reintentar);
        }

        try {
            $driver = Socialite::driver('facebook')->redirectUrl($redirectUrl);
            $fbUser = ($estadoVerificado ? $driver->stateless() : $driver)->user();
        } catch (\Throwable $e) {
            Log::error('[FB] conexión desde la app: fallo al obtener el usuario', ['err' => $e->getMessage()]);
            return $this->resultado('facebook', false, __('No se pudo completar la conexión con Facebook. Inténtalo de nuevo. Detalle: :error', ['error' => $e->getMessage()]), [], $vinculo['volver'] ?? null, $reintentar);
        }

        // Token de usuario de larga duración
        $version = config('services.facebook.version', 'v23.0');
        $userAccessToken = $fbUser->token;
        $expiresAt = isset($fbUser->expiresIn) ? now()->addSeconds((int) $fbUser->expiresIn) : null;
        try {
            $ex = Http::timeout(20)->get("https://graph.facebook.com/{$version}/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'fb_exchange_token' => $fbUser->token,
            ]);
            if ($ex->ok()) {
                $j = $ex->json();
                $userAccessToken = $j['access_token'] ?? $userAccessToken;
                $expiresAt = !empty($j['expires_in']) ? now()->addSeconds((int) $j['expires_in']) : $expiresAt;
            }
        } catch (\Throwable $e) {
            // Sin la URL: lleva el secreto de la app y el token
            Log::warning('[FB] conexión desde la app: no se pudo alargar el token', ['err' => preg_replace('/\?\S*/', '?…', $e->getMessage())]);
        }

        $social = SocialAccount::updateOrCreate(
            ['provider' => 'facebook', 'provider_user_id' => $fbUser->getId(), 'usuario_app' => $u],
            [
                'user_id' => null,
                'access_token' => $userAccessToken,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at' => $expiresAt,
                'raw' => array_merge(is_array($fbUser->user ?? null) ? $fbUser->user : [], ['name' => $fbUser->getName(), 'avatar' => $fbUser->getAvatar()]),
            ]
        );

        try {
            $n = $cuentas->sincronizarPaginas($social, null, $u);
        } catch (\Throwable $e) {
            Log::error('[FB] conexión desde la app: fallo al sincronizar páginas', ['usuario_app' => $u, 'err' => $e->getMessage()]);
            return $this->resultado('facebook', false, __('Tu cuenta quedó conectada, pero no se pudieron leer tus páginas: :error', ['error' => $e->getMessage()]), [], $vinculo['volver'] ?? null, $reintentar);
        }

        $mensaje = $n === 1 ? __('Se conectó 1 página de Facebook.') : __('Se conectaron :n páginas de Facebook.', ['n' => $n]);
        $aviso = $cuentas->avisoPermisos($social);
        // Con aviso no vuelve sola a la app: que alcance a leerlo. Si fue la ventana normal, queda a la mano la del
        // portafolio (para quien maneja una página solo desde el portafolio comercial y no le apareció)
        return $this->resultado('facebook', true, $aviso ? $mensaje . "\n\n" . $aviso : $mensaje, ['paginas' => $n], $vinculo['volver'] ?? null, $portafolio ? [] : $this->reintentar($vinculo), (bool) $aviso);
    }

    /**
     * Enlaces para volver a abrir la ventana de Facebook con el mismo enlace firmado (si aún no vence): la normal y la
     * del portafolio comercial. Vacío si no se puede.
     */
    private function reintentar(array $vinculo): array
    {
        $exp = (int) ($vinculo['exp'] ?? 0);
        if ($exp <= time() || empty($vinculo['sig']) || ($vinculo['u'] ?? '') === '') return [];
        $firma = ['u' => (string) $vinculo['u'], 'exp' => $exp, 'sig' => (string) $vinculo['sig'], 'volver' => (string) ($vinculo['volver'] ?? 'cuentas')];
        return [
            'normal' => route('app.cuentas.facebook', $firma),
            'portafolio' => route('app.cuentas.facebook', $firma + ['portafolio' => 1]),
        ];
    }

    /** Lo llama Admin\YoutubeController::callback cuando la conexión la inició la app. */
    public function callbackYoutube(Request $request, array $vinculo, YouTubeLiveService $youtube): View
    {
        $request->session()->forget(self::SESION);
        $u = (string) ($vinculo['u'] ?? '');
        if ($u === '') return $this->resultado('youtube', false, __('No se reconoció al usuario de la app. Vuelve a intentarlo desde la app.'));

        if ($request->has('error')) {
            return $this->resultado('youtube', false, __('Google no autorizó la conexión: :error', ['error' => $request->get('error_description', $request->get('error'))]), [], $vinculo['volver'] ?? null);
        }
        try {
            $g = Socialite::driver('google')->redirectUrl((string) config('services.google.redirect'))->stateless()->user();
            $canal = $youtube->registrarCanal((string) $g->token, $g->refreshToken ? (string) $g->refreshToken : null, $g->expiresIn ? (int) $g->expiresIn : null, null, $u);
        } catch (\Throwable $e) {
            Log::warning('[YouTube] conexión desde la app falló', ['usuario_app' => $u, 'err' => $e->getMessage()]);
            return $this->resultado('youtube', false, __('No se pudo conectar el canal: :error', ['error' => $e->getMessage()]), [], $vinculo['volver'] ?? null);
        }
        $mensaje = $canal->refresh_token
            ? __('Canal «:canal» conectado.', ['canal' => $canal->titulo])
            : __('Canal «:canal» conectado. Google no entregó permiso permanente: si deja de funcionar, desconéctalo y vuelve a conectarlo.', ['canal' => $canal->titulo]);
        return $this->resultado('youtube', true, $mensaje, ['canal' => $canal->titulo], $vinculo['volver'] ?? null);
    }

    /** Pantalla de la app a la que vuelve el navegador (?volver=en-vivo); por defecto "cuentas". */
    private function volver(Request $request): string
    {
        $v = strtolower(trim((string) $request->query('volver', '')));
        return preg_match('/^[a-z0-9\-\/]{1,40}$/', $v) ? $v : 'cuentas';
    }

    /**
     * $reintentar: enlaces para abrir otra vez la ventana de Facebook. Si falló, los dos (la normal y la del portafolio).
     * Si salió bien pero con aviso de permisos ($esperar), la normal, que es la que el aviso pide; si salió bien sin
     * aviso, la del portafolio (por si falta una página que solo se maneja desde ahí). $esperar: no vuelve sola a la
     * app (hay algo que leer); si falló, tampoco. Con el enlace del portafolio a la vista, vuelve sola tras unos segundos.
     */
    private function resultado(string $red, bool $ok, string $mensaje, array $extra = [], ?string $volver = null, array $reintentar = [], bool $esperar = false): View
    {
        if ($ok) $reintentar = array_intersect_key($reintentar, $esperar ? ['normal' => true] : ['portafolio' => true]);
        return view('cuentas-app.resultado', [
            'red' => $red,
            'ok' => $ok,
            'mensaje' => $mensaje,
            'volver' => CuentasAppService::urlVolverApp($volver ?: 'cuentas', ['red' => $red, 'ok' => $ok ? 1 : 0] + $extra),
            'reintentar' => $reintentar,
            'volverSola' => $ok && !$esperar,
            'espera' => $reintentar ? 5000 : 700,
        ]);
    }
}
