<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use App\Models\SocialAccount;
use App\Models\Role;

class FacebookAuthController extends Controller
{
    public function redirect()
    {
        $scopes = config('services.facebook.scopes', []);
        return Socialite::driver('facebook')
            ->scopes($scopes)
            ->with(['auth_type' => 'rerequest'])
            ->redirect();
    }

    public function callback()
    {
        $fbUser = \Laravel\Socialite\Facades\Socialite::driver('facebook')->stateless()->user();

        // Intercambia por token long-lived (mejor para /me/accounts estable)
        $appId = config('services.facebook.client_id');
        $appSecret = config('services.facebook.client_secret');

        $ex = \Illuminate\Support\Facades\Http::get('https://graph.facebook.com/v23.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
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
        } elseif (property_exists($fbUser, 'expiresIn') && $fbUser->expiresIn) {
            $expiresAt = now()->addSeconds((int) $fbUser->expiresIn);
        }

        // Usuario local
        $user = User::firstOrCreate(
            ['email' => $fbUser->getEmail() ?: (Str::uuid() . '@no-email.local')],
            [
                'name' => $fbUser->getName() ?: $fbUser->getNickname() ?: 'FB User',
                'password' => bcrypt(Str::random(32)),
                'role_id' => optional(Role::where('slug', 'user')->first())->id,
            ]
        );

        // Guarda/actualiza SocialAccount con el user token (long-lived si hubo)
        SocialAccount::updateOrCreate(
            ['provider' => 'facebook', 'provider_user_id' => $fbUser->getId()],
            [
                'user_id' => $user->id,
                'access_token' => $userAccessToken,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at' => $expiresAt,
                'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]
        );

        Auth::login($user);
        return redirect('/dashboard');
    }

    // === Alias para rutas "Basic" (compatibilidad con prod) ===
    public function redirectBasic()
    {
        return $this->redirect();
    }
    public function callbackBasic()
    {
        return $this->callback();
    }
}
