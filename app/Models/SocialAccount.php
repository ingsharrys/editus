<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'raw'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'raw'        => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
