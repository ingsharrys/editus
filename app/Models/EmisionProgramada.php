<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Video pregrabado programado para salir en vivo. Los destinos se guardan cifrados
 * porque llevan la URL de transmisión (clave) de cada página o canal.
 *
 * destinos: [{red: facebook|youtube, meta_page_id|canal_id, nombre, live_id, stream_url, permalink, estado, error}]
 */
class EmisionProgramada extends Model
{
    protected $table = 'emisiones_programadas';
    protected $fillable = ['user_id', 'emisor_id', 'titulo', 'descripcion', 'programada_en', 'estado', 'progreso', 'archivo_nombre', 'archivo_bytes',
        'duracion', 'plantilla', 'destinos', 'error', 'anunciada_en', 'inicio_real', 'fin_real', 'archivo_borrado_en', 'usuario_app', 'miniatura'];
    protected $casts = ['programada_en' => 'datetime', 'plantilla' => 'array', 'destinos' => 'encrypted:array', 'duracion' => 'float', 'progreso' => 'integer',
        'anunciada_en' => 'datetime', 'inicio_real' => 'datetime', 'fin_real' => 'datetime', 'archivo_borrado_en' => 'datetime', 'archivo_bytes' => 'integer'];

    public const ESTADOS = [
        'subiendo' => 'Subiendo', 'procesando' => 'Preparando el video', 'listo' => 'Programado', 'al_aire' => 'En vivo ahora',
        'terminado' => 'Emitido', 'error' => 'Con error', 'cancelado' => 'Cancelado',
    ];
    /** Estados que cuentan para el límite de "en cola" del plan. */
    public const PENDIENTES = ['subiendo', 'procesando', 'listo', 'al_aire'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pendiente(): bool
    {
        return in_array($this->estado, self::PENDIENTES, true);
    }

    /** Para la interfaz: sin las URL de transmisión. */
    public function paraVista(): array
    {
        return [
            'id' => $this->id, 'titulo' => $this->titulo, 'descripcion' => $this->descripcion,
            'programada_en' => $this->programada_en?->toIso8601String(), 'programada_texto' => $this->programada_en?->translatedFormat('D j M Y · g:i a'),
            'estado' => $this->estado, 'estado_texto' => self::ESTADOS[$this->estado] ?? $this->estado, 'progreso' => $this->progreso,
            'archivo' => $this->archivo_nombre, 'bytes' => $this->archivo_bytes, 'duracion' => $this->duracion, 'error' => $this->error,
            'anunciada' => (bool) $this->anunciada_en, 'inicio_real' => $this->inicio_real?->format('d/m/Y H:i'), 'fin_real' => $this->fin_real?->format('d/m/Y H:i'),
            'usuario' => $this->user?->name, 'puede_cancelar' => $this->pendiente(),
            'miniatura' => $this->miniatura ? \App\Services\MiniaturaService::url($this->miniatura) : null,
            'destinos' => array_map(fn($d) => [
                'red' => $d['red'] ?? 'facebook', 'nombre' => $d['nombre'] ?? '', 'estado' => $d['estado'] ?? 'pendiente', 'error' => $d['error'] ?? null, 'permalink' => $d['permalink'] ?? null,
                'anuncio' => !empty($d['anuncio_post_id']), 'anuncio_permalink' => $d['anuncio_permalink'] ?? null,
            ], (array) $this->destinos),
        ];
    }
}
