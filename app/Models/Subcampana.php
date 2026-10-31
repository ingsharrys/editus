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
        'campaign_id', 'parent_id', 'nombre', 'programa', 'subprograma', 'descripcion', 'is_active', 'orden', 'programa_id',
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

    /** Subcampaña que la agrupa (la campaña del plan, p. ej. «Poblaciones vulnerables»). */
    public function parent()
    {
        return $this->belongsTo(Subcampana::class, 'parent_id');
    }

    /** Subcampañas que agrupa (p. ej. «Discapacidad», «Envejecimiento y vejez»). */
    public function hijas()
    {
        return $this->hasMany(Subcampana::class, 'parent_id')->orderBy('orden')->orderBy('id');
    }

    public static function anidadas(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('campaign_subcampanas', 'parent_id');
    }

    /** «Poblaciones vulnerables › Discapacidad». */
    public function nombreCompleto(): string
    {
        return $this->parent_id && $this->parent ? $this->parent->nombre . ' › ' . $this->nombre : $this->nombre;
    }

    /** La subcampaña y sus hijas (para sumar el avance de una que agrupa). */
    public function idsConHijas(): array
    {
        if (!self::anidadas()) return [$this->id];
        return array_merge([$this->id], Subcampana::where('parent_id', $this->id)->pluck('id')->map(fn($v) => (int) $v)->all());
    }

    /** ¿Agrupa otras subcampañas? (entonces no se publica directamente en ella). */
    public function esGrupo(): bool
    {
        if (!self::anidadas()) return false;
        return $this->relationLoaded('hijas') ? $this->hijas->isNotEmpty() : Subcampana::where('parent_id', $this->id)->exists();
    }

    /**
     * Subcampañas en orden de árbol (cada una seguida de sus hijas), con «nivel» 0 o 1.
     * @param \Illuminate\Support\Collection<int, Subcampana> $subs
     */
    public static function arbol($subs)
    {
        if (!self::anidadas()) return $subs->each(fn($s) => $s->setAttribute('nivel', 0))->values();
        $ids = $subs->pluck('id')->all();
        $raices = $subs->filter(fn($s) => !$s->parent_id || !in_array($s->parent_id, $ids));
        $out = collect();
        foreach ($raices as $r) {
            $r->setAttribute('nivel', 0);
            $out->push($r);
            foreach ($subs->where('parent_id', $r->id) as $h) { $h->setAttribute('nivel', 1); $h->setRelation('parent', $r); $out->push($h); }
        }
        return $out->values();
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
