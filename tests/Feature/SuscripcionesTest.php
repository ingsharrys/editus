<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\PagoSuscripcion;
use App\Models\Role;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\PlanService;
use App\Services\WompiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Planes, pagos con Wompi, límites y API firmada del plugin SharryStreem. */
class SuscripcionesTest extends TestCase
{
    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.facebook.version' => 'v23.0',
            'services.wompi.env' => 'sandbox', 'services.wompi.public_key' => 'pub_test_abc',
            'services.wompi.integrity_secret' => 'test_integrity_secreto', 'services.wompi.events_secret' => 'test_events_secreto',
        ]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        User::factory()->create(['name' => 'Equipo']); // usuario existente → queda exento
        (require database_path('migrations/2026_10_13_000001_create_suscripciones.php'))->up();

        Role::firstOrCreate(['slug' => 'user'], ['name' => 'Usuario']);
        $this->cliente = User::factory()->create(['name' => 'Cliente', 'email' => 'cliente@prueba.co', 'role_id' => Role::where('slug', 'user')->value('id')]);
        foreach (['101' => 'Diario Uno', '102' => 'Diario Dos', '103' => 'Diario Tres'] as $id => $nombre) {
            $p = MetaPage::create(['page_id' => $id, 'name' => $nombre]);
            $p->users()->attach($this->cliente->id, ['page_access_token' => 'tok-' . $id, 'is_active' => true, 'created_at' => now()->addSeconds((int) $id)]);
        }
    }

    private function pagar(string $plan = 'basico', string $periodo = 'mensual'): PagoSuscripcion
    {
        return PagoSuscripcion::create(['user_id' => $this->cliente->id, 'plan' => $plan, 'periodo' => $periodo, 'referencia' => 'ED-' . uniqid(), 'monto_centavos' => app(PlanService::class)->precio($plan, $periodo) * 100, 'estado' => 'APPROVED']);
    }

    public function test_los_usuarios_existentes_quedan_exentos_y_los_nuevos_necesitan_plan(): void
    {
        $planes = app(PlanService::class);
        $this->assertTrue($planes->sinLimites(User::where('name', 'Equipo')->first()));
        $this->assertFalse($planes->sinLimites($this->cliente));
        $this->assertNull($planes->limites($this->cliente));
        $this->assertStringContainsString('plan activo', $planes->validarPaginas($this->cliente, [1]));
        $this->assertSame(60000, $planes->precio('basico', 'mensual'));
        $this->assertSame(660000, $planes->precio('basico', 'anual'));
        $this->assertSame(990000, $planes->precio('full', 'anual'));
    }

    public function test_aplicar_pago_activa_extiende_una_sola_vez_y_crea_licencia(): void
    {
        $planes = app(PlanService::class);
        $pago = $this->pagar();
        $s = $planes->aplicarPago($pago);
        $this->assertTrue($s->activa());
        $this->assertEqualsWithDelta(30, now()->diffInDays($s->vence_en), 1);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $s->licencia_prefijo);
        $this->assertSame(64, strlen($s->licencia_hash));
        // Idempotente: el mismo pago no vuelve a extender
        $this->assertTrue($planes->aplicarPago($pago)->vence_en->eq($s->vence_en));
        // Renovar el mismo plan suma al vencimiento; el anual da 365 días
        $s2 = $planes->aplicarPago($this->pagar('basico', 'anual'));
        $this->assertEqualsWithDelta(395, now()->diffInDays($s2->vence_en), 1);
        // Cambiar a Full empieza un periodo nuevo desde hoy
        $s3 = $planes->aplicarPago($this->pagar('full'));
        $this->assertSame('full', $s3->plan);
        $this->assertEqualsWithDelta(30, now()->diffInDays($s3->vence_en), 1);
    }

    public function test_paginas_del_plan_basico_limitadas_a_dos_y_elegibles(): void
    {
        $planes = app(PlanService::class);
        $planes->aplicarPago($this->pagar());
        $ids = MetaPage::orderBy('page_id')->pluck('id')->all();
        $this->assertSame([$ids[0], $ids[1]], $planes->paginasPermitidas($this->cliente)->pluck('id')->all());
        $this->assertNull($planes->validarPaginas($this->cliente, [$ids[0], $ids[1]]));
        $this->assertStringContainsString('2 página(s)', $planes->validarPaginas($this->cliente, [$ids[2]]));
        $planes->elegirPaginas($this->cliente, [$ids[2], $ids[0], $ids[1]]); // se recorta a 2
        $this->assertCount(2, Suscripcion::first()->paginas);
        $this->assertNull($planes->validarPaginas($this->cliente, [$ids[2]]));
    }

    public function test_wompi_checkout_firmado_y_webhook_activa_el_plan(): void
    {
        $w = app(WompiService::class);
        $this->assertSame(hash('sha256', 'REF1' . '6000000' . 'COP' . 'test_integrity_secreto'), $w->firmaIntegridad('REF1', 6000000));

        // El cliente elige plan → pago pendiente y redirección al checkout con la firma
        $r = $this->actingAs($this->cliente)->post(route('suscripcion.pagar'), ['plan' => 'basico', 'periodo' => 'mensual']);
        $pago = PagoSuscripcion::first();
        $this->assertSame('PENDING', $pago->estado);
        $this->assertSame(6000000, (int) $pago->monto_centavos);
        $destino = $r->headers->get('Location');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $destino);
        $this->assertStringContainsString('signature%3Aintegrity=' . $w->firmaIntegridad($pago->referencia, 6000000), $destino);

        // Evento de Wompi: firma inválida → rechazado
        $tx = ['id' => '1234-abc', 'status' => 'APPROVED', 'amount_in_cents' => 6000000, 'currency' => 'COP', 'reference' => $pago->referencia, 'payment_method_type' => 'PSE'];
        Http::fake(['sandbox.wompi.co/v1/transactions/1234-abc' => Http::response(['data' => $tx])]);
        $evento = ['event' => 'transaction.updated', 'data' => ['transaction' => $tx], 'timestamp' => 1700000000,
            'signature' => ['properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], 'checksum' => 'malo']];
        $this->postJson('/webhooks/wompi', $evento)->assertStatus(401);
        $this->assertNull(Suscripcion::first());

        // Firma correcta → se consulta la transacción en Wompi y se activa
        $evento['signature']['checksum'] = hash('sha256', '1234-abc' . 'APPROVED' . '6000000' . '1700000000' . 'test_events_secreto');
        $this->postJson('/webhooks/wompi', $evento)->assertOk();
        $this->assertTrue(Suscripcion::first()->activa());
        $this->assertSame('APPROVED', $pago->fresh()->estado);
        // Repetir el evento no extiende de nuevo
        $vence = Suscripcion::first()->vence_en;
        $this->postJson('/webhooks/wompi', $evento)->assertOk();
        $this->assertTrue(Suscripcion::first()->vence_en->eq($vence));
    }

    public function test_el_retorno_verifica_con_wompi_y_rechaza_montos_alterados(): void
    {
        $pago = PagoSuscripcion::create(['user_id' => $this->cliente->id, 'plan' => 'full', 'periodo' => 'mensual', 'referencia' => 'ED-X', 'monto_centavos' => 9000000, 'estado' => 'PENDING']);
        Http::fake(['sandbox.wompi.co/v1/transactions/tx-1' => Http::response(['data' => ['id' => 'tx-1', 'status' => 'APPROVED', 'amount_in_cents' => 100, 'currency' => 'COP', 'reference' => 'ED-X']])]);
        $this->actingAs($this->cliente)->get(route('suscripcion.resultado', ['id' => 'tx-1']))->assertRedirect(route('suscripcion.index'));
        $this->assertSame('ERROR', $pago->fresh()->estado);
        $this->assertNull(Suscripcion::first());

        $this->actingAs($this->cliente)->get(route('suscripcion.index'))->assertOk()->assertSee('Mi suscripción')->assertSee('$60.000')->assertSee('$90.000')->assertSee('Anual $990.000');
    }

    // ------------------------------------------------------------ plugin

    private function firmar(string $metodo, string $ruta, string $llave, string $sitio, string $cuerpo = '', ?int $ts = null, ?string $nonce = null): array
    {
        $ts = $ts ?? time();
        $nonce = $nonce ?? bin2hex(random_bytes(12));
        $prefijo = PlanService::prefijoDe($llave);
        $firma = hash_hmac('sha256', implode("\n", [$ts, $nonce, $metodo, $ruta, $sitio, hash('sha256', $cuerpo)]), hash('sha256', $llave));
        return ['X-SharryStreem-Key' => 'ss_' . $prefijo, 'X-SharryStreem-Timestamp' => (string) $ts, 'X-SharryStreem-Nonce' => $nonce, 'X-SharryStreem-Site' => $sitio, 'X-SharryStreem-Signature' => $firma, 'Accept' => 'application/json', 'Content-Type' => 'application/json'];
    }

    private function llamar(string $metodo, string $accion, string $llave, string $sitio, array $cuerpo = [], ?int $ts = null, ?string $nonce = null)
    {
        $json = $cuerpo ? json_encode($cuerpo) : '';
        $ruta = '/api/sharrystreem/v1/' . $accion;
        return $this->call($metodo, $ruta, [], [], [], $this->servidor($this->firmar($metodo, $ruta, $llave, $sitio, $json, $ts, $nonce)), $json);
    }

    private function servidor(array $headers): array
    {
        $s = [];
        foreach ($headers as $k => $v) $s[in_array($k, ['Content-Type'], true) ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        return $s;
    }

    public function test_plugin_firma_sitios_vencimiento_y_autopost(): void
    {
        $planes = app(PlanService::class);
        $s = $planes->aplicarPago($this->pagar());
        $llave = $planes->generarLicencia($s);
        $sitio = 'https://midiario.co';

        // Firma inválida o llave inexistente
        $this->llamar('GET', 'estado', 'ss_' . str_repeat('a', 12) . '_' . str_repeat('B', 40), $sitio)->assertStatus(401);
        // Firma vencida
        $this->llamar('POST', 'activar', $llave, $sitio, [], time() - 900)->assertStatus(401);
        // Sitio sin activar
        $this->llamar('GET', 'estado', $llave, $sitio)->assertStatus(403);

        // Activar: vincula el sitio y devuelve las páginas del plan
        $r = $this->llamar('POST', 'activar', $llave, $sitio)->assertOk()->json();
        $this->assertSame('Básico', $r['plan_nombre']);
        $this->assertCount(2, $r['paginas']);
        // El Básico permite 1 sitio
        $this->llamar('POST', 'activar', $llave, 'https://otrositio.co')->assertStatus(409);

        // Anti-repetición: la misma firma no se acepta dos veces
        $this->llamar('GET', 'estado', $llave, $sitio, [], null, 'nonceRepetido12345678')->assertOk();
        $this->llamar('GET', 'estado', $llave, $sitio, [], null, 'nonceRepetido12345678')->assertStatus(409);
        $this->llamar('GET', 'estado', $llave, $sitio)->assertOk(); // otra petición en el mismo segundo sí pasa

        // Autopost sin imagen: enlace en Facebook; Instagram pide imagen
        Http::fake(['graph.facebook.com/v23.0/101/feed' => Http::response(['id' => '101_555'])]);
        $pid = MetaPage::where('page_id', '101')->value('id');
        $r = $this->llamar('POST', 'publicar', $llave, $sitio, ['titulo' => 'Nueva vía en Garzón', 'enlace' => 'https://midiario.co/nueva-via', 'paginas' => [$pid], 'facebook' => true, 'instagram' => true])->assertOk()->json();
        $this->assertTrue($r['resultados'][0]['facebook']['ok']);
        $this->assertFalse($r['resultados'][0]['instagram']['ok']);
        Http::assertSent(fn($req) => str_contains($req->url(), '101/feed') && $req['link'] === 'https://midiario.co/nueva-via' && $req['access_token'] === 'tok-101');

        // Página fuera del plan y enlace de otro sitio
        $fuera = MetaPage::where('page_id', '103')->value('id');
        $this->llamar('POST', 'publicar', $llave, $sitio, ['titulo' => 'x', 'enlace' => 'https://midiario.co/x', 'paginas' => [$fuera]])->assertStatus(403);
        $this->llamar('POST', 'publicar', $llave, $sitio, ['titulo' => 'x', 'enlace' => 'https://otro.co/x', 'paginas' => [$pid]])->assertStatus(422);

        // Al vencer la suscripción la licencia deja de funcionar
        Suscripcion::first()->fill(['vence_en' => now()->subMinute()])->save();
        $this->llamar('GET', 'estado', $llave, $sitio)->assertStatus(402);

        // Una llave nueva invalida la anterior
        Suscripcion::first()->fill(['vence_en' => now()->addDays(5)])->save();
        $planes->generarLicencia(Suscripcion::first());
        $this->llamar('GET', 'estado', $llave, $sitio)->assertStatus(401);
    }

    public function test_admin_activa_manual_y_marca_exento(): void
    {
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $admin = User::factory()->create(['role_id' => $rol->id]);
        $this->actingAs($admin)->post(route('admin.suscripciones.activar', $this->cliente), ['plan' => 'full', 'periodo' => 'anual', 'cobrado' => 1])->assertRedirect();
        $s = Suscripcion::first();
        $this->assertSame('full', $s->plan);
        $this->assertSame(99000000, (int) PagoSuscripcion::first()->monto_centavos);
        $this->actingAs($admin)->get(route('admin.suscripciones'))->assertOk()->assertSee('Cliente')->assertSee('Full · anual');
        $this->actingAs($admin)->post(route('admin.suscripciones.exento', $this->cliente))->assertRedirect();
        $this->assertTrue(app(PlanService::class)->sinLimites($this->cliente->fresh()));
        $this->actingAs($this->cliente)->get(route('admin.suscripciones'))->assertStatus(403);
    }
}
