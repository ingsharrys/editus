<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rol de usuario con sus permisos (claves de config/permisos.php).
 * "admin" tiene siempre todos los permisos; "admin" y "user" son roles del sistema (no se borran).
 */
class Role extends Model
{
    protected $fillable = ['name', 'slug', 'descripcion', 'permisos', 'sistema'];
    protected $casts = ['permisos' => 'array', 'sistema' => 'boolean'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function esAdmin(): bool
    {
        return $this->slug === 'admin';
    }

    /** Claves de permiso de este rol (el administrador, todas). */
    public function listaPermisos(): array
    {
        if ($this->esAdmin()) return self::claves();
        return array_values(array_intersect(self::claves(), (array) ($this->permisos ?? [])));
    }

    public function tiene(string $permiso): bool
    {
        return $this->esAdmin() || in_array($permiso, (array) ($this->permisos ?? []), true);
    }

    /** Todas las claves del catálogo. */
    public static function claves(): array
    {
        $out = [];
        foreach ((array) config('permisos.grupos', []) as $permisos) $out = array_merge($out, array_keys($permisos));
        return $out;
    }

    /** Nombre legible de un permiso. */
    public static function nombrePermiso(string $clave): string
    {
        foreach ((array) config('permisos.grupos', []) as $permisos) if (isset($permisos[$clave])) return $permisos[$clave][0];
        return $clave;
    }
}
