<?php

namespace App\Services;

use App\Models\PagoSuscripcion;
use Illuminate\Support\Facades\Log;

/** Procesa transacciones de Wompi contra los pagos de suscripción (desde el retorno o el webhook). */
class CobrosService
{
    public function __construct(private PlanService $planes)
    {
    }

    /** Actualiza el pago con la transacción de Wompi y, si quedó aprobada, activa/extiende el plan. */
    public function procesar(array $tx): ?PagoSuscripcion
    {
        $pago = PagoSuscripcion::where('referencia', (string) ($tx['reference'] ?? ''))->first();
        if (!$pago) return null;
        $estado = strtoupper((string) ($tx['status'] ?? 'ERROR'));
        // El monto y la moneda deben ser exactamente los del pago creado por editus
        if ((int) ($tx['amount_in_cents'] ?? -1) !== (int) $pago->monto_centavos || strtoupper((string) ($tx['currency'] ?? '')) !== $pago->moneda) {
            Log::warning('[suscripciones] transacción con monto o moneda distintos', ['referencia' => $pago->referencia, 'tx' => $tx['id'] ?? null]);
            $estado = 'ERROR';
        }
        if ($pago->estado !== 'APPROVED') {
            $pago->fill([
                'estado' => $estado, 'wompi_id' => (string) ($tx['id'] ?? $pago->wompi_id),
                'metodo' => (string) ($tx['payment_method_type'] ?? $pago->metodo),
                'respuesta' => array_intersect_key($tx, array_flip(['id', 'status', 'status_message', 'amount_in_cents', 'currency', 'payment_method_type', 'created_at', 'finalized_at'])),
            ])->save();
        }
        if ($pago->estado === 'APPROVED') $this->planes->aplicarPago($pago);
        return $pago->fresh();
    }
}
