<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class MetaPost extends Model
{
    protected $fillable = [
        'batch_uuid',
        'meta_page_id',
        'user_id',
        'type',              // text|photo|video
        'message',
        'link',
        'local_media',
        'fb_media_ids',
        'fb_post_id',
        'fb_permalink_url',
        'status',
        'error',
        'published_at',

        // ← quedarán como FUENTE ÚNICA de métricas
        'alcance',
        'visualizaciones',
        'interacciones',

        // opcional si la agregas
        'last_insights_at',
    ];

    protected $casts = [
        'local_media' => 'array',
        'fb_media_ids' => 'array',
        'published_at' => 'datetime',
        'alcance' => 'integer',
        'visualizaciones' => 'integer',
        'interacciones' => 'integer',
        'last_insights_at' => 'datetime',
    ];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Páginas del usuario (como lo tenías)
    public function scopeForUserPages(Builder $q, int $userId, bool $onlyActive = true): Builder
    {
        return $q->whereIn('meta_page_id', function ($sub) use ($userId, $onlyActive) {
            $sub->select('meta_pages.id')
                ->from('meta_pages')
                ->join('meta_page_user', 'meta_page_user.meta_page_id', '=', 'meta_pages.id')
                ->where('meta_page_user.user_id', $userId);

            if ($onlyActive)
                $sub->where('meta_page_user.is_active', true);
        });
    }
}
