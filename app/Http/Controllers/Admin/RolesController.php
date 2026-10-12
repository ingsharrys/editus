<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AccesoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Roles y permisos: qué módulos de editus puede usar cada rol. */
class RolesController extends Controller
{
    public function __construct(private AccesoService $acceso)
    {
    }

    public function index(): View
    {
        return view('admin.roles.index', [
            'roles' => Role::withCount('users')->orderByDesc('sistema')->orderBy('name')->get(),
            'grupos' => (array) config('permisos.grupos', []),
            'mios' => Auth::user()->permisos(),
            'esAdmin' => Auth::user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validar($request);
        $slug = $this->slugLibre($d['name']);
        Role::create(['name' => $d['name'], 'slug' => $slug, 'descripcion' => $d['descripcion'] ?? null, 'sistema' => false,
            'permisos' => $this->acceso->permisosPermitidos(Auth::user(), (array) ($d['permisos'] ?? []))]);
        return redirect()->route('roles.index')->with('success', "Rol «{$d['name']}» creado.");
    }

    public function update(Request $request, Role $rol): RedirectResponse
    {
        if ($rol->esAdmin()) return back()->withErrors(['rol' => 'El rol Administrador tiene todos los permisos y no se puede cambiar.']);
        $actor = Auth::user();
        // Quien no es administrador no edita su propio rol ni roles con permisos que él no tiene
        if (!$actor->isAdmin() && ((int) $actor->role_id === (int) $rol->id || !$this->acceso->puedeAsignar($actor, $rol))) abort(403, 'No puedes editar este rol.');
        $d = $this->validar($request, $rol);
        $rol->fill(['name' => $d['name'], 'descripcion' => $d['descripcion'] ?? null, 'permisos' => $this->acceso->permisosPermitidos($actor, (array) ($d['permisos'] ?? []))])->save();
        return redirect()->route('roles.index')->with('success', "Rol «{$rol->name}» actualizado.");
    }

    public function destroy(Role $rol): RedirectResponse
    {
        if ($rol->sistema || in_array($rol->slug, ['admin', 'user'], true)) return back()->withErrors(['rol' => 'Los roles del sistema no se borran.']);
        if ($rol->users()->exists()) return back()->withErrors(['rol' => "El rol «{$rol->name}» tiene usuarios: cámbialos de rol antes de borrarlo."]);
        abort_unless($this->acceso->puedeAsignar(Auth::user(), $rol), 403);
        $nombre = $rol->name;
        $rol->delete();
        return back()->with('success', "Rol «{$nombre}» borrado.");
    }

    private function validar(Request $request, ?Role $rol = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($rol?->id)],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'permisos' => ['nullable', 'array'], 'permisos.*' => ['string', Rule::in(Role::claves())],
        ], ['name.unique' => 'Ya existe un rol con ese nombre.']);
    }

    private function slugLibre(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'rol';
        $slug = $base; $i = 2;
        while (Role::where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }
}
