<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de análisis de una campaña (temas, lecturas de comentarios e informes de la IA).
 * La campaña en sí (`campaigns`) se crea en el módulo Campañas; nombre, contexto, fechas,
 * estado y páginas de este perfil se sincronizan desde ella (CampanasService).
 */
class Campana extends Model
{
    protected $table = 'campanas';
    protected $fillable = ['campaign_id', 'nombre', 'descripcion', 'territorio', 'desde', 'hasta', 'activa'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'activa' => 'boolean'];

    public function paginas(): BelongsToMany
    {
        $r = $this->belongsToMany(MetaPage::class, 'campana_pagina', 'campana_id', 'meta_page_id');
        return \Illuminate\Support\Facades\Schema::hasColumn('campana_pagina', 'origen') ? $r->withPivot('origen') : $r;
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /** La campaña de sistema (Esnoticia) se analiza solo cuando el operador lo pide, no en las tareas automáticas de la IA. */
    public function esDeSistema(): bool
    {
        return (bool) $this->campaign?->is_system;
    }

    public function temas(): HasMany
    {
        return $this->hasMany(Tema::class, 'campana_id')->orderBy('orden')->orderBy('id');
    }

    public function informes(): HasMany
    {
        return $this->hasMany(InformeCampana::class, 'campana_id')->orderByDesc('hasta');
    }
}
