<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Periodistas (usuarios del backend de esnoticia) que usan la app del editor.
 * editus los pide al backend (GET /api/editus/usuarios con X-Editus-Token) para
 * ofrecerlos en el selector "Periodistas que ven esta página / canal".
 */
class UsuariosAppService
{
    public function configurado(): bool
    {
        return $this->url() !== '' && (string) config('services.editus.ingest_token') !== '';
    }

    /** @return array<int, array{id:int, username:string, email:string, role:string}> */
    public function listar(bool $forzar = false): array
    {
        if (!$this->configurado()) return [];
        if ($forzar) Cache::forget('usuarios_app');
        return Cache::remember('usuarios_app', 300, function () {
            try {
                $r = Http::timeout(15)->withHeaders(['X-Editus-Token' => (string) config('services.editus.ingest_token'), 'Accept' => 'application/json'])
                    ->get($this->url() . '/api/editus/usuarios');
                if (!$r->ok() || !$r->json('success')) {
                    Log::warning('[usuarios app] el backend no devolvió la lista', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
                    return [];
                }
                return array_values(array_map(fn($u) => [
                    'id' => (int) ($u['id'] ?? 0),
                    'username' => (string) ($u['username'] ?? ''),
                    'email' => (string) ($u['email'] ?? ''),
                    'role' => (string) ($u['role'] ?? ''),
                ], (array) $r->json('usuarios', [])));
            } catch (\Throwable $e) {
                Log::warning('[usuarios app] no se pudo consultar el backend', ['err' => $e->getMessage()]);
                return [];
            }
        });
    }

    /** id del usuario de la app → nombre de usuario (para mostrar quién conectó una página desde la app). */
    public function nombresPorId(): array
    {
        $m = [];
        foreach ($this->listar() as $u) $m[(string) $u['id']] = $u['username'];
        return $m;
    }

    private function url(): string
    {
        return rtrim(trim((string) config('services.esnoticia.url', '')), '/');
    }
}
