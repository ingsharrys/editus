<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Límite de piezas de un tipo (Video Corto, Post…) en una campaña: por defecto (meta_page_id null) o para una página. */
class CampaignCupo extends Model
{
    protected $table = 'campaign_cupos';

    protected $fillable = ['campaign_id', 'meta_page_id', 'tipo_pieza', 'cantidad'];

    protected $casts = ['cantidad' => 'integer', 'meta_page_id' => 'integer'];
}
