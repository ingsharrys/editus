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
    public function redirect()
    {
        $version = config('services.facebook.version', 'v23.0');
        $scopes = config('services.facebook.login_scopes', ['email', 'pages_show_list']);

        $with = [];

        // si tienes config_id (business login), lo mandas
        if ($cid = config('services.facebook.login_config_id')) {
            $with['config_id'] = $cid;
        }

        return Socialite::driver('facebook')
            ->usingGraphVersion($version)
            ->scopes($scopes)
            ->with($with)
            ->redirect();
    }

    public function callback(Request $request)
    {
        // Log de lo que llega desde Facebook (IMPORTANTE para ver si viene error o code)
        Log::info('FB callback payload', [
            'query' => $request->query(),
            'full' => $request->all(),
        ]);

        // Si Facebook devolvió error (no hay code)
        if ($request->has('error')) {
            $desc = $request->get('error_description') ?: $request->get('error_reason') ?: $request->get('error');

            return redirect()
                ->route('facebook.login')
                ->with('error', "Facebook OAuth error: {$desc}");
        }

        // Si NO vino el code, no intentes Socialite (evitas el 400 feo)
        if (!$request->filled('code')) {
            return redirect()
                ->route('facebook.login')
                ->with('error', 'Facebook no devolvió el parámetro "code". Revisa permisos/redirect. (Mira laravel.log)');
        }

        $version = config('services.facebook.version', 'v23.0');

        // TIP: para debug quita stateless; si te da InvalidStateException, ahí ya sabes que tu sesión/cookies están mal
        $fbUser = Socialite::driver('facebook')
            ->usingGraphVersion($version)
            ->user();

        // ---- de aquí para abajo dejas tu lógica tal cual ----

        $existing = SocialAccount::where('provider', 'facebook')
            ->where('provider_user_id', $fbUser->getId())
            ->first();

        if ($existing && $existing->user) {
            Auth::login($existing->user, true);
            return redirect()->route('meta.pages.index');
        }

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

        $email = $fbUser->getEmail();
        $user = $email ? User::where('email', $email)->first() : null;

        if (!$user) {
            $user = User::create([
                'name' => $fbUser->getName() ?: $fbUser->getNickname() ?: 'FB User',
                'email' => $email ?: ('fb_' . $fbUser->getId() . '@no-email.local'),
                'password' => bcrypt(Str::random(32)),
                'role_id' => optional(Role::where('slug', 'user')->first())->id,
            ]);
        }

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_user_id' => $fbUser->getId(),
            'access_token' => $userAccessToken,
            'refresh_token' => $fbUser->refreshToken ?? null,
            'expires_at' => $expiresAt,
            'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
        ]);

        Auth::login($user, true);
        return redirect()->route('meta.pages.index');
    }

    /**
     * Ruta temporal para ver la URL exacta que genera Socialite.
     * Úsala SOLO para debug y luego la borras.
     */
    public function debugUrl()
    {
        $scopes = config('services.facebook.login_scopes', ['email', 'public_profile']);
        $version = config('services.facebook.version', 'v23.0');

        $url = Socialite::driver('facebook')
            ->usingGraphVersion($version)
            ->scopes($scopes)
            ->redirect()
            ->getTargetUrl();

        dd($url);
    }

    public function redirectBasic()
    {
        return $this->redirect();
    }

    public function callbackBasic()
    {
        return $this->callback();
    }
}
