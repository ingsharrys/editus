<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Informe periódico de una campaña redactado por la IA a partir de los datos agregados. */
class InformeCampana extends Model
{
    protected $table = 'informes_campana';
    protected $fillable = ['campana_id', 'desde', 'hasta', 'contenido', 'datos'];
    protected $casts = ['desde' => 'date', 'hasta' => 'date', 'datos' => 'array'];

    public function campana(): BelongsTo
    {
        return $this->belongsTo(Campana::class, 'campana_id');
    }
}
