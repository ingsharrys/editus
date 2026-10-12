<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago de una suscripción (Wompi en COP, Square en USD, o manual). Solo extiende la suscripción una vez (aplicado_en). */
class PagoSuscripcion extends Model
{
    protected $table = 'pagos_suscripcion';
    protected $fillable = ['user_id', 'plan', 'periodo', 'referencia', 'monto_centavos', 'moneda', 'estado', 'wompi_id', 'metodo', 'origen', 'aplicado_en', 'respuesta', 'pasarela', 'externo_id'];
    protected $casts = ['respuesta' => 'array', 'aplicado_en' => 'datetime'];

    public function montoTexto(): string
    {
        return \App\Services\PlanService::formatoCentavos((int) $this->monto_centavos, $this->moneda);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
