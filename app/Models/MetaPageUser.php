<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class MetaPageUser extends Pivot
{
    protected $table = 'meta_page_user';
    protected $fillable = [
        'meta_page_id',
        'user_id',
        'usuario_app',
        'social_account_id',
        'page_access_token',
        'expires_at',
        'is_active',
    ];
    protected $casts = ['expires_at' => 'datetime', 'is_active' => 'boolean'];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
