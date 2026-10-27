<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de publicación de un plan de medios (Videos largos, Banner 1600x185, Post 600x600, En vivos, Podcast, Cuñas…):
 * se asigna a las subcampañas, cada pieza es de un tipo, el informe tiene una sección por tipo y los cupos son por tipo.
 */
class TipoPublicacion extends Model
{
    protected $table = 'campaign_tipos';

    protected $fillable = ['campaign_id', 'nombre', 'etiqueta', 'clase', 'tipo', 'formato', 'unidad', 'plantilla', 'titulo_informe', 'is_active', 'orden'];

    protected $casts = ['is_active' => 'boolean', 'orden' => 'integer'];

    /** Cómo se presenta en el informe cada clase de publicación. */
    public const CLASES = [
        'redes' => 'Redes sociales (métricas por medio)',
        'envivo' => 'En vivo (fecha de emisión y métricas)',
        'web' => 'Páginas web (banner con captura)',
        'audio' => 'Audio (podcast, cuñas)',
        'impreso' => 'Impreso',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'tipo_id');
    }

    public function subcampanas()
    {
        return $this->belongsToMany(Subcampana::class, 'campaign_subcampana_tipo', 'tipo_id', 'subcampana_id')->withPivot('cantidad')->withTimestamps();
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
