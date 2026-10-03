<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Recurso de producción para las transmisiones.
 * tipo: imagen | video.  uso: intro (video antes de las cámaras) | plantilla (PNG sobre el video) | publicidad (imagen o video al aire).
 */
class RecursoEnVivo extends Model
{
    public const USOS = ['intro', 'plantilla', 'publicidad'];
    protected $table = 'recursos_en_vivo';
    protected $fillable = ['tipo', 'uso', 'nombre', 'archivo', 'duracion', 'orden', 'activo'];
    protected $casts = ['duracion' => 'integer', 'orden' => 'integer', 'activo' => 'boolean'];

    /**
     * URL pública del archivo. Si LIVEKIT_RECURSOS_URL está definido (el VPS sirve los
     * recursos por su propio dominio, ver infra/en-vivo/instalar-recursos.sh), se usa ese
     * origen: así el mezclador los carga en local y sin protecciones anti-bots del hosting.
     */
    public function url(): string
    {
        $base = rtrim((string) config('services.livekit.recursos_url'), '/');
        if ($base !== '') return $base . '/' . basename($this->archivo);
        return url(Storage::disk('public')->url($this->archivo));
    }

    public function paraApi(): array
    {
        return ['id' => $this->id, 'tipo' => $this->tipo, 'uso' => $this->uso ?: 'publicidad', 'nombre' => $this->nombre, 'url' => $this->url(), 'duracion' => $this->duracion, 'creado_en' => $this->created_at?->toIso8601String()];
    }

    /** Lo que va a la metadata de la sala cuando el recurso sale al aire. */
    public function paraEscena(): array
    {
        return ['id' => $this->id, 'tipo' => $this->tipo, 'nombre' => $this->nombre, 'url' => $this->url(), 'duracion' => $this->duracion, 'inicio' => (int) round(microtime(true) * 1000)];
    }
}
