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
        'tipo',
        'contexto',
        'territorio',
        'starts_on',
        'ends_on',
        'medios',
        'is_system',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'medios' => 'array',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function posts()
    {
        return $this->hasMany(MetaPost::class);
    }

    /** Perfil de análisis (temas, lecturas de comentarios, informes de la IA) de la campaña. */
    public function perfil()
    {
        return $this->hasOne(Campana::class, 'campaign_id');
    }

    /** Nombres de los medios donde se publica la campaña (la de sistema: todos). */
    public function nombresMedios(): array
    {
        $todos = (array) config('services.editus.medios', []);
        if ($this->is_system && empty($this->medios)) return array_values($todos);
        return array_values(array_map(fn($s) => $todos[$s] ?? $s, (array) $this->medios));
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
