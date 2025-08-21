<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPage extends Model
{
    protected $fillable = [
        'page_id','name','category','instagram_business_account_id','picture_url','tasks'
    ];

    protected $casts = [
        'tasks' => 'array',
    ];

    public function users() {
        return $this->belongsToMany(User::class, 'meta_page_user')
            ->withPivot(['page_access_token','social_account_id','expires_at','is_active'])
            ->withTimestamps();
    }
}
