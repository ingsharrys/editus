<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Role;
use App\Services\AccesoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user()->loadMissing('role');
        $mios = $user->permisos();
        $grupos = [];
        foreach (config('permisos.grupos', []) as $grupo => $permisos) {
            $tengo = array_intersect_key($permisos, array_flip($mios));
            if ($tengo) $grupos[$grupo] = $tengo;
        }

        return view('profile.edit', [
            'user' => $user,
            'grupos' => $grupos,
            'totalPermisos' => count(Role::claves()),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email', 'telefono', 'cargo']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $anterior = $user->avatar;
        if ($request->hasFile('avatar')) {
            $user->avatar = $request->file('avatar')->store('avatares', 'public');
        } elseif ($request->boolean('quitar_avatar')) {
            $user->avatar = null;
        }

        $user->save();

        if ($anterior && $anterior !== $user->avatar) {
            Storage::disk('public')->delete($anterior);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, AccesoService $acceso): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($user->isAdmin() && $acceso->adminsActivos() <= 1) {
            return back()->withErrors(['password' => 'Eres el único administrador. Nombra a otro administrador antes de eliminar tu cuenta.'], 'userDeletion');
        }

        Auth::logout();

        if ($user->avatar) Storage::disk('public')->delete($user->avatar);
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
