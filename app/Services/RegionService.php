<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Idioma y país del visitante (config/region.php). El país decide la moneda:
 * Colombia → COP con Wompi; el resto → USD con Square.
 */
class RegionService
{
    public const COOKIE_IDIOMA = 'editus_idioma';
    public const COOKIE_MONEDA = 'editus_moneda';
    public const MONEDAS = ['COP', 'USD'];

    /** @return string[] */
    public function idiomas(): array
    {
        return array_keys((array) config('region.idiomas', ['es' => 'Español']));
    }

    public function idiomaValido(?string $idioma): ?string
    {
        $idioma = strtolower(substr((string) $idioma, 0, 2));
        return in_array($idioma, $this->idiomas(), true) ? $idioma : null;
    }

    /**
     * Idioma de esta petición: el elegido (cookie o perfil) → el del país (países de habla hispana
     * en español, el resto del mundo en inglés) → el del navegador → el de por defecto.
     */
    public function idioma(Request $request): string
    {
        $elegido = $this->idiomaValido($request->cookie(self::COOKIE_IDIOMA))
            ?? $this->idiomaValido($request->user()?->idioma);
        if ($elegido) return $elegido;
        if ($pais = $this->pais($request)) return $this->idiomaDelPais($pais);
        $navegador = $request->getLanguages();
        if ($navegador) {
            // El primer idioma del navegador que tengamos; si no hay ninguno, inglés
            foreach ($navegador as $l) if ($ok = $this->idiomaValido($l)) return $ok;
            return in_array('en', $this->idiomas(), true) ? 'en' : (string) config('region.idioma_defecto', 'es');
        }
        return $this->idiomaValido(config('region.idioma_defecto')) ?? 'es';
    }

    /** «es» en los países de habla hispana (config/region.php), «en» en el resto. */
    public function idiomaDelPais(string $pais): string
    {
        return in_array(strtoupper($pais), (array) config('region.paises_espanol', []), true) ? 'es' : 'en';
    }

    /** País (ISO de 2 letras) o null si no se pudo saber. */
    public function pais(Request $request): ?string
    {
        // Se calcula una sola vez por petición (lo usan el idioma y la moneda)
        if ($request->attributes->has('_editus_pais')) return $request->attributes->get('_editus_pais');
        $pais = $this->detectarPais($request);
        $request->attributes->set('_editus_pais', $pais);
        return $pais;
    }

    private function detectarPais(Request $request): ?string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Country-Code', 'X-Geo-Country', 'X-AppEngine-Country'] as $h) {
            if ($p = $this->paisValido($request->header($h))) return $p;
        }
        foreach (['GEOIP_COUNTRY_CODE', 'HTTP_GEOIP_COUNTRY_CODE', 'MM_COUNTRY_CODE'] as $v) {
            if ($p = $this->paisValido($request->server($v))) return $p;
        }
        return $this->consultarIp((string) $request->ip());
    }

    /** Moneda en la que se cobra: la elegida a mano → la del país del perfil → la del país detectado. */
    public function moneda(Request $request, ?User $user = null): string
    {
        $elegida = strtoupper((string) $request->cookie(self::COOKIE_MONEDA));
        if (in_array($elegida, self::MONEDAS, true)) return $elegida;
        $pais = $this->paisValido($user?->pais) ?? $this->pais($request);
        if ($pais === null) return config('region.pais_desconocido_local', true) ? 'COP' : 'USD';
        return $pais === config('region.pais_local', 'CO') ? 'COP' : 'USD';
    }

    public function paisValido(mixed $pais): ?string
    {
        $pais = strtoupper(trim((string) $pais));
        // XX / T1 = desconocido o Tor en Cloudflare
        return preg_match('/^[A-Z]{2}$/', $pais) && !in_array($pais, ['XX', 'T1', 'ZZ'], true) ? $pais : null;
    }

    private function consultarIp(string $ip): ?string
    {
        $url = (string) config('region.geoip_url', '');
        if ($url === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        $guardado = Cache::get('pais_ip:' . $ip);
        if ($guardado !== null) return $guardado ?: null;
        $pais = null;
        try {
            $r = Http::timeout((int) config('region.geoip_timeout', 2))->get(str_replace('{ip}', rawurlencode($ip), $url));
            if ($r->ok()) $pais = $this->paisValido($r->json('country_code') ?? $r->json('countryCode') ?? $r->json('country') ?? trim($r->body()));
        } catch (\Throwable $e) {
            Log::info('[region] no se pudo consultar el país', ['error' => $e->getMessage()]);
        }
        Cache::put('pais_ip:' . $ip, $pais ?? '', $pais ? now()->addDays(7) : now()->addHour());
        return $pais;
    }
}
