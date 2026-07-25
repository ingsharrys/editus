<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaPageAudience extends Model
{
    protected $fillable = [
        'meta_page_id',
        'captured_date',
        'dimension',
        'key',
        'value',
    ];

    protected $casts = [
        'captured_date' => 'date',
    ];

    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }
}
