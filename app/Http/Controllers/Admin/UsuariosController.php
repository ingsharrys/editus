<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AccesoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Usuarios de editus: buscar, crear, editar, cambiar rol, activar / desactivar y borrar. */
class UsuariosController extends Controller
{
    public function __construct(private AccesoService $acceso)
    {
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $rol = $request->query('rol');
        $estado = $request->query('estado');
        $usuarios = User::with('role')->withCount('metaPages')
            ->when($q !== '', fn($x) => $x->where(fn($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('cargo', 'like', "%{$q}%")))
            ->when($rol, fn($x) => $x->where('role_id', (int) $rol))
            ->when($estado === 'activos', fn($x) => $x->where(fn($w) => $w->where('activo', true)->orWhereNull('activo')))
            ->when($estado === 'inactivos', fn($x) => $x->where('activo', false))
            ->orderBy('name')->paginate(25)->withQueryString();
        $actor = Auth::user();
        $roles = Role::withCount('users')->orderBy('name')->get();
        return view('admin.usuarios.index', [
            'usuarios' => $usuarios, 'roles' => $roles, 'q' => $q, 'rol' => $rol, 'estado' => $estado,
            'asignables' => $roles->filter(fn($r) => $this->acceso->puedeAsignar($actor, $r))->values(),
            'resumen' => [
                'total' => User::count(),
                'activos' => User::where(fn($w) => $w->where('activo', true)->orWhereNull('activo'))->count(),
                'inactivos' => User::where('activo', false)->count(),
                'semana' => User::where('ultimo_acceso', '>=', now()->subDays(7))->count(),
            ],
            'acceso' => $this->acceso, 'actor' => $actor,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validar($request);
        $rol = Role::findOrFail($d['role_id']);
        abort_unless($this->acceso->puedeAsignar(Auth::user(), $rol), 403, 'No puedes asignar ese rol.');
        $clave = ($d['password'] ?? null) ?: Str::password(12, symbols: false);
        $u = new User();
        $u->forceFill([
            'name' => $d['name'], 'email' => strtolower($d['email']), 'password' => Hash::make($clave), 'role_id' => $rol->id,
            'cargo' => $d['cargo'] ?? null, 'telefono' => $d['telefono'] ?? null, 'activo' => (bool) ($d['activo'] ?? true), 'email_verified_at' => now(),
        ])->save();
        $msg = "Usuario {$u->name} creado.";
        if (empty($d['password'])) $msg .= " Contraseña temporal: {$clave} (cópiala y compártela; podrá cambiarla en su perfil).";
        return redirect()->route('usuarios.index')->with('success', $msg)->with('clave_temporal', empty($d['password']) ? $clave : null);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $actor = Auth::user();
        abort_unless($this->acceso->puedeGestionar($actor, $usuario), 403, 'No puedes editar a este usuario.');
        $d = $this->validar($request, $usuario);
        $rol = Role::findOrFail($d['role_id']);
        if ((int) $usuario->role_id !== (int) $rol->id) {
            if ($usuario->id === $actor->id) return back()->withErrors(['role_id' => 'No puedes cambiar tu propio rol.']);
            abort_unless($this->acceso->puedeAsignar($actor, $rol), 403, 'No puedes asignar ese rol.');
            if ($usuario->isAdmin() && !$rol->esAdmin() && $this->acceso->adminsActivos() <= 1) return back()->withErrors(['role_id' => 'Debe quedar al menos un administrador.']);
        }
        $usuario->forceFill(['name' => $d['name'], 'email' => strtolower($d['email']), 'role_id' => $rol->id, 'cargo' => $d['cargo'] ?? null, 'telefono' => $d['telefono'] ?? null]);
        if (!empty($d['password'])) $usuario->password = Hash::make($d['password']);
        $usuario->save();
        return redirect()->route('usuarios.index', $request->only('q', 'rol', 'estado'))->with('success', "Usuario {$usuario->name} actualizado.");
    }

    public function activo(User $usuario): RedirectResponse
    {
        $actor = Auth::user();
        abort_unless($this->acceso->puedeGestionar($actor, $usuario), 403, 'No puedes cambiar a este usuario.');
        if ($usuario->id === $actor->id) return back()->withErrors(['activo' => 'No puedes desactivarte a ti mismo.']);
        $nuevo = !$usuario->estaActivo();
        if (!$nuevo && $usuario->isAdmin() && $this->acceso->adminsActivos() <= 1) return back()->withErrors(['activo' => 'Debe quedar al menos un administrador activo.']);
        $usuario->forceFill(['activo' => $nuevo])->save();
        return back()->with('success', $nuevo ? "{$usuario->name} puede volver a entrar." : "{$usuario->name} quedó desactivado: ya no puede entrar a editus.");
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $actor = Auth::user();
        abort_unless($this->acceso->puedeGestionar($actor, $usuario), 403, 'No puedes borrar a este usuario.');
        if ($usuario->id === $actor->id) return back()->withErrors(['borrar' => 'No puedes borrarte a ti mismo (hazlo desde tu perfil).']);
        if ($usuario->isAdmin() && $this->acceso->adminsActivos() <= 1) return back()->withErrors(['borrar' => 'Debe quedar al menos un administrador.']);
        if ($usuario->avatar) Storage::disk('public')->delete($usuario->avatar);
        $nombre = $usuario->name;
        $usuario->delete();
        return back()->with('success', "Usuario {$nombre} borrado.");
    }

    private function validar(Request $request, ?User $u = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($u?->id)],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'activo' => ['nullable', 'boolean'],
        ], ['email.unique' => 'Ya existe un usuario con ese correo.', 'password.min' => 'La contraseña debe tener al menos 8 caracteres.']);
    }
}
