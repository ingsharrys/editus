<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Recurso de producción para las transmisiones: cortinilla o comercial (video) o imagen a pantalla completa. */
class RecursoEnVivo extends Model
{
    protected $table = 'recursos_en_vivo';
    protected $fillable = ['tipo', 'nombre', 'archivo', 'duracion', 'orden', 'activo'];
    protected $casts = ['duracion' => 'integer', 'orden' => 'integer', 'activo' => 'boolean'];

    public function url(): string
    {
        return url(Storage::disk('public')->url($this->archivo));
    }

    public function paraApi(): array
    {
        return ['id' => $this->id, 'tipo' => $this->tipo, 'nombre' => $this->nombre, 'url' => $this->url(), 'duracion' => $this->duracion];
    }

    /** Lo que va a la metadata de la sala cuando el recurso sale al aire. */
    public function paraEscena(): array
    {
        return ['id' => $this->id, 'tipo' => $this->tipo, 'nombre' => $this->nombre, 'url' => $this->url(), 'duracion' => $this->duracion, 'inicio' => (int) round(microtime(true) * 1000)];
    }
}
