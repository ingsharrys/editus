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
        if ($u === null) return $this->resultado('facebook', false, 'El enlace para conectar venció o no es válido. Vuelve a la app e intenta de nuevo.');
        if ((string) config('services.facebook.client_id') === '') return $this->resultado('facebook', false, 'editus no tiene configurada la app de Facebook (FACEBOOK_CLIENT_ID).');

        $request->session()->put(self::SESION, ['u' => $u, 'red' => 'facebook', 'desde' => time(), 'volver' => $this->volver($request)]);
        return $facebook->linkRedirect();
    }

    public function youtube(Request $request): RedirectResponse|View
    {
        $u = CuentasAppService::usuarioFirmado($request);
        if ($u === null) return $this->resultado('youtube', false, 'El enlace para conectar venció o no es válido. Vuelve a la app e intenta de nuevo.');
        if ((string) config('services.google.client_id') === '') return $this->resultado('youtube', false, 'editus no tiene configurado el cliente de Google (GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET).');

        $request->session()->put(self::SESION, ['u' => $u, 'red' => 'youtube', 'desde' => time(), 'volver' => $this->volver($request)]);
        return Socialite::driver('google')
            ->scopes(self::SCOPES_YOUTUBE)
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true'])
            ->redirectUrl((string) config('services.google.redirect'))
            ->redirect();
    }

    /** Lo llama FacebookPageController::linkCallback cuando la conexión la inició la app. */
    public function callbackFacebook(Request $request, array $vinculo, string $redirectUrl, CuentasAppService $cuentas): View
    {
        $request->session()->forget(self::SESION);
        $u = (string) ($vinculo['u'] ?? '');
        if ($u === '') return $this->resultado('facebook', false, 'No se reconoció al usuario de la app. Vuelve a intentarlo desde la app.');

        if ($request->has('error')) {
            $desc = $request->get('error_description') ?: $request->get('error_reason') ?: $request->get('error');
            return $this->resultado('facebook', false, "Facebook no autorizó la conexión: {$desc}", [], $vinculo['volver'] ?? null);
        }

        try {
            $fbUser = Socialite::driver('facebook')->redirectUrl($redirectUrl)->user();
        } catch (\Throwable $e) {
            Log::error('[FB] conexión desde la app: fallo al obtener el usuario', ['err' => $e->getMessage()]);
            return $this->resultado('facebook', false, 'No se pudo completar la conexión con Facebook. Inténtalo de nuevo. Detalle: ' . $e->getMessage(), [], $vinculo['volver'] ?? null);
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
            Log::warning('[FB] conexión desde la app: no se pudo alargar el token', ['err' => $e->getMessage()]);
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
            return $this->resultado('facebook', false, 'Tu cuenta quedó conectada, pero no se pudieron leer tus páginas: ' . $e->getMessage(), [], $vinculo['volver'] ?? null);
        }

        return $this->resultado('facebook', true, $n === 1 ? 'Se conectó 1 página de Facebook.' : "Se conectaron {$n} páginas de Facebook.", ['paginas' => $n], $vinculo['volver'] ?? null);
    }

    /** Lo llama Admin\YoutubeController::callback cuando la conexión la inició la app. */
    public function callbackYoutube(Request $request, array $vinculo, YouTubeLiveService $youtube): View
    {
        $request->session()->forget(self::SESION);
        $u = (string) ($vinculo['u'] ?? '');
        if ($u === '') return $this->resultado('youtube', false, 'No se reconoció al usuario de la app. Vuelve a intentarlo desde la app.');

        if ($request->has('error')) {
            return $this->resultado('youtube', false, 'Google no autorizó la conexión: ' . $request->get('error_description', $request->get('error')), [], $vinculo['volver'] ?? null);
        }
        try {
            $g = Socialite::driver('google')->redirectUrl((string) config('services.google.redirect'))->stateless()->user();
            $canal = $youtube->registrarCanal((string) $g->token, $g->refreshToken ? (string) $g->refreshToken : null, $g->expiresIn ? (int) $g->expiresIn : null, null, $u);
        } catch (\Throwable $e) {
            Log::warning('[YouTube] conexión desde la app falló', ['usuario_app' => $u, 'err' => $e->getMessage()]);
            return $this->resultado('youtube', false, 'No se pudo conectar el canal: ' . $e->getMessage(), [], $vinculo['volver'] ?? null);
        }
        $aviso = $canal->refresh_token ? '' : ' Google no entregó permiso permanente: si deja de funcionar, desconéctalo y vuelve a conectarlo.';
        return $this->resultado('youtube', true, "Canal «{$canal->titulo}» conectado.{$aviso}", ['canal' => $canal->titulo], $vinculo['volver'] ?? null);
    }

    /** Pantalla de la app a la que vuelve el navegador (?volver=en-vivo); por defecto "cuentas". */
    private function volver(Request $request): string
    {
        $v = strtolower(trim((string) $request->query('volver', '')));
        return preg_match('/^[a-z0-9\-\/]{1,40}$/', $v) ? $v : 'cuentas';
    }

    private function resultado(string $red, bool $ok, string $mensaje, array $extra = [], ?string $volver = null): View
    {
        return view('cuentas-app.resultado', [
            'red' => $red,
            'ok' => $ok,
            'mensaje' => $mensaje,
            'volver' => CuentasAppService::urlVolverApp($volver ?: 'cuentas', ['red' => $red, 'ok' => $ok ? 1 : 0] + $extra),
        ]);
    }
}
