<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Publicación de Facebook o Instagram con su tema y métricas (la unidad del análisis). */
class PublicacionRed extends Model
{
    protected $table = 'publicaciones_redes';
    protected $fillable = ['meta_page_id', 'red', 'post_id', 'tipo', 'texto', 'permalink', 'publicado_en', 'tema_id', 'tema_fuente', 'tema_confianza',
        'alcance', 'impresiones', 'interacciones', 'reacciones', 'comentarios', 'compartidos', 'reproducciones', 'guardados', 'metricas_en'];
    protected $casts = ['publicado_en' => 'datetime', 'metricas_en' => 'datetime'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }

    public function tema(): BelongsTo
    {
        return $this->belongsTo(Tema::class, 'tema_id');
    }

    public function analisis(): HasOne
    {
        return $this->hasOne(ComentarioAnalisis::class, 'publicacion_id');
    }

    /** Interacciones por cada 100 personas alcanzadas. */
    public function tasa(): ?float
    {
        if (!$this->alcance) return null;
        return round(100 * (int) $this->interacciones / $this->alcance, 2);
    }
}
