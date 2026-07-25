<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
 /**
     * GET /api/webhooks/whatsapp
     * Verificación inicial del webhook (Meta envía hub.challenge).
     * Acepta tanto hub.mode/hub.verify_token/hub.challenge como sus variantes con guion bajo.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub.mode', $request->query('hub_mode'));
        $token     = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    /**
     * POST /api/webhooks/whatsapp
     * Recepción de eventos (statuses, mensajes, updates de plantillas).
     * Verifica firma (si hay APP_SECRET), loguea y enruta a handlers.
     */
    public function handle(Request $request)
    {
        // 1) Verificación de firma HMAC (opcional pero recomendada)
        $appSecret = config('services.whatsapp.app_secret');
        if (!empty($appSecret)) {
            $signatureHeader = $request->header('X-Hub-Signature-256'); // "sha256=<hex>"
            $rawBody = $request->getContent();

            if (!$this->isValidSignature($rawBody, $signatureHeader, $appSecret)) {
                Log::warning('[WA webhook] Firma inválida', [
                    'has_header' => (bool)$signatureHeader,
                    'header' => $signatureHeader,
                    'len_raw' => strlen($rawBody),
                ]);
                // En puesta en marcha puedes devolver 200 para no perder eventos; en prod devuelve 403.
                return response('Invalid signature', Response::HTTP_FORBIDDEN);
            }
        }

        // 2) Payload completo para auditoría
        $payload = $request->all();
        Log::info('[WA webhook] payload', $payload);

        // 3) Estructura base
        $entry   = $payload['entry'][0] ?? [];
        $changes = $entry['changes'][0] ?? [];
        $value   = $changes['value'] ?? [];

        // 4) Status de mensajes salientes (sent/delivered/read/failed)
        if (!empty($value['statuses'])) {
            foreach ($value['statuses'] as $st) {
                $this->handleStatus($st);
            }
        }

        // 5) Mensajes entrantes de usuarios (abre ventana de 24 h)
        if (!empty($value['messages'])) {
            foreach ($value['messages'] as $msg) {
                $this->handleIncoming($msg, $value);
            }
        }

        // 6) Cambios de estado de plantillas (approved/rejected)
        if (!empty($value['message_template_id']) || !empty($value['event'])) {
            $this->handleTemplateUpdate($value);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Verifica firma X-Hub-Signature-256 = "sha256=<hex>" usando el App Secret.
     */
    private function isValidSignature(string $rawBody, ?string $header, string $appSecret): bool
    {
        if (!$header || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        $given = strtolower(substr($header, 7)); // hex del header (sin "sha256=")
        $calc  = hash_hmac('sha256', $rawBody, $appSecret); // hex lowercase
        return hash_equals($calc, $given);
    }

    /**
     * Manejo de statuses de mensajes.
     * Ejemplo de status:
     * [
     *   "id" => "wamid.HBgMNTc...==",
     *   "status" => "delivered|sent|read|failed",
     *   "timestamp" => "1727719825",
     *   "recipient_id" => "57XXXXXXXXXX",
     *   "errors" => [ ... ] // si failed
     * ]
     */
    private function handleStatus(array $st): void
    {
        $id     = $st['id'] ?? null;              // message id (wamid)
        $status = $st['status'] ?? null;          // sent|delivered|read|failed
        $to     = $st['recipient_id'] ?? null;    // E164 sin "+"
        $ts     = $st['timestamp'] ?? null;
        $error  = $st['errors'][0] ?? null;

        Log::info('[WA status]', compact('id', 'status', 'to', 'ts', 'error'));

        // TODO: aquí puedes actualizar tu tabla de envíos, ej:
        // Outbox::where('wa_message_id', $id)->update([
        //     'status' => $status,
        //     'delivered_at' => $status === 'delivered' ? now() : null,
        //     'read_at' => $status === 'read' ? now() : null,
        //     'error_code' => $error['code'] ?? null,
        //     'error_title' => $error['title'] ?? null,
        // ]);
    }

    /**
     * Manejo de mensajes entrantes.
     * Ejemplo de message:
     * [
     *   "from" => "57XXXXXXXXXX",
     *   "id" => "wamid.HBgMNTc...==",
     *   "timestamp" => "1727719830",
     *   "type" => "text|interactive|image|button|... ",
     *   "text" => ["body" => "Hola"],
     * ]
     */
    private function handleIncoming(array $msg, array $value): void
    {
        $from   = $msg['from'] ?? null; // E164 sin "+"
        $type   = $msg['type'] ?? null;
        $text   = $type === 'text' ? ($msg['text']['body'] ?? null) : null;
        $ts     = $msg['timestamp'] ?? null;

        Log::info('[WA incoming]', compact('from', 'type', 'text', 'ts'));

        // TODO: normaliza número a +57XXXXXXXXXX si trabajas así:
        // $fromE164 = '+' . $from;

        // TODO: guarda/actualiza contacto y marca ventana 24h abierta:
        // Contact::firstOrCreate(['phone'=>$fromE164], ...);
        // Contact::where('phone', $fromE164)->update(['last_incoming_at'=>now()]);

        // TODO: si tu flujo reconoce "1/2/3" para CONFIRMAR/CANCELAR/REPROGRAMAR:
        // $this->registrarRespuestaCita($fromE164, $text);

        // Si vienen interacciones (botones, list replies), parsea:
        // if ($type === 'interactive') { ... }
    }

    /**
     * Manejo de updates de plantillas (aprobaciones, rechazos, etc.)
     * La Cloud API puede enviar eventos de template review dependiendo de la suscripción.
     */
    private function handleTemplateUpdate(array $value): void
    {
        Log::info('[WA template update]', $value);

        // Ejemplos de campos útiles (dependen del evento recibido):
        // $templateId = $value['message_template_id'] ?? null;
        // $event      = $value['event'] ?? null; // e.g., "TEMPLATE_STATUS_UPDATE"
        // $status     = $value['message_template_update']['status'] ?? null; // approved/rejected
        // TODO: persiste cambios de estado de plantillas si te interesa
    }

    /* =========================================================
     * Helpers opcionales para tu dominio (ejemplos)
     * ========================================================= */

    // private function registrarRespuestaCita(string $fromE164, ?string $text): void
    // {
    //     if (!$text) return;
    //     $t = trim(mb_strtolower($text));
    //     if (in_array($t, ['1','si','sí','confirmo','confirmar'])) {
    //         // TODO: marca confirmación en tu tabla 'confirmaciones'
    //     } elseif (in_array($t, ['2','no','cancelar'])) {
    //         // TODO: marca cancelación
    //     } elseif (in_array($t, ['3','reprogramar'])) {
    //         // TODO: marca reprogramar y lanza flujo de contacto
    //     }
    // }
}
