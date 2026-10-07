<?php

namespace App\Models;

use App\Services\Inteligencia\Enfoque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Diagnóstico estratégico redactado por la IA sobre los indicadores calculados (emociones, comportamiento, tendencias y pronóstico). */
class DiagnosticoIa extends Model
{
    protected $table = 'diagnosticos_ia';
    protected $fillable = ['campana_id', 'user_id', 'enfoque', 'ambito', 'resultado', 'modelo'];
    protected $casts = ['ambito' => 'array', 'resultado' => 'array'];

    public function campana(): BelongsTo
    {
        return $this->belongsTo(Campana::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paraVista(): array
    {
        return [
            'id' => $this->id, 'enfoque' => $this->enfoque, 'enfoque_nombre' => Enfoque::NOMBRES[$this->enfoque] ?? $this->enfoque,
            'resultado' => $this->resultado ?: [], 'ambito' => $this->ambito ?: [],
            'fecha' => $this->created_at?->format('d/m/Y H:i'), 'usuario' => $this->user?->name,
        ];
    }
}
