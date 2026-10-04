<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pregunta hecha al consultor de IA y su respuesta estructurada (diagnóstico, recomendaciones, publicaciones sugeridas). */
class ConsultaIa extends Model
{
    protected $table = 'consultas_ia';
    protected $fillable = ['user_id', 'campana_id', 'ambito', 'pregunta', 'respuesta', 'modelo'];
    protected $casts = ['ambito' => 'array', 'respuesta' => 'array'];

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
            'id' => $this->id,
            'pregunta' => $this->pregunta,
            'respuesta' => $this->respuesta ?: [],
            'ambito' => $this->ambito ?: [],
            'fecha' => $this->created_at?->format('d/m/Y H:i'),
            'usuario' => $this->user?->name,
            'campana' => $this->campana?->nombre,
        ];
    }
}
