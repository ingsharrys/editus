<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tema o mensaje de campaña con el que se clasifica cada publicación (seguridad, empleo, candidato X...). */
class Tema extends Model
{
    protected $fillable = ['campana_id', 'nombre', 'descripcion', 'palabras_clave', 'color', 'orden'];
    protected $casts = ['palabras_clave' => 'array'];

    public function campana(): BelongsTo
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }
}
