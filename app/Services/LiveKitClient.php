<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente mínimo de LiveKit (servidor propio): tokens de acceso (JWT HS256)
 * y llamadas Twirp a RoomService / Egress. Sin dependencias externas.
 *
 * Docs: https://docs.livekit.io/reference/server/server-apis/
 */
class LiveKitClient
{
    private string $apiUrl;
    private string $wsUrl;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->wsUrl = rtrim((string) config('services.livekit.url'), '/');
        $apiUrl = (string) config('services.livekit.api_url');
        if ($apiUrl === '' && $this->wsUrl !== '') {
            $apiUrl = preg_replace('#^wss://#', 'https://', preg_replace('#^ws://#', 'http://', $this->wsUrl));
        }
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = (string) config('services.livekit.api_key');
        $this->apiSecret = (string) config('services.livekit.api_secret');
    }

    public function configurado(): bool
    {
        return $this->wsUrl !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function wsUrl(): string
    {
        return $this->wsUrl;
    }

    /** Token para un participante (la cámara de la app o la escena del egress). */
    public function tokenParticipante(string $room, string $identity, string $nombre, bool $publicar = true, int $ttl = 6 * 3600, array $extra = []): string
    {
        $grant = [
            'roomJoin' => true,
            'room' => $room,
            'canPublish' => $publicar,
            'canSubscribe' => true,
            'canPublishData' => true,
        ] + $extra;
        return $this->firmar(['video' => $grant, 'name' => $nombre], $identity, $ttl);
    }

    /** Token de servidor para las APIs (roomCreate, roomAdmin, egress...). */
    private function tokenApi(string $room = ''): string
    {
        return $this->firmar(['video' => ['roomCreate' => true, 'roomList' => true, 'roomAdmin' => true, 'room' => $room ?: null, 'roomRecord' => true]], 'editus-api', 600);
    }

    private function firmar(array $claims, string $identity, int $ttl): string
    {
        $ahora = time();
        $payload = array_merge([
            'iss' => $this->apiKey,
            'sub' => $identity,
            'nbf' => $ahora - 10,
            'exp' => $ahora + $ttl,
            'jti' => (string) Str::uuid(),
        ], $claims);
        if (isset($payload['video']['room']) && $payload['video']['room'] === null) unset($payload['video']['room']);
        return JWT::encode($payload, $this->apiSecret, 'HS256');
    }

    // ---------------------------------------------------------- RoomService

    public function crearSala(string $room, array $metadata = [], int $vacioTimeout = 300): array
    {
        return $this->twirp('livekit.RoomService', 'CreateRoom', [
            'name' => $room,
            'empty_timeout' => $vacioTimeout,
            'max_participants' => 20,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function actualizarMetadata(string $room, array $metadata): array
    {
        return $this->twirp('livekit.RoomService', 'UpdateRoomMetadata', [
            'room' => $room,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ], $room);
    }

    public function borrarSala(string $room): void
    {
        try {
            $this->twirp('livekit.RoomService', 'DeleteRoom', ['room' => $room], $room);
        } catch (\Throwable) {
            // ya no existe
        }
    }

    // ---------------------------------------------------------------- Egress

    /**
     * Compone la sala con la página de la escena (plantilla en tiempo real) y
     * la envía por RTMP(S). Devuelve el egress_id.
     */
    public function iniciarEgressRtmp(string $room, string $escenaBaseUrl, array $rtmpUrls, string $layout = 'escena'): string
    {
        $r = $this->twirp('livekit.Egress', 'StartRoomCompositeEgress', [
            'room_name' => $room,
            'layout' => $layout,
            'custom_base_url' => $escenaBaseUrl,
            'stream_outputs' => [['protocol' => 'RTMP', 'urls' => array_values($rtmpUrls)]],
            'preset' => 'H264_720P_30',
        ], $room);
        $id = (string) ($r['egress_id'] ?? $r['egressId'] ?? '');
        if ($id === '') throw new \RuntimeException('LiveKit no devolvió egress_id');
        return $id;
    }

    public function detenerEgress(string $egressId): void
    {
        try {
            $this->twirp('livekit.Egress', 'StopEgress', ['egress_id' => $egressId]);
        } catch (\Throwable) {
            // ya estaba detenido
        }
    }

    public function estadoEgress(string $egressId): ?string
    {
        try {
            $r = $this->twirp('livekit.Egress', 'ListEgress', ['egress_id' => $egressId]);
            $items = $r['items'] ?? [];
            return $items ? (string) ($items[0]['status'] ?? '') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------

    private function twirp(string $servicio, string $metodo, array $cuerpo, string $room = ''): array
    {
        if (!$this->configurado()) {
            throw new \RuntimeException('LiveKit no está configurado en editus (LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET)');
        }
        $r = Http::timeout(30)->connectTimeout(10)
            ->withToken($this->tokenApi($room))
            ->acceptJson()
            ->post("{$this->apiUrl}/twirp/{$servicio}/{$metodo}", $cuerpo);
        if (!$r->ok()) {
            $msg = data_get($r->json(), 'msg') ?: data_get($r->json(), 'error') ?: Str::limit($r->body(), 200, '');
            throw new \RuntimeException("LiveKit {$metodo} falló (HTTP {$r->status()}): {$msg}");
        }
        return $r->json() ?? [];
    }
}
