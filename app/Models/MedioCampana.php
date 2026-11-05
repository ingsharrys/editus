<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Medio de comunicación del listado de una campaña, con su tipo de contrato. */
class MedioCampana extends Model
{
    protected $table = 'campaign_medios';

    /** Clases de medio (lo que el contrato llama «medio digital, página web o emisora»). */
    public const CLASES = [
        'digital' => 'Medio digital',
        'web' => 'Página web',
        'emisora' => 'Emisora',
        'redes' => 'Redes sociales',
        'impreso' => 'Medio impreso',
        'tv' => 'Televisión',
        'otro' => 'Otro',
    ];

    protected $fillable = ['campaign_id', 'nombre', 'clase', 'tipo_id', 'correo', 'notas', 'participante_id', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function tipo()
    {
        return $this->belongsTo(ContratoTipo::class, 'tipo_id');
    }

    public function participante()
    {
        return $this->belongsTo(CampaignParticipante::class, 'participante_id');
    }

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'medio_id');
    }

    public function claseTexto(): string
    {
        return self::CLASES[$this->clase] ?? 'Medio';
    }
}
