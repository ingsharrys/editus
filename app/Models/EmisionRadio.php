<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cuñas o espacios de radio que una emisora inscrita en la campaña reporta como emitidos (y firma). */
class EmisionRadio extends Model
{
    protected $table = 'campaign_emisiones';

    protected $fillable = [
        'campaign_id', 'participante_id', 'subcampana_id', 'tipo_id', 'cantidad', 'fecha_desde', 'fecha_hasta',
        'programa', 'horario', 'soporte_path', 'observaciones', 'firma_path', 'firma_hash', 'firmado_at', 'ip',
    ];

    protected $casts = ['cantidad' => 'integer', 'fecha_desde' => 'date', 'fecha_hasta' => 'date', 'firmado_at' => 'datetime'];

    public function participante()
    {
        return $this->belongsTo(CampaignParticipante::class, 'participante_id');
    }

    public function subcampana()
    {
        return $this->belongsTo(Subcampana::class);
    }

    public function tipo()
    {
        return $this->belongsTo(TipoPublicacion::class, 'tipo_id');
    }
}
