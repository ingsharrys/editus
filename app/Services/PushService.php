<?php

namespace App\Services;

use App\Models\DispositivoPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones al celular con el servicio de Expo (gratuito, sin límite de mensajes para este uso).
 * La app registra su token (ExponentPushToken[...]) y editus le envía los avisos. Los tokens que
 * Expo reporta como no registrados (app desinstalada) se borran solos.
 */
class PushService
{
    private const URL = 'https://exp.host/--/api/v2/push/send';

    public function registrar(string $token, ?int $userId, ?string $usuarioApp, ?string $plataforma): DispositivoPush
    {
        return DispositivoPush::updateOrCreate(['token' => $token], [
            'user_id' => $userId, 'usuario_app' => $usuarioApp ?: null, 'plataforma' => $plataforma ? substr($plataforma, 0, 10) : null, 'ultimo_uso' => now(),
        ]);
    }

    public function quitar(string $token): void
    {
        DispositivoPush::where('token', $token)->delete();
    }

    public static function tokenValido(string $token): bool
    {
        return (bool) preg_match('/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]{10,}\]$/', $token);
    }

    /** Envía a todos los celulares de un usuario de la web y/o de la app. Devuelve cuántos se aceptaron. */
    public function enviar(?int $userId, ?string $usuarioApp, string $titulo, string $cuerpo, array $datos = []): int
    {
        if (!Schema::hasTable('dispositivos_push') || (!$userId && !$usuarioApp)) return 0;
        $tokens = DispositivoPush::query()->where(function ($q) use ($userId, $usuarioApp) {
            if ($userId) $q->orWhere('user_id', $userId);
            if ($usuarioApp) $q->orWhere('usuario_app', $usuarioApp);
        })->pluck('token')->unique()->values();
        if ($tokens->isEmpty()) return 0;
        $ok = 0;
        foreach ($tokens->chunk(100) as $lote) {
            $mensajes = $lote->values()->map(fn($t) => ['to' => $t, 'title' => $titulo, 'body' => $cuerpo, 'data' => $datos, 'sound' => 'default', 'priority' => 'high', 'channelId' => 'avisos'])->all();
            try {
                $r = Http::timeout(15)->acceptJson()->withHeaders(array_filter(['Authorization' => config('services.expo.access_token') ? 'Bearer ' . config('services.expo.access_token') : null]))
                    ->post(self::URL, $mensajes);
                foreach ((array) data_get($r->json(), 'data', []) as $i => $res) {
                    if (($res['status'] ?? '') === 'ok') { $ok++; continue; }
                    if (data_get($res, 'details.error') === 'DeviceNotRegistered') DispositivoPush::where('token', $mensajes[$i]['to'] ?? '')->delete();
                }
            } catch (\Throwable $e) {
                Log::info('[push] no se pudo enviar', ['err' => $e->getMessage()]);
            }
        }
        return $ok;
    }
}
