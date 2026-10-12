<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Archivo propio del estudio en vivo: logo, superposición (PNG), fondo, clip de video,
 * efecto de sonido o pista de música. Se guarda junto a los recursos de producción
 * (en-vivo/recursos), así el VPS los sirve con la misma caché (LIVEKIT_RECURSOS_URL).
 */
class ActivoEnVivo extends Model
{
    public const TIPOS = ['logo', 'superposicion', 'fondo', 'clip', 'sonido', 'musica'];
    public const IMAGEN = ['logo', 'superposicion', 'fondo'];
    public const AUDIO = ['sonido', 'musica'];

    protected $table = 'activos_en_vivo';
    protected $fillable = ['user_id', 'tipo', 'nombre', 'archivo', 'mime', 'bytes', 'duracion'];
    protected $casts = ['bytes' => 'integer', 'duracion' => 'integer'];

    public function url(): string
    {
        $base = rtrim((string) config('services.livekit.recursos_url'), '/');
        if ($base !== '') return $base . '/' . basename($this->archivo);
        return url(Storage::disk('public')->url($this->archivo));
    }

    public function esVideo(): bool
    {
        return str_starts_with((string) $this->mime, 'video/');
    }

    public function paraApi(): array
    {
        return ['id' => $this->id, 'tipo' => $this->tipo, 'nombre' => $this->nombre, 'url' => $this->url(), 'mime' => $this->mime,
            'bytes' => $this->bytes, 'duracion' => $this->duracion, 'propio' => $this->user_id !== null];
    }
}
