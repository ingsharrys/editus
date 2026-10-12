<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'activo',
        'telefono',
        'cargo',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'exento_planes' => 'boolean',
            'activo' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }
    public function role()
    {
        return $this->belongsTo(\App\Models\Role::class);
    }

    public function hasRole(string $slug): bool
    {
        return optional($this->role)->slug === $slug;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /** ¿Puede usar este módulo? (permisos del rol; el administrador puede todo). */
    public function tienePermiso(string $permiso): bool
    {
        if ($this->isAdmin()) return true;
        $rol = $this->role;
        // Sin rol asignado: lo mismo que un usuario normal
        if (!$rol) return in_array($permiso, (array) config('permisos.por_defecto', []), true);
        // Rol "user" de una instalación sin migrar: los permisos de siempre
        if ($rol->permisos === null && $rol->slug === 'user') return in_array($permiso, (array) config('permisos.por_defecto', []), true);
        return $rol->tiene($permiso);
    }

    /** @return string[] */
    public function permisos(): array
    {
        if ($this->isAdmin()) return Role::claves();
        $rol = $this->role;
        if (!$rol || ($rol->permisos === null && $rol->slug === 'user')) return array_values(array_intersect(Role::claves(), (array) config('permisos.por_defecto', [])));
        return $rol->listaPermisos();
    }

    public function estaActivo(): bool
    {
        return ($this->activo ?? true) !== false;
    }

    /** Foto de perfil (o iniciales si no hay). */
    public function avatarUrl(): ?string
    {
        return $this->avatar ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar) : null;
    }

    public function iniciales(): string
    {
        $partes = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        return mb_strtoupper(mb_substr($partes[0] ?? '?', 0, 1) . mb_substr($partes[1] ?? '', 0, 1));
    }
    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }
    public function metaPages()
    {
        return $this->belongsToMany(MetaPage::class, 'meta_page_user')
            ->withPivot(['page_access_token', 'social_account_id', 'expires_at', 'is_active'])
            ->withTimestamps();
    }
    public function suscripcion()
    {
        return $this->hasOne(Suscripcion::class);
    }

    /** Sin límites de plan: administradores y equipo interno marcado como exento. */
    public function sinLimitesDePlan(): bool
    {
        return $this->isAdmin() || (bool) ($this->exento_planes ?? false);
    }

    public function favoritePages()
    {
        return $this->belongsToMany(MetaPage::class, 'meta_page_favorites')->withTimestamps();
    }

}
