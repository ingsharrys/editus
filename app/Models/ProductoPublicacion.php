<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Publicación de una pieza registrada a mano (banner en una página web, publicación en un medio que no está en editus)
 * o datos extra (captura de evidencia, métricas corregidas) de una publicación hecha desde editus (meta_post_id).
 */
class ProductoPublicacion extends Model
{
    protected $table = 'campaign_producto_publicaciones';

    protected $fillable = ['producto_id', 'meta_post_id', 'meta_page_id', 'medio', 'url', 'alcance', 'visualizaciones', 'interacciones', 'fecha', 'evidencia_path', 'orden'];

    protected $casts = ['fecha' => 'date', 'alcance' => 'integer', 'visualizaciones' => 'integer', 'interacciones' => 'integer', 'orden' => 'integer'];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function post()
    {
        return $this->belongsTo(MetaPost::class, 'meta_post_id');
    }

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }
}
