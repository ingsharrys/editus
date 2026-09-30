<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Plantilla de imagen para la sección Redes de la app del editor. El
 * backend de esnoticia la lee por /api/plantillas y compone la pieza
 * (foto + logo + etiqueta + título + pie) con estos datos.
 */
class PlantillaEditor extends Model
{
    protected $table = 'plantillas_editor';

    protected $fillable = [
        'nombre', 'logo_path', 'logo_texto', 'logo_tintar', 'etiqueta', 'pie', 'hashtag',
        'color_titulo', 'color_logo', 'color_etiqueta', 'predeterminada', 'activa', 'orden',
    ];

    protected $casts = [
        'logo_tintar'    => 'boolean',
        'predeterminada' => 'boolean',
        'activa'         => 'boolean',
        'orden'          => 'integer',
    ];

    /** URL pública del logo subido (usa el host de la petición, no APP_URL). */
    public function logoUrl(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }
        return url(Storage::disk('public')->url($this->logo_path));
    }

    /** Representación que consume el backend / la app. */
    public function paraApi(): array
    {
        return [
            'id'             => $this->id,
            'nombre'         => (string) $this->nombre,
            'logo_url'       => $this->logoUrl(),
            'logo_texto'     => (string) ($this->logo_texto ?? ''),
            'logo_tintar'    => (bool) $this->logo_tintar,
            'etiqueta'       => (string) ($this->etiqueta ?? ''),
            'pie'            => (string) ($this->pie ?? ''),
            'hashtag'        => (string) ($this->hashtag ?? ''),
            'color_titulo'   => (string) $this->color_titulo,
            'color_logo'     => (string) $this->color_logo,
            'color_etiqueta' => (string) $this->color_etiqueta,
            'predeterminada' => (bool) $this->predeterminada,
        ];
    }
}
