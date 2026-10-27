<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Subcampaña de un plan de medios = tipo de publicación (Videos largos, Banner 1600x185, En vivos…): agrupa sus piezas y define cómo salen en el informe. */
class Subcampana extends Model
{
    protected $table = 'campaign_subcampanas';

    protected $fillable = [
        'campaign_id', 'nombre', 'programa', 'subprograma', 'descripcion', 'is_active', 'orden',
        'etiqueta', 'clase', 'tipo', 'formato', 'unidad', 'plantilla', 'titulo_informe',
    ];

    /** Cómo se presenta en el informe cada clase de publicación. */
    public const CLASES = [
        'redes' => 'Redes sociales (métricas por medio)',
        'envivo' => 'En vivo (fecha de emisión y métricas)',
        'web' => 'Páginas web (banner con captura)',
        'audio' => 'Audio (podcast, cuñas)',
        'impreso' => 'Impreso',
    ];

    protected $casts = ['is_active' => 'boolean', 'orden' => 'integer'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function posts()
    {
        return $this->hasMany(MetaPost::class, 'subcampana_id');
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'subcampana_id');
    }

    /** Nombre singular para numerar las piezas: «Video Largo» → «Video Largo No. 1». */
    public function etiquetaSingular(): string
    {
        return trim((string) $this->etiqueta) ?: (string) $this->nombre;
    }

    /** Título de la sección en el informe (p. ej. «VIDEOS LARGOS»). */
    public function tituloInforme(): string
    {
        return mb_strtoupper(trim((string) $this->titulo_informe) ?: (string) $this->nombre);
    }
}
