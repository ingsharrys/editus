<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente del emisor de videos pregrabados (infra/en-vivo/emisor). Cada petición va firmada:
 * HMAC-SHA256(EMISOR_SECRETO, ts \n nonce \n MÉTODO \n ruta \n sha256(cuerpo)). La misma firma
 * protege los avisos que el emisor manda a /webhooks/emisor.
 */
class EmisorClient
{
    public function configurado(): bool
    {
        return (string) config('services.emisor.url') !== '' && strlen((string) config('services.emisor.secreto')) >= 32;
    }

    public function base(): string
    {
        return rtrim((string) config('services.emisor.url'), '/');
    }

    public static function firmar(int $ts, string $nonce, string $metodo, string $ruta, string $cuerpo): string
    {
        return hash_hmac('sha256', "{$ts}\n{$nonce}\n" . strtoupper($metodo) . "\n{$ruta}\n" . hash('sha256', $cuerpo), (string) config('services.emisor.secreto'));
    }

    /** URL a la que el navegador sube las partes del video (con su token, válido 24 h). */
    public function urlSubida(string $id, int $maxBytes): string
    {
        $cuerpo = rtrim(strtr(base64_encode(json_encode(['v' => $id, 'max' => $maxBytes, 'exp' => time() + 86400])), '+/', '-_'), '=');
        $token = $cuerpo . '.' . hash_hmac('sha256', 'subida.' . $cuerpo, (string) config('services.emisor.secreto'));
        return $this->base() . '/subidas/' . $id . '?token=' . $token;
    }

    public function estado(string $id): ?array
    {
        [$codigo, $json] = $this->pedir('GET', "/videos/{$id}");
        return $codigo === 200 ? $json : null;
    }

    public function procesar(string $id, array $opciones): array
    {
        return $this->exigir('POST', "/videos/{$id}/procesar", $opciones);
    }

    /** $horaVideo: hora exacta (unix) en que debe empezar el video; la cuenta regresiva termina a esa hora. */
    public function emitir(string $id, array $destinos, bool $cuentaRegresiva, ?int $horaVideo = null): array
    {
        return $this->exigir('POST', "/videos/{$id}/emitir", ['destinos' => array_values($destinos), 'cuenta_regresiva' => $cuentaRegresiva, 'hora_video' => $horaVideo]);
    }

    public function detener(string $id): void
    {
        $this->pedir('POST', "/videos/{$id}/detener");
    }

    public function borrar(string $id): bool
    {
        [$codigo] = $this->pedir('DELETE', "/videos/{$id}");
        return $codigo === 200 || $codigo === 404;
    }

    public function salud(): ?array
    {
        [$codigo, $json] = $this->pedir('GET', '/estado');
        return $codigo === 200 ? $json : null;
    }

    /** Aviso del emisor (subido, listo, reintento, terminado, error): firma, ventana de 5 min y nonce de un solo uso. */
    public function avisoValido(Request $request): bool
    {
        if (!$this->configurado()) return false;
        $ts = (int) $request->header('X-Emisor-Ts');
        $nonce = (string) $request->header('X-Emisor-Nonce');
        $firma = (string) $request->header('X-Emisor-Firma');
        if (!$ts || $nonce === '' || abs(time() - $ts) > 300) return false;
        $esperada = self::firmar($ts, $nonce, 'POST', '/' . ltrim($request->path(), '/'), $request->getContent());
        if (!hash_equals($esperada, $firma)) return false;
        return Cache::add('emisor.nonce.' . sha1($nonce), 1, 600);
    }

    // ------------------------------------------------------------------

    private function exigir(string $metodo, string $ruta, array $datos = []): array
    {
        [$codigo, $json] = $this->pedir($metodo, $ruta, $datos);
        if ($codigo !== 200) throw new \RuntimeException('Emisor: ' . ($json['error'] ?? ($codigo ? "respondió {$codigo}" : 'no responde (revisa EMISOR_URL y que el contenedor esté arriba)')));
        return $json;
    }

    /** @return array{0:int,1:array} código HTTP (0 si no hubo conexión) y JSON */
    private function pedir(string $metodo, string $ruta, ?array $datos = null): array
    {
        if (!$this->configurado()) throw new \RuntimeException('El emisor no está configurado (EMISOR_URL y EMISOR_SECRETO en el .env de editus).');
        $url = $this->base() . $ruta;
        $cuerpo = $datos !== null && $metodo !== 'GET' && $metodo !== 'DELETE' ? json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $ts = time();
        $nonce = (string) Str::uuid();
        $firma = self::firmar($ts, $nonce, $metodo, (string) parse_url($url, PHP_URL_PATH), $cuerpo);
        try {
            $r = Http::timeout(30)->withHeaders(['X-Emisor-Ts' => (string) $ts, 'X-Emisor-Nonce' => $nonce, 'X-Emisor-Firma' => $firma, 'Accept' => 'application/json'])
                ->withBody($cuerpo, 'application/json')->send($metodo, $url);
            return [$r->status(), (array) $r->json()];
        } catch (\Throwable $e) {
            return [0, ['error' => $e->getMessage()]];
        }
    }
}
