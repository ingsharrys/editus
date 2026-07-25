<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPageDailyMetric extends Model
{
    protected $fillable = [
        'meta_page_id',
        'date',
        'network',
        'impressions',
        'reach',
        'engagements',
        'video_views',
        'fans',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }
}
