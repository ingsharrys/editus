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
        'type',
        'message',
        'link',
        'local_media',
        'fb_media_ids',
        'fb_post_id',
        'fb_permalink_url',
        'status',
        'error',
        'published_at',
        'alcance',
        'visualizaciones',
        'interacciones',
        'evidencia_path', // ← nuevos
    ];

    protected $casts = [
        'local_media' => 'array',
        'fb_media_ids' => 'array',
        'published_at' => 'datetime',
        'alcance' => 'integer',
        'visualizaciones' => 'integer',
        'interacciones' => 'integer',
    ];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function getEffectiveAtAttribute(): ?Carbon
    {
        return $this->published_at ?? $this->created_at;
    }

    public function scopeForUserPages(Builder $q, int $userId, bool $onlyActive = true): Builder
    {
        return $q->whereIn('meta_page_id', function ($sub) use ($userId, $onlyActive) {
            $sub->select('meta_pages.id')
                ->from('meta_pages')
                ->join('meta_page_user', 'meta_page_user.meta_page_id', '=', 'meta_pages.id')
                ->where('meta_page_user.user_id', $userId);

            if ($onlyActive) {
                $sub->where('meta_page_user.is_active', true);
            }
        });
    }
}
