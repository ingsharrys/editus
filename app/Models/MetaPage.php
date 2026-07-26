<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPage extends Model
{
    protected $fillable = [
        'page_id',
        'name',
        'category',
        'medio_slug',
        'instagram_business_account_id',
        'picture_url',
        'tasks'
    ];

    protected $casts = [
        'tasks' => 'array',
    ];

    // MetaPage.php
    public function users()
    {
        return $this->belongsToMany(User::class, 'meta_page_user', 'meta_page_id', 'user_id')
            ->using(\App\Models\MetaPageUser::class)
            ->withPivot(['social_account_id', 'page_access_token', 'expires_at', 'is_active'])
            ->withTimestamps();
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
