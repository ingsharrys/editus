<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Square (pagos internacionales en dólares): enlace de pago (Checkout API), consulta de la
 * orden y sus pagos, y verificación de la firma de los webhooks. Las llaves van SOLO en el .env:
 *   SQUARE_ENV=sandbox|production, SQUARE_ACCESS_TOKEN, SQUARE_LOCATION_ID, SQUARE_WEBHOOK_SIGNATURE_KEY
 *   (opcional) SQUARE_WEBHOOK_URL: la URL exacta registrada en Square, si no es la de route('webhooks.square')
 *   (opcional) SQUARE_VERSION: versión de la API (si no, la que tenga la aplicación en el panel de Square)
 */
class SquareService
{
    public function configurado(): bool
    {
        return (string) config('services.square.access_token') !== '' && (string) config('services.square.location_id') !== '';
    }

    public function apiBase(): string
    {
        return config('services.square.env') === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    }

    private function http()
    {
        $h = Http::timeout(25)->acceptJson()->asJson()->withToken((string) config('services.square.access_token'));
        if ($v = (string) config('services.square.version')) $h = $h->withHeaders(['Square-Version' => $v]);
        return $h;
    }

    /**
     * Crea un enlace de pago por un monto fijo. Devuelve ['url' => …, 'order_id' => …, 'id' => …].
     * La referencia de editus sirve de llave de idempotencia (reintentos no cobran dos veces).
     */
    public function crearEnlace(string $referencia, string $concepto, int $montoCentavos, string $moneda, string $redirectUrl, ?string $email = null): array
    {
        $cuerpo = [
            'idempotency_key' => $referencia,
            'quick_pay' => [
                'name' => mb_substr($concepto, 0, 255),
                'price_money' => ['amount' => $montoCentavos, 'currency' => $moneda],
                'location_id' => (string) config('services.square.location_id'),
            ],
            'checkout_options' => ['redirect_url' => $redirectUrl, 'ask_for_shipping_address' => false, 'allow_tipping' => false],
            'payment_note' => $referencia,
        ];
        if ($email) $cuerpo['pre_populated_data'] = ['buyer_email' => $email];
        $r = $this->http()->post($this->apiBase() . '/v2/online-checkout/payment-links', $cuerpo);
        $enlace = (array) $r->json('payment_link', []);
        if (!$r->successful() || empty($enlace['url']) || empty($enlace['order_id'])) {
            Log::warning('[square] no se pudo crear el enlace de pago', ['referencia' => $referencia, 'status' => $r->status(), 'errores' => $r->json('errors')]);
            throw new RuntimeException('Square: ' . ($r->json('errors.0.detail') ?? 'no se pudo crear el enlace de pago'));
        }
        return ['url' => (string) $enlace['url'], 'order_id' => (string) $enlace['order_id'], 'id' => (string) ($enlace['id'] ?? '')];
    }

    public function orden(string $orderId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $orderId)) return null;
        $r = $this->http()->get($this->apiBase() . '/v2/orders/' . $orderId);
        return $r->ok() ? (array) $r->json('order', []) : null;
    }

    public function pago(string $paymentId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $paymentId)) return null;
        $r = $this->http()->get($this->apiBase() . '/v2/payments/' . $paymentId);
        return $r->ok() ? (array) $r->json('payment', []) : null;
    }

    /**
     * Estado real de una orden, consultado en la API de Square (nunca con lo que dice la URL de retorno):
     * ['estado' => APPROVED|PENDING|DECLINED|VOIDED|ERROR, 'pago' => array|null].
     * Solo es APPROVED si hay un pago COMPLETED por exactamente el monto y la moneda esperados.
     */
    public function estadoOrden(string $orderId, int $montoCentavos, string $moneda): array
    {
        $orden = $this->orden($orderId);
        if (!$orden) return ['estado' => 'ERROR', 'pago' => null];
        $pendiente = null;
        foreach ((array) ($orden['tenders'] ?? []) as $tender) {
            $id = (string) ($tender['payment_id'] ?? $tender['id'] ?? '');
            $p = $id !== '' ? $this->pago($id) : null;
            if (!$p || ($p['order_id'] ?? $orderId) !== $orderId) continue;
            $exacto = (int) data_get($p, 'amount_money.amount', -1) === $montoCentavos && strtoupper((string) data_get($p, 'amount_money.currency')) === $moneda;
            $status = strtoupper((string) ($p['status'] ?? ''));
            if ($status === 'COMPLETED') {
                if ($exacto) return ['estado' => 'APPROVED', 'pago' => $p];
                Log::warning('[square] pago con monto o moneda distintos', ['orden' => $orderId, 'pago' => $p['id'] ?? null]);
                return ['estado' => 'ERROR', 'pago' => $p];
            }
            if (in_array($status, ['APPROVED', 'PENDING'], true)) $pendiente = $p;
            elseif (!$pendiente) $pendiente = $p + ['_fallido' => true];
        }
        if (strtoupper((string) ($orden['state'] ?? '')) === 'CANCELED') return ['estado' => 'VOIDED', 'pago' => $pendiente];
        if ($pendiente && !empty($pendiente['_fallido'])) { unset($pendiente['_fallido']); return ['estado' => 'DECLINED', 'pago' => $pendiente]; }
        return ['estado' => 'PENDING', 'pago' => $pendiente];
    }

    /** Firma del webhook: base64(HMAC-SHA256(llave, URL de notificación + cuerpo crudo)). */
    public function firmaValida(string $cuerpo, string $firma, string $urlNotificacion): bool
    {
        $llave = (string) config('services.square.webhook_signature_key');
        if ($llave === '' || $firma === '') return false;
        return hash_equals(base64_encode(hash_hmac('sha256', $urlNotificacion . $cuerpo, $llave, true)), $firma);
    }

    public function urlWebhook(): string
    {
        return (string) (config('services.square.webhook_url') ?: route('webhooks.square'));
    }
}
