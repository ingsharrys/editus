<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;

/**
 * Reglas para administrar usuarios y roles sin escalar privilegios:
 * quien no es administrador solo puede dar los permisos que él mismo tiene,
 * no toca a los administradores ni su propio rol, y siempre queda un administrador activo.
 */
class AccesoService
{
    /** ¿Puede $actor asignar este rol a alguien? */
    public function puedeAsignar(User $actor, Role $rol): bool
    {
        if ($actor->isAdmin()) return true;
        if ($rol->esAdmin()) return false;
        return !array_diff($rol->listaPermisos(), $actor->permisos());
    }

    /** ¿Puede $actor editar, desactivar o borrar a $usuario? */
    public function puedeGestionar(User $actor, User $usuario): bool
    {
        if ($actor->isAdmin()) return true;
        if ($usuario->isAdmin()) return false;
        return !$usuario->role || $this->puedeAsignar($actor, $usuario->role);
    }

    /** ¿Puede $actor dar estos permisos a un rol? */
    public function permisosPermitidos(User $actor, array $permisos): array
    {
        $validos = array_values(array_intersect(Role::claves(), $permisos));
        return $actor->isAdmin() ? $validos : array_values(array_intersect($validos, $actor->permisos()));
    }

    public function adminsActivos(): int
    {
        $admin = Role::where('slug', 'admin')->first();
        return $admin ? User::where('role_id', $admin->id)->where(fn($q) => $q->where('activo', true)->orWhereNull('activo'))->count() : 0;
    }
}
