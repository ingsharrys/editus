<?php

namespace Tests\Feature;

use App\Models\PagoSuscripcion;
use App\Models\Role;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\RegionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Sitio en español e inglés; fuera de Colombia, precios en dólares y pago con Square. */
class IdiomasYPagosInternacionalesTest extends TestCase
{
    private User $cliente;
    private array $llamadas = [];
    private int $monto = 2500;
    private string $estadoPago = 'COMPLETED';
    private bool $falso = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.wompi.env' => 'sandbox', 'services.wompi.public_key' => 'pub_test_abc', 'services.wompi.integrity_secret' => 'test_integrity', 'services.wompi.events_secret' => 'test_events',
            'services.square.env' => 'sandbox', 'services.square.access_token' => 'EAAA-prueba', 'services.square.location_id' => 'LOC1',
            'services.square.webhook_signature_key' => 'firma-square-prueba', 'services.square.webhook_url' => 'https://app.editus.online/webhooks/square',
            'region.geoip_url' => 'https://geo.prueba/{ip}/country/',
        ]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->text('page_access_token')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        User::factory()->create(['name' => 'Equipo']); // existente → exento
        (require database_path('migrations/2026_10_13_000001_create_suscripciones.php'))->up();
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        (require database_path('migrations/2026_10_21_000001_idiomas_y_pagos_internacionales.php'))->up();
        $this->cliente = User::factory()->create(['name' => 'Cliente', 'email' => 'cliente@prueba.co', 'role_id' => Role::where('slug', 'user')->value('id')]);
        Cache::flush();
    }

    /** Square de mentira: enlace de pago, orden con su pago, y el pago con el monto y estado que se indiquen. */
    private function square(int $monto = 2500, string $estado = 'COMPLETED'): void
    {
        [$this->monto, $this->estadoPago] = [$monto, $estado];
        if ($this->falso) return;
        $this->falso = true;
        Http::fake(function (PeticionHttp $r) {
            $this->llamadas[] = ['url' => $r->url(), 'metodo' => $r->method(), 'datos' => $r->data(), 'auth' => $r->header('Authorization')[0] ?? null];
            $u = $r->url();
            if (str_contains($u, '/v2/online-checkout/payment-links')) return Http::response(['payment_link' => ['id' => 'PL1', 'url' => 'https://square.link/u/abc123', 'order_id' => 'ORD-1']]);
            if (str_contains($u, '/v2/orders/ORD-1')) return Http::response(['order' => ['id' => 'ORD-1', 'state' => 'OPEN', 'tenders' => [['id' => 'T1', 'payment_id' => 'PAY-1']]]]);
            if (str_contains($u, '/v2/payments/PAY-1')) return Http::response(['payment' => ['id' => 'PAY-1', 'order_id' => 'ORD-1', 'status' => $this->estadoPago, 'source_type' => 'CARD',
                'amount_money' => ['amount' => $this->monto, 'currency' => 'USD'], 'card_details' => ['card' => ['card_brand' => 'VISA']], 'receipt_url' => 'https://squareup.com/receipt/preview/PAY-1']]);
            if (str_contains($u, 'geo.prueba')) return Http::response('MX', 200);
            return Http::response([], 404);
        });
    }

    /** Cada petición empieza limpia (las cabeceras, cookies y variables del servidor de las pruebas se acumulan). */
    private function limpio(): static
    {
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];
        return $this->withHeader('Accept-Language', 'es-CO,es;q=0.9');
    }

    public function test_el_idioma_sale_del_navegador_y_se_puede_elegir(): void
    {
        $this->limpio()->get('/planes')->assertOk()->assertSee('Elige tu plan y empieza hoy')->assertSee('<html lang="es">', false);
        // Plurales (trans_choice) también en español
        $this->limpio()->get('/planes')->assertSee('Plugin SharryStreem: autopost en 1 sitio WordPress')->assertSee('autopost en 3 sitios WordPress');
        $this->limpio()->withHeader('Accept-Language', 'en-US,en;q=0.9')->get('/planes')->assertOk()->assertSee('Choose your plan and start today')->assertSee('<html lang="en">', false)->assertSee('autopost on 3 WordPress sites');
        // Otro idioma que no tenemos (portugués) → inglés
        $this->limpio()->withHeader('Accept-Language', 'pt-BR')->get('/planes')->assertSee('Choose your plan and start today');

        // Elegirlo a mano: cookie que gana sobre el navegador, y queda en el perfil
        $r = $this->limpio()->actingAs($this->cliente)->from('/planes')->get('/idioma/en')->assertRedirect('/planes');
        $r->assertCookie(RegionService::COOKIE_IDIOMA, 'en');
        $this->assertSame('en', $this->cliente->fresh()->idioma);
        $this->limpio()->withCookie(RegionService::COOKIE_IDIOMA, 'en')->get('/planes')->assertSee('Choose your plan and start today');
        $this->limpio()->get('/idioma/fr')->assertNotFound();

        // Con sesión, el perfil manda aunque el navegador diga otra cosa
        $this->limpio()->actingAs($this->cliente->fresh())->withHeader('Accept-Language', 'es-CO')->get('/profile')->assertOk()->assertSee('My profile')->assertSee('Personal details');
        // Mensajes del servidor y validación también en inglés
        $this->limpio()->actingAs($this->cliente->fresh())->get('/admin/usuarios')->assertForbidden()->assertSee('Your role doesn’t have permission for this module.', false);
        $this->limpio()->actingAs($this->cliente->fresh())->patch('/profile', ['name' => '', 'email' => 'cliente@prueba.co'])->assertSessionHasErrors(['name' => 'The name field is required.']);
        $this->cliente->forceFill(['idioma' => 'es'])->save();
        $this->limpio()->actingAs($this->cliente->fresh())->patch('/profile', ['name' => '', 'email' => 'cliente@prueba.co'])->assertSessionHasErrors(['name' => 'nombre es obligatorio.']);
    }

    public function test_fuera_de_colombia_los_precios_salen_en_dolares(): void
    {
        $this->limpio()->get('/planes')->assertSee('$60.000')->assertSee('COP / mes')->assertSee('Wompi');
        $this->limpio()->withHeader('CF-IPCountry', 'CO')->get('/planes')->assertSee('$60.000');
        $this->limpio()->withHeader('CF-IPCountry', 'US')->get('/planes')->assertSee('$15')->assertSee('USD / mes')->assertSee('Square')->assertDontSee('$60.000');
        $this->limpio()->withHeader('CF-IPCountry', 'US')->withHeader('Accept-Language', 'en')->get('/planes')->assertSee('USD / month')->assertSee('$275');

        // Sin cabecera del proxy: se consulta el país por IP una vez (queda en caché)
        $this->square();
        $this->limpio()->withServerVariables(['REMOTE_ADDR' => '201.141.1.1'])->get('/planes')->assertSee('USD / mes');
        $this->limpio()->withServerVariables(['REMOTE_ADDR' => '201.141.1.1'])->get('/planes')->assertSee('USD / mes');
        $this->assertCount(1, array_filter($this->llamadas, fn($l) => str_contains($l['url'], 'geo.prueba/201.141.1.1')));

        // La persona puede cambiar la moneda a mano
        $this->limpio()->withHeader('CF-IPCountry', 'US')->get('/moneda/COP')->assertCookie(RegionService::COOKIE_MONEDA, 'COP');
        $this->limpio()->withHeader('CF-IPCountry', 'US')->withCookie(RegionService::COOKIE_MONEDA, 'COP')->get('/planes')->assertSee('$60.000');
        $this->limpio()->withCookie(RegionService::COOKIE_MONEDA, 'USD')->get('/suscribirse?plan=full&periodo=anual')->assertSee('$275')->assertSee('Square');
        $this->limpio()->get('/moneda/EUR')->assertNotFound();
    }

    public function test_pago_con_square_en_dolares_activa_el_plan(): void
    {
        $this->square();
        $r = $this->limpio()->actingAs($this->cliente)->withHeader('CF-IPCountry', 'US')->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $r->assertRedirect('https://square.link/u/abc123');
        $pago = PagoSuscripcion::firstOrFail();
        $this->assertSame(['USD', 2500, 'square', 'ORD-1', 'PENDING'], [$pago->moneda, (int) $pago->monto_centavos, $pago->pasarela, $pago->externo_id, $pago->estado]);
        $enlace = collect($this->llamadas)->first(fn($l) => str_contains($l['url'], 'payment-links'));
        $this->assertSame('https://connect.squareupsandbox.com/v2/online-checkout/payment-links', $enlace['url']);
        $this->assertSame('Bearer EAAA-prueba', $enlace['auth']);
        $this->assertSame(['amount' => 2500, 'currency' => 'USD'], $enlace['datos']['quick_pay']['price_money']);
        $this->assertSame('LOC1', $enlace['datos']['quick_pay']['location_id']);
        $this->assertSame($pago->referencia, $enlace['datos']['idempotency_key']);
        $this->assertSame(route('suscripcion.square', ['ref' => $pago->referencia]), $enlace['datos']['checkout_options']['redirect_url']);

        // Vuelve de Square: se consulta la orden y el pago (no se confía en la URL)
        $this->limpio()->actingAs($this->cliente)->get('/suscripcion/square?ref=' . $pago->referencia . '&orderId=ORD-1&transactionId=falso')
            ->assertRedirect(route('suscripcion.index'))->assertSessionHas('success', '¡Pago aprobado! Tu plan está activo.');
        $pago->refresh();
        $this->assertSame(['APPROVED', 'VISA'], [$pago->estado, $pago->metodo]);
        $s = Suscripcion::where('user_id', $this->cliente->id)->firstOrFail();
        $this->assertTrue($s->activa());
        $this->assertSame('full', $s->plan);

        // Otro usuario no puede usar esa referencia
        $otro = User::factory()->create(['role_id' => $this->cliente->role_id]);
        $this->limpio()->actingAs($otro)->get('/suscripcion/square?ref=' . $pago->referencia)->assertSessionHas('error', 'El pago no corresponde a tu cuenta.');

        // El historial muestra el monto en dólares y la pasarela
        $this->limpio()->actingAs($this->cliente)->get('/suscripcion')->assertOk()->assertSee('US$25')->assertSee('Square');
    }

    public function test_square_rechaza_montos_alterados_y_pagos_pendientes(): void
    {
        $this->square(100); // pagó 1 dólar por un plan de 25
        $this->limpio()->actingAs($this->cliente)->withCookie(RegionService::COOKIE_MONEDA, 'USD')->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->limpio()->actingAs($this->cliente)->get('/suscripcion/square?ref=' . $pago->referencia)->assertSessionHas('error');
        $this->assertSame('ERROR', $pago->fresh()->estado);
        $this->assertNull(Suscripcion::where('user_id', $this->cliente->id)->first()?->activa() ?: null);

        PagoSuscripcion::query()->delete();
        $this->square(2500, 'APPROVED'); // autorizado pero no cobrado todavía
        $this->limpio()->actingAs($this->cliente)->withCookie(RegionService::COOKIE_MONEDA, 'USD')->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->limpio()->actingAs($this->cliente)->get('/suscripcion/square?ref=' . $pago->referencia)->assertSessionHas('success', 'Tu pago está en proceso. Se activará apenas Square lo confirme.');
        $this->assertSame('PENDING', $pago->fresh()->estado);
    }

    public function test_el_webhook_de_square_verifica_la_firma_y_activa_el_plan(): void
    {
        $this->square();
        $this->limpio()->actingAs($this->cliente)->withCookie(RegionService::COOKIE_MONEDA, 'USD')->post('/suscripcion/pagar', ['plan' => 'basico', 'periodo' => 'anual']);
        $pago = PagoSuscripcion::firstOrFail();
        $this->assertSame(16500, (int) $pago->monto_centavos);
        $this->square(16500);

        $cuerpo = json_encode(['type' => 'payment.updated', 'event_id' => 'e1', 'data' => ['type' => 'payment', 'id' => 'PAY-1', 'object' => ['payment' => ['id' => 'PAY-1', 'order_id' => 'ORD-1', 'status' => 'COMPLETED']]]]);
        $firma = base64_encode(hash_hmac('sha256', 'https://app.editus.online/webhooks/square' . $cuerpo, 'firma-square-prueba', true));
        $this->limpio()->call('POST', '/webhooks/square', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => 'mala'], $cuerpo)->assertStatus(401);
        $this->assertSame('PENDING', $pago->fresh()->estado);
        $this->limpio()->call('POST', '/webhooks/square', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $firma], $cuerpo)->assertOk();
        $this->assertSame('APPROVED', $pago->fresh()->estado);
        $this->assertSame('basico', Suscripcion::where('user_id', $this->cliente->id)->value('plan'));
        // Repetido: no vuelve a sumar días
        $vence = Suscripcion::where('user_id', $this->cliente->id)->value('vence_en');
        $this->limpio()->call('POST', '/webhooks/square', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $firma], $cuerpo)->assertOk();
        $this->assertEquals($vence, Suscripcion::where('user_id', $this->cliente->id)->value('vence_en'));
    }

    public function test_suscribirse_desde_el_exterior_crea_la_cuenta_y_va_a_square(): void
    {
        $this->square();
        $r = $this->limpio()->withHeader('CF-IPCountry', 'MX')->withHeader('Accept-Language', 'es-MX')->post('http://editus.online/suscribirse', ['plan' => 'basico', 'periodo' => 'mensual', 'name' => 'Diario de Monterrey', 'email' => 'mty@diario.mx', 'password' => 'Clave-segura-123', 'password_confirmation' => 'Clave-segura-123', 'acepto' => 1]);
        $r->assertRedirect('https://square.link/u/abc123');
        $u = User::where('email', 'mty@diario.mx')->firstOrFail();
        $this->assertSame(['MX', 'es'], [$u->pais, $u->idioma]);
        $this->assertSame(['USD', 1500], [PagoSuscripcion::where('user_id', $u->id)->value('moneda'), (int) PagoSuscripcion::where('user_id', $u->id)->value('monto_centavos')]);

        // Desde Colombia (otra persona, sin sesión) sigue yendo a Wompi en pesos
        \Illuminate\Support\Facades\Auth::logout();
        $r = $this->limpio()->withHeader('CF-IPCountry', 'CO')->post('http://editus.online/suscribirse', ['plan' => 'basico', 'periodo' => 'mensual', 'name' => 'Diario del Huila', 'email' => 'huila@diario.co', 'password' => 'Clave-segura-123', 'password_confirmation' => 'Clave-segura-123', 'acepto' => 1]);
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $r->headers->get('Location'));
    }

    public function test_sin_square_configurado_no_se_cobra_en_dolares(): void
    {
        config(['services.square.access_token' => '']);
        $this->limpio()->actingAs($this->cliente)->withCookie(RegionService::COOKIE_MONEDA, 'USD')->get('/suscripcion')->assertSee('Los pagos en línea aún no están configurados.');
        $this->limpio()->actingAs($this->cliente)->withCookie(RegionService::COOKIE_MONEDA, 'USD')->post('/suscripcion/pagar', ['plan' => 'full', 'periodo' => 'mensual'])->assertSessionHas('error');
        $this->assertSame(0, PagoSuscripcion::count());
    }
}
