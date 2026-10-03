<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPage extends Model
{
    protected $fillable = [
        'page_id',
        'name',
        'category',
        'instagram_business_account_id',
        'picture_url',
        'tasks',
        'visible_en_editor',
        'medio_slug',
        'app_usuarios',
    ];

    protected $casts = [
        'tasks' => 'array',
        'visible_en_editor' => 'boolean',
        'app_usuarios' => 'array',
    ];

    /**
     * Normaliza la lista de periodistas que ven una página / canal de la organización.
     * Acepta el selector múltiple (array) o texto "willy, karol". '*' = todos los periodistas;
     * lista vacía = ninguno (solo quien conecte la página desde la app).
     */
    public static function usuariosApp(array|string|null $valor): array
    {
        $items = is_array($valor) ? $valor : (preg_split('/[\s,;]+/', (string) $valor) ?: []);
        $lista = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim((string) $x)), $items), fn($x) => $x !== '')));
        if (in_array('*', $lista, true) || in_array('todos', $lista, true)) return ['*'];
        return $lista;
    }

    /** Etiqueta corta de quién ve la página en la app. */
    public function resumenUsuariosApp(): string
    {
        $lista = $this->app_usuarios;
        if ($lista === null || in_array('*', (array) $lista, true)) return 'Todos los periodistas';
        if (!$lista) return 'Nadie (solo quien la conecte desde la app)';
        return implode(', ', $lista);
    }

    /** ¿Este usuario de la app (nombre de usuario) puede ver la página de la organización? Lista vacía = todos. */
    public function visibleParaUsuarioApp(?string $nombre): bool
    {
        $lista = $this->app_usuarios;
        if ($lista === null) return true;                  // nunca configurada: todos (compatibilidad)
        if (!is_array($lista) || !$lista) return false;    // lista vacía: ningún periodista
        if (in_array('*', $lista, true)) return true;      // todos los periodistas
        return $nombre !== null && in_array(strtolower(trim($nombre)), $lista, true);
    }

    // MetaPage.php
    public function users()
    {
        return $this->belongsToMany(User::class, 'meta_page_user', 'meta_page_id', 'user_id')
            ->using(\App\Models\MetaPageUser::class)
            ->withPivot(['social_account_id', 'page_access_token', 'expires_at', 'is_active'])
            ->withTimestamps();
    }

    /** Vínculos (tokens de página) de usuarios de editus y de usuarios de la app. */
    public function vinculos()
    {
        return $this->hasMany(MetaPageUser::class, 'meta_page_id');
    }

    public function pictureUrl(string $type = 'normal', ?int $width = null, ?int $height = null): string
    {
        // Si prefieres forzar siempre Graph:
        $base = "https://graph.facebook.com/v20.0/{$this->page_id}/picture";
        $qs = ['type' => $type];
        if ($width)
            $qs['width'] = $width;
        if ($height)
            $qs['height'] = $height;
        return $base . '?' . http_build_query($qs);
    }

    public function getPictureSmallUrlAttribute(): string
    {
        return $this->pictureUrl('small');
    }

    public function getPictureLargeUrlAttribute(): string
    {
        return $this->pictureUrl('large');
    }

    // Scope por usuario activo (útil si quieres filtrar)
    // App\Models\MetaPage.php
    public function scopeForUser($query, int $userId, bool $onlyActive = true)
    {
        return $query->whereHas('users', function ($q) use ($userId, $onlyActive) {
            $q->where('users.id', $userId);
            if ($onlyActive) {
                $q->where('meta_page_user.is_active', 1); // ← en vez de wherePivot(...)
            }
        });
    }
    public function favoritedBy()
    {
        return $this->belongsToMany(User::class, 'meta_page_favorites')->withTimestamps();
    }


}
