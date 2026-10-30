<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sección "Redes" en la web de editus: lo mismo que la sección Redes de la app del editor.
 *
 * La lógica vive en el backend de esnoticia (nota en la web de cada medio, enlace corto,
 * imagen con plantilla y envío a las páginas de Facebook / Instagram por medio de editus),
 * así que la web usa los mismos endpoints que la app (/api/redes/*), autenticada con
 * X-Editus-Token (= EDITUS_INGEST_TOKEN) y X-Editus-Usuario (el periodista del backend que
 * firma la nota). Lo que se publica queda en la campaña de sistema Esnoticia.
 */
class RedesWebService
{
    public function __construct(private UsuariosAppService $usuarios) {}

    public function configurado(): bool
    {
        return $this->url() !== '' && $this->token() !== '';
    }

    /** Periodistas del backend que pueden firmar la nota. */
    public function autores(): array
    {
        return $this->usuarios->listar();
    }

    /** El periodista del backend que corresponde al usuario de editus (mismo correo o mismo nombre). */
    public function autorSugerido(\App\Models\User $u): ?int
    {
        $autores = $this->autores();
        $correo = mb_strtolower(trim((string) $u->email));
        foreach ($autores as $a) {
            if ($correo !== '' && mb_strtolower($a['email']) === $correo) return $a['id'];
        }
        $nombre = mb_strtolower(trim((string) $u->name));
        foreach ($autores as $a) {
            if ($nombre !== '' && mb_strtolower($a['username']) === $nombre) return $a['id'];
        }
        return null;
    }

    public function autorValido($id): ?int
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        foreach ($this->autores() as $a) {
            if ($a['id'] === $id) return $id;
        }
        return null;
    }

    /** Medios (sitios web) con sus categorías: el mismo catálogo que usa la app. */
    public function medios(): array
    {
        return Cache::remember('redes_web_medios', 300, function () {
            $r = Http::timeout(20)->acceptJson()->get($this->url() . '/api/test/categories');
            if (!$r->ok() || !$r->json('success') || !is_array($r->json('sitios'))) {
                throw new \RuntimeException((string) ($r->json('error') ?: __('No se pudieron cargar los medios (HTTP :s)', ['s' => $r->status()])));
            }
            return collect($r->json('sitios'))
                ->filter(fn($s) => ($s['tipo'] ?? 'laravel') === 'laravel')
                ->map(fn($s) => [
                    'sitio_id' => (int) ($s['sitio_id'] ?? 0),
                    'sitio_nombre' => (string) ($s['sitio_nombre'] ?? ''),
                    'linea_nombre' => (string) ($s['linea_nombre'] ?? ''),
                    'slug' => (string) ($s['slug'] ?? ''),
                    'url' => (string) ($s['url'] ?? ''),
                    'categorias' => array_values(array_map(fn($c) => [
                        'nombre' => (string) ($c['nombre'] ?? ''),
                        'id' => (int) ($c['wordpress_category_id'] ?? $c['wordpress_id'] ?? $c['id'] ?? 0),
                    ], is_array($s['categorias'] ?? null) ? $s['categorias'] : [])),
                ])->values()->all();
        });
    }

    /**
     * Llama a /api/redes/{ruta} del backend en nombre del periodista.
     * @return array{0:int,1:array} [status HTTP, json]
     */
    public function llamar(string $metodo, string $ruta, int $autor, array $datos = [], int $timeout = 60): array
    {
        try {
            $http = Http::timeout($timeout)->acceptJson()->withHeaders([
                'X-Editus-Token' => $this->token(),
                'X-Editus-Usuario' => (string) $autor,
            ]);
            $url = $this->url() . '/api/redes/' . ltrim($ruta, '/');
            $r = $metodo === 'GET' ? $http->get($url, $datos) : $http->asJson()->post($url, $datos);
            $json = $r->json();
            if (!is_array($json)) {
                $json = ['success' => false, 'error' => __('El backend de esnoticia respondió HTTP :s sin datos.', ['s' => $r->status()])];
            }
            if ($r->status() === 401) {
                $json = ['success' => false, 'error' => __('El backend de esnoticia no aceptó la conexión: actualízalo (git pull) y revisa que EDITUS_TOKEN del backend sea igual a EDITUS_INGEST_TOKEN de editus.')];
            } elseif ($r->status() === 404) {
                $json = ['success' => false, 'error' => __('El backend de esnoticia aún no tiene esta parte de Redes: hay que actualizarlo (git pull).')];
            }
            return [$r->status(), $json];
        } catch (\Throwable $e) {
            Log::warning('[redes web] el backend no respondió', ['ruta' => $ruta, 'err' => $e->getMessage()]);
            return [502, ['success' => false, 'error' => __('No se pudo conectar con el backend de esnoticia: :e', ['e' => mb_substr($e->getMessage(), 0, 200)])]];
        }
    }

    private function url(): string
    {
        return rtrim(trim((string) config('services.esnoticia.url', '')), '/');
    }

    private function token(): string
    {
        return trim((string) config('services.editus.ingest_token', ''));
    }
}
