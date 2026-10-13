<?php

namespace Tests\Feature;

use App\Models\PagoSuscripcion;
use App\Models\Role;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Pagos internacionales en dólares con PayPal (orden → aprobación → captura → plan activo). */
class PayPalTest extends TestCase
{
    private User $cliente;
    private array $llamadas = [];
    private string $estadoOrden = 'APPROVED';
    private string $valorCaptura = '25.00';
    private string $estadoCaptura = 'COMPLETED';
    private bool $webhookOk = true;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pasarela_internacional' => 'paypal',
            'services.paypal.env' => 'sandbox', 'services.paypal.client_id' => 'cliente-pp', 'services.paypal.client_secret' => 'secreto-pp', 'services.paypal.webhook_id' => 'WH-1',
            'services.wompi.public_key' => 'pub_test', 'services.wompi.integrity_secret' => 'int',
            'region.geoip_url' => '',
        ]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->text('page_access_token')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        User::factory()->create(['name' => 'Equipo']);
        (require database_path('migrations/2026_10_13_000001_create_suscripciones.php'))->up();
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        (require database_path('migrations/2026_10_21_000001_idiomas_y_pagos_internacionales.php'))->up();
        $this->cliente = User::factory()->create(['name' => 'Cliente', 'email' => 'cliente@prueba.com', 'role_id' => Role::where('slug', 'user')->value('id'), 'pais' => 'US']);
        Cache::flush();

        Http::fake(function (PeticionHttp $r) {
            $u = $r->url();
            $this->llamadas[] = ['url' => $u, 'metodo' => $r->method(), 'datos' => $r->data(), 'cabeceras' => $r->headers()];
            $captura = ['id' => 'CAP-1', 'status' => $this->estadoCaptura, 'amount' => ['currency_code' => 'USD', 'value' => $this->valorCaptura], 'update_time' => '2026-10-13T15:00:00Z'];
            $orden = fn($st, $conCaptura) => ['id' => 'ORD-9', 'status' => $st, 'payer' => ['email_address' => 'buyer@example.com'], 'payment_source' => ['paypal' => ['email_address' => 'buyer@example.com']],
                'purchase_units' => [['reference_id' => 'x', 'payments' => $conCaptura ? ['captures' => [$captura]] : []]]];
            if (str_ends_with($u, '/v1/oauth2/token')) return Http::response(['access_token' => 'A21-token', 'expires_in' => 32400]);
            if (str_ends_with($u, '/v2/checkout/orders') && $r->method() === 'POST') return Http::response(['id' => 'ORD-9', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                ['rel' => 'self', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORD-9'], ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORD-9']]], 201);
            if (str_ends_with($u, '/v2/checkout/orders/ORD-9/capture')) {
                $this->estadoOrden = 'COMPLETED';
                return Http::response($orden('COMPLETED', true), 201);
            }
            if (str_ends_with($u, '/v2/checkout/orders/ORD-9')) return Http::response($orden($this->estadoOrden, $this->estadoOrden === 'COMPLETED'));
            if (str_ends_with($u, '/v1/notifications/verify-webhook-signature')) return Http::response(['verification_status' => $this->webhookOk ? 'SUCCESS' : 'FAILURE']);
            return Http::response([], 404);
        });
    }

    private function capturas(): int
    {
        return count(array_filter($this->llamadas, fn($l) => str_ends_with($l['url'], '/capture')));
    }

    public function test_desde_el_exterior_se_paga_con_paypal_y_se_activa_el_plan(): void
    {
        $this->withHeader('CF-IPCountry', 'US')->get('/planes')->assertSee('PayPal')->assertSee('USD / month')->assertDontSee('Square');
        $this->flushHeaders()->withHeader('Accept-Language', 'es-CO');

        $r = $this->actingAs($this->cliente)->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $r->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORD-9');
        $pago = PagoSuscripcion::firstOrFail();
        $this->assertSame(['paypal', 'ORD-9', 'USD', 2500, 'PENDING'], [$pago->pasarela, $pago->externo_id, $pago->moneda, (int) $pago->monto_centavos, $pago->estado]);

        $token = collect($this->llamadas)->first(fn($l) => str_ends_with($l['url'], '/v1/oauth2/token'));
        $this->assertSame('Basic ' . base64_encode('cliente-pp:secreto-pp'), $token['cabeceras']['Authorization'][0]);
        $orden = collect($this->llamadas)->first(fn($l) => str_ends_with($l['url'], '/v2/checkout/orders'));
        $this->assertSame('https://api-m.sandbox.paypal.com/v2/checkout/orders', $orden['url']);
        $this->assertSame('Bearer A21-token', $orden['cabeceras']['Authorization'][0]);
        $this->assertSame('orden-' . $pago->referencia, $orden['cabeceras']['PayPal-Request-Id'][0]);
        $this->assertSame(['currency_code' => 'USD', 'value' => '25.00'], $orden['datos']['purchase_units'][0]['amount']);
        $this->assertSame($pago->referencia, $orden['datos']['purchase_units'][0]['custom_id']);
        $this->assertSame(route('suscripcion.paypal', ['ref' => $pago->referencia]), $orden['datos']['payment_source']['paypal']['experience_context']['return_url']);

        // Vuelve de PayPal: editus captura la orden y activa el plan
        $this->actingAs($this->cliente)->get('/suscripcion/paypal?ref=' . $pago->referencia . '&token=ORD-9&PayerID=P1')
            ->assertRedirect(route('suscripcion.index'))->assertSessionHas('success', '¡Pago aprobado! Tu plan está activo.');
        $this->assertSame(['APPROVED', 'PAYPAL'], [$pago->fresh()->estado, $pago->fresh()->metodo]);
        $this->assertTrue(Suscripcion::where('user_id', $this->cliente->id)->firstOrFail()->activa());
        $this->assertSame(1, $this->capturas());

        // Recargar el retorno no vuelve a capturar ni suma días
        $vence = Suscripcion::where('user_id', $this->cliente->id)->value('vence_en');
        $this->actingAs($this->cliente)->get('/suscripcion/paypal?ref=' . $pago->referencia)->assertSessionHas('success');
        $this->assertSame(1, $this->capturas());
        $this->assertEquals($vence, Suscripcion::where('user_id', $this->cliente->id)->value('vence_en'));

        // Otro usuario no puede usar esa referencia
        $otro = User::factory()->create(['role_id' => $this->cliente->role_id]);
        $this->actingAs($otro)->get('/suscripcion/paypal?ref=' . $pago->referencia)->assertSessionHas('error', 'El pago no corresponde a tu cuenta.');
        $this->actingAs($this->cliente)->get('/suscripcion')->assertSee('US$25')->assertSee('PayPal');
    }

    public function test_monto_alterado_pendiente_y_cancelado(): void
    {
        $this->valorCaptura = '1.00';
        $this->actingAs($this->cliente)->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->actingAs($this->cliente)->get('/suscripcion/paypal?ref=' . $pago->referencia)->assertSessionHas('error');
        $this->assertSame('ERROR', $pago->fresh()->estado);
        $this->assertNull(Suscripcion::where('user_id', $this->cliente->id)->first());

        // Captura pendiente (revisión de PayPal): no activa todavía
        PagoSuscripcion::query()->delete();
        [$this->valorCaptura, $this->estadoCaptura, $this->estadoOrden] = ['25.00', 'PENDING', 'APPROVED'];
        $this->actingAs($this->cliente)->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->actingAs($this->cliente)->get('/suscripcion/paypal?ref=' . $pago->referencia)->assertSessionHas('success', 'Tu pago está en proceso. Se activará apenas PayPal lo confirme.');
        $this->assertSame('PENDING', $pago->fresh()->estado);

        // Cancelado en PayPal
        PagoSuscripcion::query()->delete();
        $this->actingAs($this->cliente)->post('/suscripcion/pagar', ['plan' => 'basico', 'periodo' => 'mensual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->actingAs($this->cliente)->get('/suscripcion/paypal/cancelar?ref=' . $pago->referencia . '&token=ORD-9')->assertSessionHas('error');
        $this->assertSame('VOIDED', $pago->fresh()->estado);
    }

    public function test_el_webhook_se_verifica_con_paypal_y_activa_el_plan(): void
    {
        $this->actingAs($this->cliente)->post('/suscripcion/pagar', ['plan' => 'basico', 'periodo' => 'anual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->assertSame(16500, (int) $pago->monto_centavos);
        $this->valorCaptura = '165.00';
        $evento = json_encode(['id' => 'WH-EV-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => 'ORD-9', 'status' => 'APPROVED']]);
        $cab = ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert', 'HTTP_PAYPAL_TRANSMISSION_ID' => 't1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'firma', 'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-10-13T15:00:00Z'];

        // Sin cabeceras de PayPal o con verificación fallida: rechazado
        $this->call('POST', '/webhooks/paypal', [], [], [], ['CONTENT_TYPE' => 'application/json'], $evento)->assertStatus(401);
        $this->webhookOk = false;
        $this->call('POST', '/webhooks/paypal', [], [], [], $cab, $evento)->assertStatus(401);
        $this->assertSame('PENDING', $pago->fresh()->estado);

        // Verificado: captura la orden aprobada (aunque la persona no haya vuelto a editus) y activa el plan
        $this->webhookOk = true;
        $this->call('POST', '/webhooks/paypal', [], [], [], $cab, $evento)->assertOk();
        $this->assertSame('APPROVED', $pago->fresh()->estado);
        $this->assertSame('basico', Suscripcion::where('user_id', $this->cliente->id)->value('plan'));
        $verif = collect($this->llamadas)->first(fn($l) => str_ends_with($l['url'], 'verify-webhook-signature'));
        $this->assertSame('WH-1', $verif['datos']['webhook_id']);
        $this->assertSame('ORD-9', $verif['datos']['webhook_event']['resource']['id']);

        // PAYMENT.CAPTURE.COMPLETED después: no captura de nuevo
        $cap = json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP-1', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORD-9']]]]);
        $this->call('POST', '/webhooks/paypal', [], [], [], $cab, $cap)->assertOk();
        $this->assertSame(1, $this->capturas());
    }

    public function test_sin_llaves_de_paypal_no_se_cobra_y_en_colombia_sigue_wompi(): void
    {
        config(['services.paypal.client_id' => '']);
        $this->actingAs($this->cliente)->get('/suscripcion')->assertSee('Los pagos en línea aún no están configurados.');
        $this->actingAs($this->cliente)->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual'])->assertSessionHas('error');
        $this->assertSame(0, PagoSuscripcion::count());

        $this->cliente->forceFill(['pais' => 'CO'])->save();
        $r = $this->actingAs($this->cliente->fresh())->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $r->headers->get('Location'));
        $this->assertSame(['wompi', 'COP'], [PagoSuscripcion::first()->pasarela, PagoSuscripcion::first()->moneda]);
    }
}
