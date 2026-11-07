<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class FacebookAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $version = config('services.facebook.version', 'v23.0');
        // Basta con ser editor o administrador de la página; con «?portafolio=1» también se pide el portafolio comercial
        $portafolio = $request->boolean('portafolio');
        $scopes = \App\Support\FacebookGraph::permisosPaginas($portafolio);

        $with = [];

        // si tienes config_id (business login), lo mandas
        if ($cid = \App\Support\FacebookGraph::configId('login', $portafolio)) {
            $with['config_id'] = $cid;
        }

        // «setScopes» y no «scopes»: Socialite ya trae services.facebook.scopes (con business_management) y «scopes» los suma
        return Socialite::driver('facebook')
            ->usingGraphVersion($version)
            ->setScopes($scopes)
            ->with($with)
            ->redirect();
    }

    public function callback(Request $request)
    {
        Log::info('FB callback payload', [
            'query' => $request->query(),
            'full' => $request->all(),
        ]);

        // Los errores vuelven a la pantalla de ingreso de editus con qué hacer (volver a «facebook.login» abría otra vez
        // la ventana de Facebook y la persona quedaba dando vueltas sin ver el aviso)
        // (?error=… al cancelar, o ?error_code=…&error_message=… cuando la ventana de Facebook falla)
        if (($desc = \App\Support\FacebookGraph::errorDeLaVentana($request)) !== null) {
            Log::warning('[FB] login: Facebook devolvió un error', ['query' => $request->query()]);
            return redirect()->route('login')->withErrors(['email' => \App\Support\FacebookGraph::ayudaPermisos($desc, false, true)]);
        }

        if (!$request->filled('code')) {
            return redirect()->route('login')->withErrors(['email' => __('Facebook no devolvió el parámetro "code". Vuelve a intentarlo.')]);
        }

        $version = config('services.facebook.version', 'v23.0');

        try {
            $fbUser = Socialite::driver('facebook')->usingGraphVersion($version)->user();
        } catch (\Throwable $e) {
            Log::warning('[FB] login: no se pudo obtener el usuario', ['err' => $e->getMessage()]);
            return redirect()->route('login')->withErrors(['email' => __('No se pudo completar el ingreso con Facebook. Vuelve a intentarlo o entra con tu correo y contraseña. (Detalle: :detalle)', ['detalle' => \Illuminate\Support\Str::limit($e->getMessage(), 200)])]);
        }

        // 1. Intercambio por token de larga duración siempre (incluso si el usuario ya existe)
        $ex = Http::get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $fbUser->token,
        ]);

        $userAccessToken = $fbUser->token;
        $expiresAt = null;

        if ($ex->ok()) {
            $j = $ex->json();
            $userAccessToken = $j['access_token'] ?? $userAccessToken;
            if (!empty($j['expires_in'])) {
                $expiresAt = now()->addSeconds((int) $j['expires_in']);
            }
        } elseif (!empty($fbUser->expiresIn)) {
            $expiresAt = now()->addSeconds((int) $fbUser->expiresIn);
        }

        // 2. Gestionar el usuario (crear o recuperar)
        $email = $fbUser->getEmail();
        $user = $email ? User::where('email', $email)->first() : null;

        // Buscar si ya existía por el ID de proveedor en caso de que no haya correo
        $existingSocial = SocialAccount::where('provider', 'facebook')
            ->where('provider_user_id', $fbUser->getId())
            ->first();

        if ($existingSocial && !$user) {
            $user = $existingSocial->user;
        }

        if (!$user) {
            $user = User::create([
                'name' => $fbUser->getName() ?: $fbUser->getNickname() ?: 'FB User',
                'email' => $email ?: ('fb_' . $fbUser->getId() . '@no-email.local'),
                'password' => bcrypt(Str::random(32)),
                'role_id' => optional(Role::where('slug', 'user')->first())->id,
            ]);
        }

        // Un usuario desactivado no entra (aunque use Facebook)
        if (!$user->estaActivo()) {
            return redirect()->route('login')->withErrors(['email' => __('Tu usuario está desactivado. Habla con un administrador.')]);
        }

        // 3. Crear o actualizar la cuenta social (asegurando tener siempre el token fresco)
        $social = SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_user_id' => $fbUser->getId(),
            ],
            [
                'access_token' => $userAccessToken,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at' => $expiresAt,
                'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]
        );

        // 4. Iniciar sesión
        Auth::login($user, true);

        // 5. Sincronizar las páginas (lo mismo que «Conectar mis páginas»)
        $cuentas = app(\App\Services\CuentasAppService::class);
        try {
            $count = $cuentas->sincronizarPaginas($social, $user->id, null);
        } catch (\Throwable $e) {
            Log::error('[FB] callback: fallo en performSync', ['err' => $e->getMessage()]);
            return redirect()->route('meta.pages.index')->with('error', __('Usuario conectado, pero falló la sincronización: :detalle', ['detalle' => $e->getMessage()]));
        }

        // 6. Redirigir con el mensaje de éxito y el conteo (y qué permisos quitó, si quitó alguno esencial)
        $r = redirect()->route('meta.pages.index')->with('success', __('¡Conexión exitosa! Páginas sincronizadas: :n', ['n' => $count]));
        return ($aviso = $cuentas->avisoPermisos($social)) ? $r->with('error', $aviso) : $r;
    }
}
