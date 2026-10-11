<?php

namespace App\Http\Controllers;

use App\Models\EmisionProgramada;
use App\Services\EmisionesService;
use App\Services\EmisorClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Avisos del emisor de pregrabados (subido, listo, reintento, terminado, error), firmados con
 * EMISOR_SECRETO. Solo adelantan lo que la tarea de cada minuto haría de todos modos.
 */
class EmisorWebhookController extends Controller
{
    public function __invoke(Request $request, EmisorClient $emisor, EmisionesService $emisiones): JsonResponse
    {
        if (!$emisor->avisoValido($request)) {
            Log::warning('[emisor] aviso con firma inválida', ['ip' => $request->ip()]);
            return response()->json(['ok' => false], 401);
        }
        $video = (array) $request->json('video', []);
        $e = EmisionProgramada::where('emisor_id', (string) ($video['id'] ?? ''))->first();
        if (!$e) return response()->json(['ok' => true, 'ignorado' => true]);
        try {
            $emisiones->aplicar($e, $video, (string) $request->json('evento', ''));
        } catch (\Throwable $ex) {
            Log::warning('[emisor] aviso', ['id' => $e->id, 'err' => $ex->getMessage()]);
        }
        return response()->json(['ok' => true]);
    }
}
