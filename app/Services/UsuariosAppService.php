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

    /** Por qué falló la última consulta al backend (para mostrarlo en pantalla), o null. */
    public ?string $error = null;
    /** Lo consultado en esta misma petición (para no esperar dos veces si el backend no responde). */
    private ?array $memo = null;
    private ?object $memoDe = null;

    /** @return array<int, array{id:int, username:string, email:string, role:string}> */
    public function listar(bool $forzar = false): array
    {
        if (!$this->configurado()) return [];
        if ($forzar) { Cache::forget('usuarios_app'); $this->memo = null; }
        if ($this->memoDe !== request()) { $this->memo = null; $this->error = null; $this->memoDe = request(); } // la misma instancia puede servir varias peticiones
        if ($this->memo !== null) return $this->memo;
        // Solo se guarda cuando llega bien: si el backend falló, la próxima vez se vuelve a intentar
        if (is_array($ya = Cache::get('usuarios_app'))) return $this->memo = $ya;
        $url = $this->url() . '/api/editus/usuarios';
        try {
            $r = Http::timeout(15)->withHeaders(['X-Editus-Token' => (string) config('services.editus.ingest_token'), 'Accept' => 'application/json'])->get($url);
            if (!$r->ok() || !$r->json('success')) {
                $this->error = match (true) {
                    in_array($r->status(), [401, 403], true) => __('el backend rechazó el token (HTTP :s): EDITUS_INGEST_TOKEN de editus debe ser igual a EDITUS_TOKEN del backend', ['s' => $r->status()]),
                    $r->status() === 404 => __('no existe :url (HTTP 404): revisa ESNOTICIA_URL o actualiza el backend', ['url' => $url]),
                    $r->ok() => __('el backend respondió algo que no es la lista (¿ESNOTICIA_URL apunta al backend?)'),
                    default => __('el backend respondió HTTP :s', ['s' => $r->status()]),
                };
                Log::warning('[usuarios app] el backend no devolvió la lista', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
                return $this->memo = [];
            }
        } catch (\Throwable $e) {
            $this->error = __('no conecta con :url (:e)', ['url' => $url, 'e' => mb_substr(preg_replace('/\s*\(see https?:\S+\)/', '', $e->getMessage()), 0, 160)]);
            Log::warning('[usuarios app] no se pudo consultar el backend', ['err' => $e->getMessage()]);
            return $this->memo = [];
        }
        $lista = array_values(array_map(fn($u) => [
            'id' => (int) ($u['id'] ?? 0),
            'username' => (string) ($u['username'] ?? ''),
            'email' => (string) ($u['email'] ?? ''),
            'role' => (string) ($u['role'] ?? ''),
            // Nombre con el que firma en las webs de los medios (periodista); si no tiene, el usuario
            'nombre' => trim((string) ($u['nombre'] ?? '')) ?: (string) ($u['username'] ?? ''),
        ], (array) $r->json('usuarios', [])));
        Cache::put('usuarios_app', $lista, 300);
        return $this->memo = $lista;
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
