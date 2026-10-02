<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto diaria de una página (Facebook o Instagram): alcance, interacciones, seguidores, demografía y horarios. */
class AudienciaDiaria extends Model
{
    protected $table = 'audiencia_diaria';
    protected $fillable = ['meta_page_id', 'red', 'fecha', 'alcance', 'impresiones', 'interacciones', 'seguidores', 'nuevos_seguidores', 'visitas', 'demografia', 'horarios', 'extras'];
    protected $casts = ['fecha' => 'date', 'demografia' => 'array', 'horarios' => 'array', 'extras' => 'array'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }
}
