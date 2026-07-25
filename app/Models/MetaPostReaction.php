<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPostReaction extends Model
{
    protected $fillable = [
        'meta_post_id',
        'type',
        'total',
    ];

    public function post()
    {
        return $this->belongsTo(MetaPost::class, 'meta_post_id');
    }
}
