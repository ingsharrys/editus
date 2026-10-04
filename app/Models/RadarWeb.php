<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Investigación en la web (Radar web) de una campaña: fuentes, hallazgos y síntesis de la IA. */
class RadarWeb extends Model
{
    protected $table = 'radar_web';
    protected $fillable = ['campana_id', 'user_id', 'estado', 'enfoque', 'plan', 'avance', 'hallazgos', 'fuentes', 'lineas', 'sintesis', 'busquedas', 'error'];
    protected $casts = ['plan' => 'array', 'hallazgos' => 'array', 'fuentes' => 'array', 'lineas' => 'array', 'sintesis' => 'array'];

    public function campana(): BelongsTo
    {
        return $this->belongsTo(Campana::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Pasos totales: un frente por tema más la síntesis final. */
    public function totalPasos(): int
    {
        return count($this->plan ?? []) + 1;
    }
}
