<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPost extends Model
{
     protected $fillable = [
        'batch_uuid','meta_page_id','user_id','type','message','link',
        'local_media','fb_media_ids','fb_post_id','fb_permalink_url',
        'status','error','published_at',
    ];

    protected $casts = [
        'local_media'  => 'array',
        'fb_media_ids' => 'array',
        'published_at' => 'datetime',
    ];

    public function page() { return $this->belongsTo(MetaPage::class, 'meta_page_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
