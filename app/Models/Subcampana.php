<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Subcampaña de un plan de medios: un tema de la campaña (p. ej. «Dengue») con su programa y subprograma
 * del contrato y los tipos de publicación que lleva (Videos largos, Post 600x600, En vivo…) con su cantidad.
 * Agrupa piezas y publicaciones, y tiene sus propias métricas.
 */
class Subcampana extends Model
{
    protected $table = 'campaign_subcampanas';

    protected $fillable = [
        'campaign_id', 'nombre', 'programa', 'subprograma', 'descripcion', 'is_active', 'orden', 'programa_id',
        'etiqueta', 'clase', 'tipo', 'formato', 'unidad', 'plantilla', 'titulo_informe',
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

    /** Programa del contrato de la subcampaña. */
    public function programaContrato()
    {
        return $this->belongsTo(Programa::class, 'programa_id');
    }

    /** Tipos de publicación asignados a la subcampaña (pivot «cantidad» = piezas planeadas). */
    public function tipos()
    {
        return $this->belongsToMany(TipoPublicacion::class, 'campaign_subcampana_tipo', 'subcampana_id', 'tipo_id')
            ->withPivot('cantidad')->withTimestamps()->orderBy('campaign_tipos.orden')->orderBy('campaign_tipos.id');
    }
}
