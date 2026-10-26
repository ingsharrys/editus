<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Medio inscrito en una campaña con el enlace de inscripción; firma el certificado de publicación. */
class CampaignParticipante extends Model
{
    protected $table = 'campaign_participantes';

    protected $fillable = [
        'campaign_id', 'user_id', 'medio_nombre', 'logo_path', 'representante', 'documento_tipo', 'documento',
        'cargo', 'ciudad', 'meta_page_ids', 'firma_path', 'firma_hash', 'acepto_terminos_at', 'terminos_hash',
        'firmado_at', 'ip', 'user_agent',
    ];

    protected $casts = [
        'meta_page_ids' => 'array',
        'acepto_terminos_at' => 'datetime',
        'firmado_at' => 'datetime',
    ];

    public const DOCUMENTOS = ['C.C.', 'C.E.', 'NIT', 'Pasaporte'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function firmado(): bool
    {
        return $this->firmado_at !== null && $this->firma_path;
    }
}
