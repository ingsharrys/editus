<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransmisionEnVivo extends Model
{
    protected $table = 'transmisiones_en_vivo';

    protected $fillable = [
        'meta_page_id', 'usuario_app', 'titulo', 'descripcion', 'room', 'fb_live_id', 'fb_video_id', 'fb_permalink',
        'stream_url', 'egress_id', 'estado', 'plantilla', 'error', 'iniciada_en', 'terminada_en',
    ];

    protected $casts = [
        'plantilla' => 'array',
        'iniciada_en' => 'datetime',
        'terminada_en' => 'datetime',
    ];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
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
            'plantilla' => $this->plantilla ?? [],
            'error' => $this->error,
            'iniciada_en' => $this->iniciada_en?->toIso8601String(),
            'terminada_en' => $this->terminada_en?->toIso8601String(),
        ];
    }
}
