<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pieza del plan de medios (control de productos): un video, un banner, un en vivo… que se publica en varios medios. */
class Producto extends Model
{
    protected $table = 'campaign_productos';

    public const ESTADOS = ['Pendiente', 'En producción', 'En revisión', 'Aprobado', 'Publicado / Entregado'];

    protected $fillable = [
        'campaign_id', 'subcampana_id', 'tipo_id', 'programa_id', 'subprograma', 'numero', 'nombre', 'descripcion', 'responsable',
        'fecha_solicitud', 'fecha_entrega', 'fecha_publicacion', 'fecha_fin', 'estado', 'acta_aprobacion', 'acta_publicacion',
        'link_archivo', 'muestra_path', 'observaciones',
    ];

    protected $casts = [
        'numero' => 'integer',
        'fecha_solicitud' => 'date',
        'fecha_entrega' => 'date',
        'fecha_publicacion' => 'date',
        'fecha_fin' => 'date',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subcampana()
    {
        return $this->belongsTo(Subcampana::class);
    }

    /** Tipo de publicación (Videos largos, Banner 1600x185…). */
    public function tipo()
    {
        return $this->belongsTo(TipoPublicacion::class, 'tipo_id');
    }

    public function programa()
    {
        return $this->belongsTo(Programa::class);
    }

    /** Publicaciones hechas desde editus. */
    public function posts()
    {
        return $this->hasMany(MetaPost::class, 'producto_id');
    }

    /** Publicaciones registradas a mano (páginas web, otros medios) y datos extra de las de editus. */
    public function publicaciones()
    {
        return $this->hasMany(ProductoPublicacion::class)->orderBy('orden')->orderBy('id');
    }

    /** «Video Largo No. 3» */
    public function codigo(): string
    {
        $et = $this->tipo?->etiquetaSingular() ?: 'Pieza';
        return $et . ' No. ' . $this->numero;
    }
}
