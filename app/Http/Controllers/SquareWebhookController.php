<?php

namespace App\Http\Controllers;

use App\Models\PagoSuscripcion;
use App\Services\CobrosService;
use App\Services\SquareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks de Square (en el panel de Square: https://app.editus.online/webhooks/square, eventos
 * payment.created y payment.updated). Se valida la firma HMAC y, además, se vuelve a consultar la
 * orden en la API de Square antes de activar nada.
 */
class SquareWebhookController extends Controller
{
    public function __invoke(Request $request, SquareService $square, CobrosService $cobros): JsonResponse
    {
        $cuerpo = (string) $request->getContent();
        if (!$square->firmaValida($cuerpo, (string) $request->header('x-square-hmacsha256-signature'), $square->urlWebhook())) {
            Log::warning('[square] webhook con firma inválida', ['ip' => $request->ip()]);
            return response()->json(['ok' => false], 401);
        }
        $evento = (array) json_decode($cuerpo, true);
        if (!in_array($evento['type'] ?? '', ['payment.created', 'payment.updated'], true)) return response()->json(['ok' => true]);
        $orderId = (string) data_get($evento, 'data.object.payment.order_id', '');
        $pago = $orderId !== '' ? PagoSuscripcion::where('pasarela', 'square')->where('externo_id', $orderId)->first() : null;
        if (!$pago) return response()->json(['ok' => true, 'ignorado' => true]);
        $cobros->procesarSquare($pago);
        return response()->json(['ok' => true]);
    }
}
