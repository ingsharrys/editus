<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Modelo de contrato de una campaña (texto con variables que se llenan al firmar). */
class ContratoTipo extends Model
{
    protected $table = 'campaign_contrato_tipos';

    protected $fillable = ['campaign_id', 'nombre', 'clase_medio', 'cuerpo', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function medios()
    {
        return $this->hasMany(MedioCampana::class, 'tipo_id');
    }
}
