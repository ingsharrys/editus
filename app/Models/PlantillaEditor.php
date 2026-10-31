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
        'nombre', 'tipo', 'marco_path', 'medio_slug', 'zonas', 'logo_path', 'logo_texto', 'logo_tintar', 'etiqueta', 'pie', 'hashtag',
        'color_titulo', 'color_logo', 'color_etiqueta', 'predeterminada', 'activa', 'orden',
    ];

    protected $casts = [
        'logo_tintar'    => 'boolean',
        'predeterminada' => 'boolean',
        'activa'         => 'boolean',
        'orden'          => 'integer',
        'zonas'          => 'array',
    ];

    public function esMarco(): bool
    {
        return $this->tipo === 'marco' && (bool) $this->marco_path;
    }

    /** Copia del marco sin la caja de la categoría (cuando la caja se ajusta al largo del texto). */
    public function marcoAjustadoPath(): ?string
    {
        return $this->marco_path ? dirname($this->marco_path) . '/ajustado-' . basename($this->marco_path) : null;
    }

    public function cajaAjustable(): bool
    {
        return !empty($this->zonas['etiqueta']['ajustar']);
    }

    /** URL pública del marco PNG que se usa para componer (sin la caja si esta se ajusta al texto). */
    public function marcoUrl(): ?string
    {
        if (!$this->marco_path) return null;
        $ruta = $this->cajaAjustable() && Storage::disk('public')->exists($this->marcoAjustadoPath()) ? $this->marcoAjustadoPath() : $this->marco_path;
        return url(Storage::disk('public')->url($ruta));
    }

    /** El marco tal como se subió (para la vista previa del editor). */
    public function marcoOriginalUrl(): ?string
    {
        return $this->marco_path ? url(Storage::disk('public')->url($this->marco_path)) : null;
    }

    /**
     * Prepara la copia sin caja si la categoría se ajusta al texto (y toma el color de la caja
     * del propio marco si no se eligió otro). Se llama al guardar.
     */
    public function prepararCaja(): void
    {
        if (!$this->marco_path) return;
        $disco = Storage::disk('public');
        $ajustado = $this->marcoAjustadoPath();
        $z = $this->zonas ?: [];
        if (!$this->cajaAjustable() || !$disco->exists($this->marco_path)) {
            if ($disco->exists($ajustado)) $disco->delete($ajustado);
            return;
        }
        [$png, $color] = app(\App\Services\MarcosService::class)->quitarCaja((string) $disco->get($this->marco_path), $z['etiqueta']);
        $disco->put($ajustado, $png);
        if (empty($z['etiqueta']['fondo'])) {
            $z['etiqueta']['fondo'] = $color ?: '#AB1010';
            $this->zonas = $z;
        }
    }

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
            'tipo'           => $this->esMarco() ? 'marco' : 'diseno',
            'marco_url'      => $this->esMarco() ? $this->marcoUrl() : null,
            'medio'          => $this->medio_slug ?: null,
            'zonas'          => $this->esMarco() ? $this->zonas : null,
        ];
    }
}
