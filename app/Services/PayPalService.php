<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal (pagos internacionales en dólares; el dinero se retira a una cuenta bancaria de Colombia).
 * Orders API v2: se crea la orden, la persona la aprueba en PayPal, editus la captura y solo
 * activa el plan si la captura quedó COMPLETED por el monto y la moneda exactos.
 * Llaves SOLO en el .env: PAYPAL_ENV=sandbox|live, PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_WEBHOOK_ID.
 */
class PayPalService
{
    public function configurado(): bool
    {
        return (string) config('services.paypal.client_id') !== '' && (string) config('services.paypal.client_secret') !== '';
    }

    public function apiBase(): string
    {
        return in_array(config('services.paypal.env'), ['live', 'production'], true) ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /** Token de acceso OAuth (se guarda en caché hasta poco antes de vencer). */
    private function token(): string
    {
        $clave = 'paypal_token:' . md5($this->apiBase() . config('services.paypal.client_id'));
        if ($t = Cache::get($clave)) return $t;
        $r = Http::timeout(20)->asForm()->withBasicAuth((string) config('services.paypal.client_id'), (string) config('services.paypal.client_secret'))
            ->post($this->apiBase() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $t = (string) $r->json('access_token', '');
        if (!$r->successful() || $t === '') {
            Log::warning('[paypal] no se pudo obtener el token', ['status' => $r->status(), 'error' => $r->json('error')]);
            throw new RuntimeException('PayPal: no se pudo autenticar');
        }
        Cache::put($clave, $t, now()->addSeconds(max(60, (int) $r->json('expires_in', 3600) - 120)));
        return $t;
    }

    private function http()
    {
        return Http::timeout(25)->acceptJson()->asJson()->withToken($this->token());
    }

    public static function valor(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /** Crea la orden y devuelve ['id' => …, 'url' => …] (la URL donde la persona aprueba el pago). */
    public function crearOrden(string $referencia, string $concepto, int $montoCentavos, string $moneda, string $returnUrl, string $cancelUrl): array
    {
        $r = $this->http()->withHeaders(['PayPal-Request-Id' => 'orden-' . $referencia])->post($this->apiBase() . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $referencia,
                'custom_id' => $referencia,
                'description' => mb_substr($concepto, 0, 127),
                'amount' => ['currency_code' => $moneda, 'value' => self::valor($montoCentavos)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => 'editus',
                'locale' => app()->getLocale() === 'en' ? 'en-US' : 'es-ES',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]]],
        ]);
        $url = collect((array) $r->json('links', []))->first(fn($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true))['href'] ?? null;
        if (!$r->successful() || !$r->json('id') || !$url) {
            Log::warning('[paypal] no se pudo crear la orden', ['referencia' => $referencia, 'status' => $r->status(), 'error' => $r->json('details') ?? $r->json('message')]);
            throw new RuntimeException('PayPal: ' . ($r->json('details.0.description') ?? $r->json('message') ?? 'no se pudo crear la orden'));
        }
        return ['id' => (string) $r->json('id'), 'url' => (string) $url];
    }

    public function orden(string $orderId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9\-]+$/', $orderId)) return null;
        $r = $this->http()->get($this->apiBase() . '/v2/checkout/orders/' . $orderId);
        return $r->ok() ? (array) $r->json() : null;
    }

    /** Captura una orden aprobada. Es idempotente (PayPal-Request-Id): repetirla no cobra dos veces. */
    public function capturar(string $orderId, string $referencia): ?array
    {
        if (!preg_match('/^[A-Za-z0-9\-]+$/', $orderId)) return null;
        $r = $this->http()->withHeaders(['PayPal-Request-Id' => 'captura-' . $referencia])
            ->withBody('{}', 'application/json')->post($this->apiBase() . '/v2/checkout/orders/' . $orderId . '/capture');
        if ($r->successful()) return (array) $r->json();
        // Ya capturada (por el retorno o por el webhook): se consulta la orden
        if ($r->status() === 422 && $r->json('details.0.issue') === 'ORDER_ALREADY_CAPTURED') return $this->orden($orderId);
        Log::warning('[paypal] no se pudo capturar', ['orden' => $orderId, 'status' => $r->status(), 'error' => $r->json('details.0.issue') ?? $r->json('name')]);
        return null;
    }

    /**
     * Estado de una orden: ['estado' => APPROVED|PENDING|DECLINED|VOIDED|ERROR, 'captura' => array|null].
     * Solo es APPROVED si hay una captura COMPLETED por exactamente el monto y la moneda esperados.
     */
    public function estado(?array $orden, int $montoCentavos, string $moneda): array
    {
        if (!$orden) return ['estado' => 'ERROR', 'captura' => null];
        $capturas = (array) data_get($orden, 'purchase_units.0.payments.captures', []);
        $c = $capturas[0] ?? null;
        $statusOrden = strtoupper((string) ($orden['status'] ?? ''));
        if ($c) {
            $exacto = data_get($c, 'amount.value') === self::valor($montoCentavos) && strtoupper((string) data_get($c, 'amount.currency_code')) === $moneda;
            $st = strtoupper((string) ($c['status'] ?? ''));
            if ($st === 'COMPLETED') {
                if ($exacto) return ['estado' => 'APPROVED', 'captura' => $c];
                Log::warning('[paypal] captura con monto o moneda distintos', ['orden' => $orden['id'] ?? null, 'captura' => $c['id'] ?? null]);
                return ['estado' => 'ERROR', 'captura' => $c];
            }
            if ($st === 'PENDING') return ['estado' => 'PENDING', 'captura' => $c];
            return ['estado' => 'DECLINED', 'captura' => $c]; // DECLINED, FAILED, REFUNDED…
        }
        if ($statusOrden === 'VOIDED') return ['estado' => 'VOIDED', 'captura' => null];
        return ['estado' => 'PENDING', 'captura' => null]; // CREATED / APPROVED / PAYER_ACTION_REQUIRED
    }

    /** Verifica un webhook con la API de PayPal (verify-webhook-signature) usando PAYPAL_WEBHOOK_ID. */
    public function webhookValido(array $cabeceras, string $cuerpo): bool
    {
        $webhookId = (string) config('services.paypal.webhook_id');
        $evento = json_decode($cuerpo, true);
        if ($webhookId === '' || !is_array($evento)) return false;
        $h = fn($n) => (string) ($cabeceras[strtolower($n)][0] ?? $cabeceras[$n][0] ?? '');
        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $n) {
            if ($h($n) === '') return false;
        }
        try {
            $r = $this->http()->post($this->apiBase() . '/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $h('paypal-auth-algo'), 'cert_url' => $h('paypal-cert-url'), 'transmission_id' => $h('paypal-transmission-id'),
                'transmission_sig' => $h('paypal-transmission-sig'), 'transmission_time' => $h('paypal-transmission-time'),
                'webhook_id' => $webhookId, 'webhook_event' => $evento,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[paypal] no se pudo verificar el webhook', ['error' => $e->getMessage()]);
            return false;
        }
        return $r->ok() && $r->json('verification_status') === 'SUCCESS';
    }
}
