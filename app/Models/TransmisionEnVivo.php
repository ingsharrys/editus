<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransmisionEnVivo extends Model
{
    protected $table = 'transmisiones_en_vivo';

    protected $fillable = [
        'meta_page_id', 'usuario_app', 'titulo', 'descripcion', 'room', 'fb_live_id', 'fb_video_id', 'fb_permalink',
        'stream_url', 'destinos', 'egress_id', 'estado', 'plantilla', 'escena', 'invitaciones', 'error', 'iniciada_en', 'terminada_en',
    ];

    protected $casts = [
        'plantilla' => 'array',
        'escena' => 'array',
        'invitaciones' => 'array',
        'destinos' => 'array',
        'iniciada_en' => 'datetime',
        'terminada_en' => 'datetime',
    ];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }

    /** Metadata de la sala LiveKit: plantilla + escena (la página de la escena la lee en tiempo real). */
    public function metadataSala(): array
    {
        return ($this->plantilla ?? []) + ['escena' => $this->escena ?? ['layout' => 'solo', 'principal' => 'camara-principal', 'visibles' => []]];
    }

    /** Destinos (una entrada por página). Las transmisiones viejas solo tienen la página principal. */
    public function destinosLista(): array
    {
        $d = $this->destinos ?? [];
        if (!$d && $this->fb_live_id) {
            $d = [['meta_page_id' => $this->meta_page_id, 'page_id' => (string) ($this->page?->page_id ?? ''), 'pagina' => (string) ($this->page?->name ?? ''),
                'fb_live_id' => $this->fb_live_id, 'fb_video_id' => $this->fb_video_id, 'permalink' => $this->fb_permalink, 'stream_url' => $this->stream_url, 'estado' => 'ok', 'error' => null]];
        }
        return $d;
    }

    /** Datos que ve la app (sin el stream_url secreto). */
    public function paraApi(): array
    {
        return [
            'id' => $this->id,
            'page_id' => (string) ($this->page?->page_id ?? ''),
            'pagina' => (string) ($this->page?->name ?? ''),
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'room' => $this->room,
            'estado' => $this->estado,
            'fb_live_id' => $this->fb_live_id,
            'fb_video_id' => $this->fb_video_id,
            'permalink' => $this->fb_permalink,
            'destinos' => array_values(array_map(fn($d) => array_diff_key($d, ['stream_url' => 1]), $this->destinosLista())),
            'paginas' => array_values(array_map(fn($d) => (string) ($d['pagina'] ?? ''), $this->destinosLista())),
            'plantilla' => $this->plantilla ?? [],
            'escena' => $this->escena ?? ['layout' => 'solo', 'principal' => 'camara-principal', 'visibles' => []],
            'invitaciones' => array_values(array_map(fn($i) => ['codigo' => $i['codigo'], 'nombre' => $i['nombre'] ?? null, 'url' => route('en-vivo.invitado', $i['codigo'])], $this->invitaciones ?? [])),
            'error' => $this->error,
            'iniciada_en' => $this->iniciada_en?->toIso8601String(),
            'terminada_en' => $this->terminada_en?->toIso8601String(),
        ];
    }
}
