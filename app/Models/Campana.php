<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Campaña (política, institucional o de marca): un grupo de páginas, temas objetivo y territorio. */
class Campana extends Model
{
    protected $table = 'campanas';
    protected $fillable = ['nombre', 'descripcion', 'territorio', 'desde', 'hasta', 'activa'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'activa' => 'boolean'];

    public function paginas(): BelongsToMany
    {
        return $this->belongsToMany(MetaPage::class, 'campana_pagina', 'campana_id', 'meta_page_id');
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
