<?php

namespace App\Services;

use App\Models\YoutubeCanal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * YouTube Live (Data API v3): crea la transmisión con título y descripción,
 * la enlaza a un stream RTMP reutilizable del canal, informa espectadores y la cierra.
 * Los tokens de Google se renuevan solos con el refresh_token.
 */
class YouTubeLiveService
{
    private const API = 'https://www.googleapis.com/youtube/v3/';

    /** Guarda o actualiza el canal a partir de los tokens de Google (callback de OAuth). */
    public function registrarCanal(string $accessToken, ?string $refreshToken, ?int $expiraEn, ?int $userId): YoutubeCanal
    {
        $r = Http::timeout(20)->withToken($accessToken)->get(self::API . 'channels', ['part' => 'snippet', 'mine' => 'true']);
        $item = data_get($r->json(), 'items.0');
        if (!$r->ok() || !$item) {
            throw new \RuntimeException('Google no devolvió ningún canal de YouTube para esta cuenta: ' . (data_get($r->json(), 'error.message') ?: 'revisa que la cuenta tenga un canal'));
        }
        $canal = YoutubeCanal::firstOrNew(['channel_id' => (string) $item['id']]);
        $canal->fill([
            'user_id' => $userId,
            'titulo' => Str::limit((string) data_get($item, 'snippet.title', 'Canal'), 150, ''),
            'foto' => data_get($item, 'snippet.thumbnails.default.url'),
            'access_token' => $accessToken,
            'expira_en' => now()->addSeconds($expiraEn ?: 3500),
        ]);
        if ($refreshToken) $canal->refresh_token = $refreshToken;
        $canal->visible_en_editor = $canal->exists ? $canal->visible_en_editor : true;
        $canal->save();
        return $canal;
    }

    /** Crea la transmisión (auto-inicio y auto-cierre) y devuelve id, stream_url (secreto) y permalink. */
    public function crear(YoutubeCanal $canal, string $titulo, string $descripcion, string $privacidad = 'public'): array
    {
        $token = $this->token($canal);
        $stream = $this->asegurarStream($canal, $token);

        $r = Http::timeout(30)->withToken($token)->post(self::API . 'liveBroadcasts?part=snippet,status,contentDetails', [
            'snippet' => ['title' => Str::limit($titulo, 100, ''), 'description' => Str::limit($descripcion, 5000, ''), 'scheduledStartTime' => now()->toIso8601ZuluString()],
            'status' => ['privacyStatus' => in_array($privacidad, ['public', 'unlisted', 'private'], true) ? $privacidad : 'public', 'selfDeclaredMadeForKids' => false],
            'contentDetails' => ['enableAutoStart' => true, 'enableAutoStop' => true, 'enableDvr' => true, 'recordFromStart' => true, 'monitorStream' => ['enableMonitorStream' => false]],
        ]);
        if (!$r->ok() || !data_get($r->json(), 'id')) {
            throw new \RuntimeException('YouTube no creó la transmisión: ' . $this->mensajeError($r->json(), $r->body()));
        }
        $broadcastId = (string) data_get($r->json(), 'id');

        $b = Http::timeout(30)->withToken($token)->post(self::API . 'liveBroadcasts/bind?' . http_build_query(['id' => $broadcastId, 'part' => 'id', 'streamId' => $stream['id']]));
        if (!$b->ok()) {
            $this->terminar($canal, $broadcastId);
            throw new \RuntimeException('YouTube no enlazó la transmisión con el stream: ' . $this->mensajeError($b->json(), $b->body()));
        }
        return ['id' => $broadcastId, 'stream_url' => $stream['rtmp'], 'permalink' => 'https://www.youtube.com/watch?v=' . $broadcastId, 'video_id' => $broadcastId];
    }

    public function terminar(YoutubeCanal $canal, string $broadcastId): void
    {
        try {
            $token = $this->token($canal);
            Http::timeout(20)->withToken($token)->post(self::API . 'liveBroadcasts/transition?' . http_build_query(['id' => $broadcastId, 'broadcastStatus' => 'complete', 'part' => 'status']));
        } catch (\Throwable $e) {
            Log::info('[YouTube] terminar', ['id' => $broadcastId, 'err' => $e->getMessage()]); // con enableAutoStop YouTube la cierra sola
        }
    }

    /** Estado y espectadores en vivo. */
    public function estado(YoutubeCanal $canal, string $broadcastId): array
    {
        try {
            $token = $this->token($canal);
            $r = Http::timeout(20)->withToken($token)->get(self::API . 'videos', ['part' => 'liveStreamingDetails,snippet', 'id' => $broadcastId]);
            $item = data_get($r->json(), 'items.0');
            if (!$r->ok() || !$item) return ['status' => null, 'espectadores' => null];
            $vivos = data_get($item, 'liveStreamingDetails.concurrentViewers');
            return [
                'status' => (string) data_get($item, 'snippet.liveBroadcastContent', ''),
                'espectadores' => $vivos !== null ? (int) $vivos : null,
                'permalink' => 'https://www.youtube.com/watch?v=' . $broadcastId,
                'video_id' => $broadcastId,
            ];
        } catch (\Throwable) {
            return ['status' => null, 'espectadores' => null];
        }
    }

    // ------------------------------------------------------------------

    /** Stream RTMP reutilizable del canal (se crea una sola vez). Devuelve id y URL rtmps completa. */
    private function asegurarStream(YoutubeCanal $canal, string $token): array
    {
        if ($canal->stream_id) {
            $r = Http::timeout(20)->withToken($token)->get(self::API . 'liveStreams', ['part' => 'cdn,status', 'id' => $canal->stream_id]);
            $item = data_get($r->json(), 'items.0');
            if ($r->ok() && $item) return ['id' => $canal->stream_id, 'rtmp' => $this->rtmpDe($item)];
        }
        $r = Http::timeout(30)->withToken($token)->post(self::API . 'liveStreams?part=snippet,cdn,contentDetails', [
            'snippet' => ['title' => 'editus · ' . config('app.name', 'app')],
            'cdn' => ['frameRate' => '30fps', 'ingestionType' => 'rtmp', 'resolution' => '1080p'],
            'contentDetails' => ['isReusable' => true],
        ]);
        if (!$r->ok() || !data_get($r->json(), 'id')) {
            throw new \RuntimeException('YouTube no creó el stream del canal: ' . $this->mensajeError($r->json(), $r->body()));
        }
        $canal->stream_id = (string) data_get($r->json(), 'id');
        $canal->save();
        return ['id' => $canal->stream_id, 'rtmp' => $this->rtmpDe($r->json())];
    }

    private function rtmpDe(array $stream): string
    {
        $ing = (array) data_get($stream, 'cdn.ingestionInfo', []);
        $base = (string) ($ing['rtmpsIngestionAddress'] ?? $ing['ingestionAddress'] ?? '');
        $clave = (string) ($ing['streamName'] ?? '');
        if ($base === '' || $clave === '') throw new \RuntimeException('YouTube no devolvió la dirección RTMP del stream');
        return rtrim($base, '/') . '/' . $clave;
    }

    /** Access token vigente; lo renueva con el refresh_token si venció. */
    public function token(YoutubeCanal $canal): string
    {
        if ($canal->expira_en && $canal->expira_en->gt(now()->addMinutes(2))) return $canal->access_token;
        if (!$canal->refresh_token) throw new \RuntimeException("El canal {$canal->titulo} necesita volver a conectarse en editus (Admin → App del editor → YouTube)");
        $r = Http::timeout(20)->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'refresh_token' => $canal->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (!$r->ok() || !data_get($r->json(), 'access_token')) {
            throw new \RuntimeException("Google no renovó el acceso del canal {$canal->titulo}: vuelve a conectarlo en editus (" . (data_get($r->json(), 'error_description') ?: data_get($r->json(), 'error') ?: 'sin detalle') . ')');
        }
        $canal->fill(['access_token' => (string) data_get($r->json(), 'access_token'), 'expira_en' => now()->addSeconds((int) data_get($r->json(), 'expires_in', 3500))])->save();
        return $canal->access_token;
    }

    private function mensajeError($json, string $body): string
    {
        $msg = (string) (data_get($json, 'error.errors.0.reason') ? data_get($json, 'error.errors.0.reason') . ': ' : '') . (data_get($json, 'error.message') ?: Str::limit($body, 200, ''));
        if (str_contains($msg, 'liveStreamingNotEnabled') || stripos($msg, 'not enabled') !== false) $msg .= ' — activa las transmisiones en vivo del canal en YouTube Studio (tarda 24 h la primera vez)';
        return $msg;
    }
}
