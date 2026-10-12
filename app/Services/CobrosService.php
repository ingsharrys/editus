<?php

namespace App\Services;

use App\Models\PagoSuscripcion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pagos de suscripción. En Colombia (COP) con Wompi; fuera de Colombia (USD) con Square.
 * El estado siempre se confirma con la API de la pasarela (retorno o webhook) antes de activar el plan.
 */
class CobrosService
{
    public function __construct(private PlanService $planes)
    {
    }

    public function pasarelaDe(string $moneda): string
    {
        return $moneda === 'USD' ? 'square' : 'wompi';
    }

    /** ¿Está lista la pasarela que cobra en esta moneda? */
    public function disponible(string $moneda): bool
    {
        return $moneda === 'USD' ? app(SquareService::class)->configurado() : app(WompiService::class)->configurado();
    }

    /**
     * Crea el pago pendiente y devuelve la URL de la pasarela (Web Checkout de Wompi o enlace de Square).
     * $redirectUrl es el retorno de Wompi; Square vuelve a route('suscripcion.square').
     */
    public function iniciarPago(\App\Models\User $user, string $plan, string $periodo, string $redirectUrl, string $moneda = 'COP'): string
    {
        $moneda = $moneda === 'USD' ? 'USD' : 'COP';
        $monto = $this->planes->precio($plan, $periodo, $moneda) * 100;
        $pago = PagoSuscripcion::create([
            'user_id' => $user->id, 'plan' => $plan, 'periodo' => $periodo,
            'referencia' => 'ED-' . $user->id . '-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(6)),
            'monto_centavos' => $monto, 'moneda' => $moneda, 'estado' => 'PENDING', 'pasarela' => $this->pasarelaDe($moneda),
        ]);
        if ($moneda === 'USD') {
            $nombre = (string) ($this->planes->planes()[$plan]['nombre'] ?? $plan);
            $concepto = 'editus · Plan ' . $nombre . ' (' . ($periodo === 'anual' ? 'yearly' : 'monthly') . ')';
            try {
                $enlace = app(SquareService::class)->crearEnlace($pago->referencia, $concepto, $monto, 'USD', route('suscripcion.square', ['ref' => $pago->referencia]), $user->email);
            } catch (\Throwable $e) {
                $pago->fill(['estado' => 'ERROR', 'respuesta' => ['error' => $e->getMessage()]])->save();
                throw $e;
            }
            $pago->fill(['externo_id' => $enlace['order_id'], 'respuesta' => ['payment_link' => $enlace['id']]])->save();
            return $enlace['url'];
        }
        return app(WompiService::class)->urlCheckout($pago->referencia, $monto, $redirectUrl, $user->email, $pago->moneda);
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

    /** Consulta en Square la orden del pago y, si quedó pagada por el monto exacto, activa/extiende el plan. */
    public function procesarSquare(PagoSuscripcion $pago): PagoSuscripcion
    {
        if ($pago->estado === 'APPROVED') {
            $this->planes->aplicarPago($pago);
            return $pago->fresh();
        }
        if (!$pago->externo_id) return $pago->fresh();
        $r = app(SquareService::class)->estadoOrden((string) $pago->externo_id, (int) $pago->monto_centavos, (string) $pago->moneda);
        // Si Square no responde, el pago se queda como estaba (lo confirmará el webhook)
        if ($r['estado'] === 'ERROR' && !$r['pago']) return $pago->fresh();
        $p = (array) ($r['pago'] ?? []);
        $pago->fill([
            'estado' => $r['estado'],
            'metodo' => (string) (data_get($p, 'card_details.card.card_brand') ?: ($p['source_type'] ?? $pago->metodo)),
            'respuesta' => array_filter([
                'payment_id' => $p['id'] ?? null, 'status' => $p['status'] ?? null, 'amount' => data_get($p, 'amount_money.amount'),
                'currency' => data_get($p, 'amount_money.currency'), 'source_type' => $p['source_type'] ?? null,
                'receipt_url' => $p['receipt_url'] ?? null, 'updated_at' => $p['updated_at'] ?? null,
            ], fn($v) => $v !== null),
        ])->save();
        if ($pago->estado === 'APPROVED') $this->planes->aplicarPago($pago);
        return $pago->fresh();
    }
}
