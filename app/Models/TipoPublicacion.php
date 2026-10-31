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

    protected $fillable = ['campaign_id', 'nombre', 'etiqueta', 'clase', 'canal', 'meta_alcance', 'valor_unitario', 'caracteristica', 'tipo', 'formato', 'unidad', 'plantilla', 'titulo_informe', 'is_active', 'orden'];

    protected $casts = ['is_active' => 'boolean', 'orden' => 'integer', 'meta_alcance' => 'integer', 'valor_unitario' => 'float'];

    /**
     * Canales de la ficha técnica: cómo se publica cada tipo.
     *  radio     → lo registran y firman las emisoras (no se publica desde editus)
     *  digital   → medios digitales: se publica desde editus en las páginas, alcance orgánico
     *  facebook / instagram → redes sociales con pauta paga: se publica desde editus y se registra la pauta
     *  tiktok    → se publica a mano y se registran el enlace y el alcance
     */
    public const CANALES = [
        'radio' => 'Radio',
        'digital' => 'Medios digitales (orgánico)',
        'facebook' => 'Redes sociales · Facebook (pauta)',
        'instagram' => 'Redes sociales · Instagram (pauta)',
        'tiktok' => 'Redes sociales · TikTok (pauta, manual)',
    ];

    /** Grupos del tablero e informes. */
    public const GRUPOS = ['radio' => 'Radio', 'digital' => 'Medios digitales', 'pauta' => 'Redes sociales (pauta paga)'];

    public function grupo(): ?string
    {
        return match ($this->canal) {
            'radio' => 'radio',
            'digital' => 'digital',
            'facebook', 'instagram', 'tiktok' => 'pauta',
            default => null,
        };
    }

    /** ¿Se publica desde editus? (radio y TikTok se registran a mano). */
    public function sePublicaEnEditus(): bool
    {
        return !in_array($this->canal, ['radio', 'tiktok'], true);
    }

    public function conPauta(): bool
    {
        return in_array($this->canal, ['facebook', 'instagram', 'tiktok'], true);
    }

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
