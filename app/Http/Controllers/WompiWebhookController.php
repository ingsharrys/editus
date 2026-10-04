<?php

namespace App\Http\Controllers;

use App\Services\CobrosService;
use App\Services\WompiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Eventos de Wompi (URL de eventos en el panel de Wompi: https://app.editus.online/webhooks/wompi).
 * Se valida el checksum con el secreto de eventos y, además, se vuelve a consultar la transacción
 * en la API de Wompi antes de activar nada.
 */
class WompiWebhookController extends Controller
{
    public function __invoke(Request $request, WompiService $wompi, CobrosService $cobros): JsonResponse
    {
        $evento = $request->json()->all();
        if (!$wompi->eventoValido($evento)) {
            Log::warning('[wompi] evento con firma inválida', ['ip' => $request->ip()]);
            return response()->json(['ok' => false], 401);
        }
        if (($evento['event'] ?? '') !== 'transaction.updated') return response()->json(['ok' => true]);
        $id = (string) data_get($evento, 'data.transaction.id', '');
        $tx = $id !== '' ? ($wompi->transaccion($id) ?? null) : null;
        if (!$tx) return response()->json(['ok' => false, 'error' => 'transacción no encontrada'], 202);
        $cobros->procesar($tx);
        return response()->json(['ok' => true]);
    }
}
