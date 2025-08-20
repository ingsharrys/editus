<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
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

        // Busca por email o crea uno “dummy” si no viene
        $user = User::firstOrCreate(
            ['email' => $fbUser->getEmail() ?: (Str::uuid().'@no-email.local')],
            [
                'name'     => $fbUser->getName() ?: $fbUser->getNickname() ?: 'FB User',
                'password' => bcrypt(Str::random(32)),
                // si quieres forzar rol user por defecto:
                'role_id'  => optional(\App\Models\Role::where('slug','user')->first())->id,
            ]
        );

        Auth::login($user);

        return redirect('/dashboard'); // cambia a donde quieras
    }

}
