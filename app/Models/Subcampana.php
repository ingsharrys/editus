<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Subcampaña de un plan de medios (p. ej. «Dengue»): agrupa publicaciones y define su programa y subprograma del certificado. */
class Subcampana extends Model
{
    protected $table = 'campaign_subcampanas';

    protected $fillable = ['campaign_id', 'nombre', 'programa', 'subprograma', 'descripcion', 'is_active', 'orden'];

    protected $casts = ['is_active' => 'boolean', 'orden' => 'integer'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function posts()
    {
        return $this->hasMany(MetaPost::class, 'subcampana_id');
    }
}
