<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Suscripción de un cliente a un plan (activa mientras vence_en esté en el futuro). */
class Suscripcion extends Model
{
    protected $table = 'suscripciones';
    protected $fillable = ['user_id', 'plan', 'periodo', 'inicia_en', 'vence_en', 'paginas', 'licencia_prefijo', 'licencia_hash', 'licencia_sitios', 'licencia_creada_en', 'nota'];
    protected $casts = ['inicia_en' => 'datetime', 'vence_en' => 'datetime', 'paginas' => 'array', 'licencia_sitios' => 'array', 'licencia_creada_en' => 'datetime'];
    protected $hidden = ['licencia_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activa(): bool
    {
        return $this->vence_en !== null && $this->vence_en->isFuture();
    }

    public function config(): array
    {
        return (array) config('planes.planes.' . $this->plan, []);
    }

    public function nombrePlan(): string
    {
        return (string) ($this->config()['nombre'] ?? ucfirst($this->plan));
    }

    public function diasRestantes(): int
    {
        return $this->activa() ? (int) ceil(now()->diffInHours($this->vence_en) / 24) : 0;
    }
}
