<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Wompi (Bancolombia): Web Checkout con firma de integridad, consulta de transacciones
 * y verificación de eventos. Las llaves van SOLO en el .env:
 *   WOMPI_ENV=sandbox|production, WOMPI_PUBLIC_KEY, WOMPI_INTEGRITY_SECRET, WOMPI_EVENTS_SECRET
 */
class WompiService
{
    public function configurado(): bool
    {
        return (string) config('services.wompi.public_key') !== '' && (string) config('services.wompi.integrity_secret') !== '';
    }

    public function apiBase(): string
    {
        return config('services.wompi.env') === 'production' ? 'https://production.wompi.co/v1' : 'https://sandbox.wompi.co/v1';
    }

    /** Firma de integridad: SHA256(referencia + monto_en_centavos + moneda + secreto_de_integridad). */
    public function firmaIntegridad(string $referencia, int $montoCentavos, string $moneda = 'COP'): string
    {
        return hash('sha256', $referencia . $montoCentavos . $moneda . (string) config('services.wompi.integrity_secret'));
    }

    /** URL del Web Checkout de Wompi para pagar esta referencia. */
    public function urlCheckout(string $referencia, int $montoCentavos, string $redirectUrl, ?string $email = null, string $moneda = 'COP'): string
    {
        $params = [
            'public-key' => (string) config('services.wompi.public_key'),
            'currency' => $moneda,
            'amount-in-cents' => $montoCentavos,
            'reference' => $referencia,
            'signature:integrity' => $this->firmaIntegridad($referencia, $montoCentavos, $moneda),
            'redirect-url' => $redirectUrl,
        ];
        if ($email) $params['customer-data:email'] = $email;
        return 'https://checkout.wompi.co/p/?' . http_build_query($params);
    }

    /** Consulta una transacción en Wompi (fuente de verdad del estado del pago). */
    public function transaccion(string $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9\-]+$/', $id)) return null;
        $r = Http::timeout(20)->acceptJson()->get($this->apiBase() . '/transactions/' . $id);
        return $r->ok() ? (array) data_get($r->json(), 'data', []) : null;
    }

    /**
     * Verifica un evento de Wompi: SHA256(valores de signature.properties en orden + timestamp + secreto de eventos)
     * debe coincidir con signature.checksum. Las propiedades se leen del propio evento (pueden cambiar).
     */
    public function eventoValido(array $evento): bool
    {
        $secreto = (string) config('services.wompi.events_secret');
        $props = data_get($evento, 'signature.properties');
        $checksum = (string) data_get($evento, 'signature.checksum', '');
        $timestamp = data_get($evento, 'timestamp');
        if ($secreto === '' || !is_array($props) || !$props || $checksum === '' || $timestamp === null) return false;
        $cadena = '';
        foreach ($props as $p) {
            $v = data_get($evento['data'] ?? [], (string) $p);
            if (is_array($v)) return false;
            $cadena .= is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        return hash_equals(strtolower($checksum), hash('sha256', $cadena . $timestamp . $secreto));
    }
}
