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

class FacebookAuthController extends Controller
{
    public function redirect()
    {
        $scopes = config('services.facebook.login_scopes', ['email', 'public_profile']);

        return Socialite::driver('facebook')
            ->scopes($scopes)
            ->with(['auth_type' => 'rerequest'])
            ->redirect(); // usa services.facebook.redirect
    }

    public function callback()
    {
        $fbUser = Socialite::driver('facebook')->stateless()->user();

        // 1) Si ya existe social account, loguea ese user
        $existing = SocialAccount::where('provider', 'facebook')
            ->where('provider_user_id', $fbUser->getId())
            ->first();

        if ($existing && $existing->user) {
            Auth::login($existing->user, true);
            return redirect()->route('meta.pages.index');
        }

        // 2) Long-lived token (opcional pero recomendado)
        $version = config('services.facebook.version', 'v23.0');

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

        // 3) User local (por email si viene)
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
    public function redirectBasic()
    {
        return $this->redirect();
    }

    public function callbackBasic()
    {
        return $this->callback();
    }
}
