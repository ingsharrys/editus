<?php

namespace App\Http\Controllers;

use App\Models\PagoSuscripcion;
use App\Services\CobrosService;
use App\Services\PayPalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks de PayPal (en developer.paypal.com → tu app → Webhooks: https://app.editus.online/webhooks/paypal,
 * eventos CHECKOUT.ORDER.APPROVED y PAYMENT.CAPTURE.*). Se verifica el evento con la API de PayPal y, además,
 * se vuelve a consultar la orden antes de activar nada (si está aprobada y sin capturar, se captura).
 */
class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, PayPalService $paypal, CobrosService $cobros): JsonResponse
    {
        $cuerpo = (string) $request->getContent();
        if (!$paypal->webhookValido($request->headers->all(), $cuerpo)) {
            Log::warning('[paypal] webhook no verificado', ['ip' => $request->ip()]);
            return response()->json(['ok' => false], 401);
        }
        $evento = (array) json_decode($cuerpo, true);
        $tipo = (string) ($evento['event_type'] ?? '');
        $orderId = match (true) {
            str_starts_with($tipo, 'CHECKOUT.ORDER.') => (string) data_get($evento, 'resource.id', ''),
            str_starts_with($tipo, 'PAYMENT.CAPTURE.') => (string) data_get($evento, 'resource.supplementary_data.related_ids.order_id', ''),
            default => '',
        };
        $pago = $orderId !== '' ? PagoSuscripcion::where('pasarela', 'paypal')->where('externo_id', $orderId)->first() : null;
        if (!$pago) return response()->json(['ok' => true, 'ignorado' => true]);
        $cobros->procesarPaypal($pago);
        return response()->json(['ok' => true]);
    }
}
