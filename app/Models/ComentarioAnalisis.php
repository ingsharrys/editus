<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lectura agregada de los comentarios de una publicación (tono, preocupaciones, palabras). Sin nombres ni perfiles. */
class ComentarioAnalisis extends Model
{
    protected $table = 'comentarios_analisis';
    protected $fillable = ['publicacion_id', 'total', 'a_favor', 'en_contra', 'neutro', 'emociones', 'preocupaciones', 'palabras', 'resumen', 'analizado_en',
        'preguntas', 'quejas', 'pedidos', 'menciones', 'intencion'];
    protected $casts = ['emociones' => 'array', 'preocupaciones' => 'array', 'palabras' => 'array', 'analizado_en' => 'datetime',
        'preguntas' => 'array', 'quejas' => 'array', 'pedidos' => 'array', 'menciones' => 'array', 'intencion' => 'integer'];

    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(PublicacionRed::class, 'publicacion_id');
    }
}
