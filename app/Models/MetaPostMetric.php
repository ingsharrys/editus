<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPostMetric extends Model
{
      protected $fillable = [
        'meta_post_id',
        'round',
        'alcance',
        'visualizaciones',
        'interacciones',
        'evidencia_path',
    ];

    protected $casts = [
        'alcance' => 'integer',
        'visualizaciones' => 'integer',
        'interacciones' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(MetaPost::class, 'meta_post_id');
    }

    public function getIsCompleteAttribute(): bool
    {
        return !is_null($this->alcance)
            && !is_null($this->visualizaciones)
            && !is_null($this->interacciones);
           
    }
}
