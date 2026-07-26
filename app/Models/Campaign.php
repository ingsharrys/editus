<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Campaign extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function posts()
    {
        return $this->hasMany(MetaPost::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Campañas que un usuario puede elegir al publicar manualmente. */
    public function scopeSelectable($query)
    {
        return $query->where('is_active', true)->where('is_system', false)->orderBy('name');
    }

    /** Campaña de sistema para los artículos de esnoticia (se crea si no existe). */
    public static function esnoticia(): self
    {
        return static::firstOrCreate(
            ['slug' => 'esnoticia'],
            [
                'name' => 'Esnoticia',
                'description' => 'Campaña de sistema: artículos replicados automáticamente desde esnoticia.',
                'is_system' => true,
                'is_active' => true,
            ]
        );
    }

    public static function makeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'campana';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
