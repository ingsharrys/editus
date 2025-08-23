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
            ->redirect();
    }

    public function callback()
    {
        $fbUser = Socialite::driver('facebook')->stateless()->user();

        $user = User::firstOrCreate(
            ['email' => $fbUser->getEmail() ?: (Str::uuid() . '@no-email.local')],
            [
                'name'     => $fbUser->getName() ?: $fbUser->getNickname() ?: 'FB User',
                'password' => bcrypt(Str::random(32)),
                'role_id'  => optional(\App\Models\Role::where('slug', 'user')->first())->id,
            ]
        );

        $expiresAt = null;
        if (property_exists($fbUser, 'expiresIn') && $fbUser->expiresIn) {
            $expiresAt = now()->addSeconds((int)$fbUser->expiresIn);
        }

        SocialAccount::updateOrCreate(
            [
                'provider'         => 'facebook',
                'provider_user_id' => $fbUser->getId(),
            ],
            [
                'user_id'      => $user->id,
                'access_token' => $fbUser->token,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at'   => $expiresAt,
                'raw'          => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]
        );

        Auth::login($user);

        return redirect('/dashboard');
    }
}
