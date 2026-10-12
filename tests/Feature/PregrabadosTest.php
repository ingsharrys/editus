<?php

namespace Tests\Feature;

use App\Models\EmisionProgramada;
use App\Models\MetaPage;
use App\Models\PagoSuscripcion;
use App\Models\Role;
use App\Models\User;
use App\Models\YoutubeCanal;
use App\Services\EmisionesService;
use App\Services\EmisorClient;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Videos pregrabados que salen en vivo a una hora programada (emisor, Facebook y YouTube simulados). */
class PregrabadosTest extends TestCase
{
    private User $admin;
    private User $cliente;
    private MetaPage $pagina;
    private MetaPage $ajena;
    /** Estado que devuelve el emisor simulado y peticiones recibidas. */
    private array $emisor = ['estado' => 'subido', 'duracion' => 600];
    private array $llamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00', 'America/Bogota'));
        config(['services.facebook.version' => 'v23.0', 'app.timezone' => 'America/Bogota',
            'services.emisor.url' => 'https://live.prueba.co/emisor', 'services.emisor.secreto' => str_repeat('s', 64), 'services.emisor.cuenta_regresiva' => 60]);
        date_default_timezone_set('America/Bogota');
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('youtube_canales', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app')->nullable(); $t->string('channel_id'); $t->string('titulo')->nullable(); $t->text('foto')->nullable(); $t->text('access_token')->nullable(); $t->text('refresh_token')->nullable(); $t->timestamp('expira_en')->nullable(); $t->string('stream_id')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->json('app_usuarios')->nullable(); $t->timestamps(); });
        $rolAdmin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin = User::factory()->create(['name' => 'Dario', 'role_id' => $rolAdmin->id]);
        (require database_path('migrations/2026_10_13_000001_create_suscripciones.php'))->up();
        (require database_path('migrations/2026_10_15_000001_create_emisiones_programadas.php'))->up();
        (require database_path('migrations/2026_10_17_000001_notificaciones_y_miniaturas.php'))->up();
        $rolUser = Role::firstOrCreate(['slug' => 'user'], ['name' => 'Usuario']);
        $this->cliente = User::factory()->create(['name' => 'Cliente', 'role_id' => $rolUser->id]);

        $this->pagina = MetaPage::create(['page_id' => '501', 'name' => 'Opa Noticias']);
        $this->pagina->users()->attach($this->cliente->id, ['page_access_token' => 'tok-cliente', 'is_active' => true]);
        $this->pagina->users()->attach($this->admin->id, ['page_access_token' => 'tok-admin', 'is_active' => true]);
        $this->ajena = MetaPage::create(['page_id' => '502', 'name' => 'Ajena']);
        $this->ajena->users()->attach($this->admin->id, ['page_access_token' => 'tok-ajena', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Emisor, Facebook y YouTube simulados. $fbAnuncioFalla: Facebook rechaza la publicación de anuncio. */
    private function simular(bool $fbAnuncioFalla = false): void
    {
        Http::fake(function (PeticionHttp $r) use ($fbAnuncioFalla) {
            $url = $r->url();
            $this->llamadas[] = ['metodo' => $r->method(), 'url' => $url, 'datos' => $r->data(), 'cuerpo' => $r->body(), 'cabeceras' => $r->headers()];
            if (str_starts_with($url, 'https://live.prueba.co/emisor/')) {
                $ruta = parse_url($url, PHP_URL_PATH);
                // Toda petición al emisor va firmada
                $ok = hash_equals(EmisorClient::firmar((int) $r->header('X-Emisor-Ts')[0], $r->header('X-Emisor-Nonce')[0], $r->method(), $ruta, $r->body()), $r->header('X-Emisor-Firma')[0]);
                if (!$ok) return Http::response(['error' => 'Firma no válida'], 401);
                if ($r->method() === 'GET') return Http::response(['id' => basename($ruta), 'progreso' => 40] + $this->emisor);
                if (str_ends_with($ruta, '/procesar')) { $this->emisor['estado'] = 'en_cola'; return Http::response(['estado' => 'en_cola']); }
                if (str_ends_with($ruta, '/emitir')) { $this->emisor['estado'] = 'emitiendo'; return Http::response(['estado' => 'emitiendo']); }
                return Http::response(['ok' => true]);
            }
            if (str_contains($url, 'graph.facebook.com') && str_ends_with(parse_url($url, PHP_URL_PATH), '/live_videos')) {
                // Como Meta hoy: los en vivos programados ya no existen en la API
                if (($r->data()['status'] ?? '') !== 'LIVE_NOW') return Http::response(['error' => ['message' => '(#100) Invalid broadcast status, Scheduled Live has been deprecated']], 400);
                return Http::response(['id' => 'fb-directo', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/fb-directo?s_ps=1']);
            }
            if (str_contains($url, '/501/feed') || str_contains($url, '/501/photos')) {
                return $fbAnuncioFalla ? Http::response(['error' => ['message' => '(#200) Permisos insuficientes']], 400) : Http::response(['id' => '501_77']);
            }
            if (str_contains($url, 'graph.facebook.com')) return Http::response(['success' => true, 'permalink_url' => '/opa/videos/1']);
            if (str_contains($url, 'liveBroadcasts/bind')) return Http::response(['id' => 'yt-b1']);
            if (str_contains($url, 'liveBroadcasts')) return Http::response(['id' => 'yt-b1']);
            if (str_contains($url, 'liveStreams')) return Http::response(['id' => 'st-1', 'items' => [['id' => 'st-1', 'cdn' => ['ingestionInfo' => ['rtmpsIngestionAddress' => 'rtmps://a.rtmps.youtube.com/live2', 'streamName' => 'clave-yt']]]],
                'cdn' => ['ingestionInfo' => ['rtmpsIngestionAddress' => 'rtmps://a.rtmps.youtube.com/live2', 'streamName' => 'clave-yt']]]);
            return Http::response([], 404);
        });
    }

    private function llamadasA(string $fragmento, ?string $metodo = null): array
    {
        return array_values(array_filter($this->llamadas, fn($l) => str_contains($l['url'], $fragmento) && (!$metodo || $l['metodo'] === $metodo)));
    }

    private function programar(User $u, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->postJson('/en-vivo/pregrabados', $extra + [
            'titulo' => 'Especial de seguridad', 'descripcion' => 'Pregrabado', 'programada_en' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'meta_page_ids' => [$this->pagina->id], 'archivo_nombre' => 'especial.mp4', 'archivo_bytes' => 800 * 1024 * 1024, 'etiqueta' => true, 'cuenta' => true,
        ]);
    }

    private function darPlan(string $plan): void
    {
        app(PlanService::class)->aplicarPago(PagoSuscripcion::create(['user_id' => $this->cliente->id, 'plan' => $plan, 'periodo' => 'mensual', 'referencia' => 'ED-' . uniqid(), 'monto_centavos' => 100, 'estado' => 'APPROVED']));
    }

    public function test_programar_valida_plan_fecha_paginas_y_cola(): void
    {
        $this->simular();
        $this->programar($this->cliente)->assertStatus(403)->assertJsonPath('error', 'Necesitas un plan activo para programar pregrabados.');
        $this->darPlan('basico');
        $this->programar($this->cliente, ['programada_en' => now()->addMinutes(5)->format('Y-m-d\TH:i')])->assertStatus(422)->assertJsonFragment(['success' => false]);
        $this->programar($this->cliente, ['meta_page_ids' => [$this->ajena->id]])->assertStatus(422);
        $this->programar($this->cliente, ['archivo_bytes' => 7 * 1024 ** 3])->assertStatus(422);
        $this->programar($this->cliente, ['meta_page_ids' => []])->assertStatus(422);

        $r = $this->programar($this->cliente, ['logo_id' => 1, 'marco_id' => 1])->assertOk();
        $subida = $r->json('subida');
        $this->assertMatchesRegularExpression('#^https://live\.prueba\.co/emisor/subidas/em-[a-z0-9]{24}\?token=#', $subida);
        // El token de subida lo valida el emisor con el mismo secreto
        parse_str((string) parse_url($subida, PHP_URL_QUERY), $q);
        [$cuerpo, $firma] = explode('.', $q['token']);
        $this->assertSame(hash_hmac('sha256', 'subida.' . $cuerpo, str_repeat('s', 64)), $firma);
        $datos = json_decode(base64_decode(strtr($cuerpo, '-_', '+/')), true);
        $this->assertSame(basename(parse_url($subida, PHP_URL_PATH)), $datos['v']);
        $this->assertSame(800 * 1024 * 1024, $datos['max']);
        $e = EmisionProgramada::first();
        $this->assertSame('subiendo', $e->estado);
        $this->assertNull($e->plantilla['logo_url'], 'el plan Básico no lleva logo');

        // Cola del plan Básico: 5 pendientes
        for ($i = 0; $i < 4; $i++) $this->programar($this->cliente)->assertOk();
        $this->programar($this->cliente)->assertStatus(422)->assertJsonPath('error', 'Tu plan Básico permite 5 pregrabados programados a la vez. Espera a que salga alguno o cancela uno.');
        // Al procesar se manda el límite de duración del plan (2 h)
        $this->actingAs($this->cliente)->postJson("/en-vivo/pregrabados/{$e->id}/subido")->assertOk()->assertJsonPath('emision.estado', 'procesando');
        $p = $this->llamadasA('/procesar')[0]['datos'];
        $this->assertSame(7200, $p['max_segundos']);
        $this->assertSame('PREGRABADO', $p['etiqueta']);
        $this->assertSame(['segundos' => 60, 'titulo' => 'Especial de seguridad', 'subtitulo' => 'Comenzamos en', 'color' => '#00024f'], $p['cuenta']);
        // Otro usuario no puede tocarla
        $this->actingAs(User::factory()->create(['role_id' => Role::where('slug', 'user')->value('id')]))->postJson("/en-vivo/pregrabados/{$e->id}/cancelar")->assertForbidden();
    }

    public function test_ciclo_completo_anuncio_cuenta_regresiva_emision_cierre_y_borrado(): void
    {
        $this->simular();
        $r = $this->programar($this->admin)->assertOk();
        $e = EmisionProgramada::find($r->json('emision.id'));
        $hora = $e->programada_en->copy();
        $svc = app(EmisionesService::class);

        // Subido → procesando → listo (por la tarea, sin aviso)
        $this->actingAs($this->admin)->postJson("/en-vivo/pregrabados/{$e->id}/subido")->assertOk();
        $this->emisor = ['estado' => 'listo', 'duracion' => 600.5];
        $svc->tick();
        $e->refresh();
        $this->assertSame('listo', $e->estado);
        $this->assertSame(600.5, $e->duracion);
        // Listo: se publica el anuncio en la página (Meta ya no permite en vivos programados)
        $this->assertNotNull($e->anunciada_en);
        $this->assertCount(0, $this->llamadasA('/501/live_videos'));
        $fb = $this->llamadasA('/501/feed', 'POST')[0]['datos'];
        $this->assertStringContainsString('EN VIVO el ', $fb['message']);
        $this->assertSame('tok-admin', $fb['access_token'], 'usa el token de quien programó');
        $this->assertSame('pendiente', $e->destinos[0]['estado']);
        $this->assertSame('501_77', $e->destinos[0]['anuncio_post_id']);
        $this->actingAs($this->admin)->getJson('/en-vivo/pregrabados/estado')->assertOk()->assertJsonPath('emisiones.0.destinos.0.anuncio', true);

        // Antes de la hora no sale; en el minuto anterior arranca con cuenta regresiva
        Carbon::setTestNow($hora->copy()->subMinutes(5));
        $svc->tick();
        $this->assertSame('listo', $e->refresh()->estado);
        Carbon::setTestNow($hora->copy()->subSeconds(60));
        $svc->tick();
        $e->refresh();
        $this->assertSame('al_aire', $e->estado);
        $emitir = $this->llamadasA('/emitir')[0]['datos'];
        $this->assertSame(['rtmps://live-api-s.facebook.com:443/rtmp/fb-directo?s_ps=1'], $emitir['destinos']);
        $this->assertTrue($emitir['cuenta_regresiva']);
        $this->assertSame($hora->getTimestamp(), $emitir['hora_video'], 'el emisor recibe la hora exacta del video');
        $this->assertCount(1, $this->llamadasA('/live_videos'), 'el en vivo de Facebook se crea a la hora');
        // La clave de transmisión queda cifrada en la base de datos
        $this->assertStringNotContainsString('rtmps://', (string) DB::table('emisiones_programadas')->value('destinos'));

        // Progreso y fin: se cierra el Live y se olvida la clave
        $this->emisor = ['estado' => 'emitiendo', 'emision' => ['total' => 660, 'posicion' => 330, 'destinos' => [['estado' => 'emitiendo', 'intentos' => 0]]]];
        $svc->tick();
        $this->assertSame(50, $e->refresh()->progreso);
        $this->emisor = ['estado' => 'emitido', 'emision' => ['total' => 660, 'posicion' => 660, 'destinos' => [['estado' => 'terminado', 'intentos' => 0]]]];
        Carbon::setTestNow($hora->copy()->addMinutes(11));
        $svc->tick();
        $e->refresh();
        $this->assertSame('terminado', $e->estado);
        $this->assertNotNull($e->fin_real);
        $this->assertSame('terminado', $e->destinos[0]['estado']);
        $this->assertNull($e->destinos[0]['stream_url']);
        $fin = collect($this->llamadasA('fb-directo', 'POST'))->first(fn($l) => ($l['datos']['end_live_video'] ?? '') === 'true');
        $this->assertNotNull($fin, 'se cierra el Live en Facebook');

        // A los 7 días se borra el archivo del VPS
        Carbon::setTestNow($hora->copy()->addDays(8));
        $svc->tick();
        $this->assertNotNull($e->refresh()->archivo_borrado_en);
        $this->assertCount(1, $this->llamadasA('/videos/' . $e->emisor_id, 'DELETE'));
        $this->actingAs($this->admin)->getJson('/en-vivo/pregrabados/estado')->assertOk()->assertJsonPath('emisiones.0.estado', 'terminado')
            ->assertJsonMissingPath('emisiones.0.destinos.0.stream_url');
    }

    public function test_si_el_anuncio_falla_sale_en_directo_y_youtube_se_enlaza_a_la_hora(): void
    {
        $this->simular(fbAnuncioFalla: true);
        $canal = YoutubeCanal::create(['channel_id' => 'UC1', 'titulo' => 'Opa TV', 'access_token' => 'yt-tok', 'expira_en' => now()->addDays(30), 'visible_en_editor' => true]);
        $e = EmisionProgramada::find($this->programar($this->admin, ['youtube_canal_ids' => [$canal->id], 'cuenta' => false])->assertOk()->json('emision.id'));
        $svc = app(EmisionesService::class);
        $this->emisor = ['estado' => 'subido'];
        $svc->tick();
        $this->assertSame('subiendo', $e->refresh()->estado, 'mientras sube, lo informa el navegador');
        Carbon::setTestNow(now()->addMinutes(3));
        $svc->tick(); // subido → procesar (aunque el navegador se haya cerrado)
        $this->assertSame('procesando', $e->refresh()->estado);
        $this->emisor = ['estado' => 'listo', 'duracion' => 120];
        $svc->tick();
        $e->refresh();
        $this->assertSame('pendiente', $e->destinos[0]['estado']);
        $this->assertStringContainsString('Anuncio', $e->destinos[0]['error']);
        $this->assertSame('anunciado', $e->destinos[1]['estado'], 'YouTube programado');
        $this->assertCount(0, $this->llamadasA('liveBroadcasts/bind'), 'no se enlaza al stream del canal hasta la hora');

        // Llega tarde (la tarea no corrió en el minuto anterior): sale sin cuenta regresiva
        Carbon::setTestNow($e->programada_en->copy()->addMinutes(2));
        $svc->tick();
        $e->refresh();
        $this->assertSame('al_aire', $e->estado);
        $emitir = $this->llamadasA('/emitir')[0]['datos'];
        $this->assertSame(['rtmps://live-api-s.facebook.com:443/rtmp/fb-directo?s_ps=1', 'rtmps://a.rtmps.youtube.com/live2/clave-yt'], $emitir['destinos']);
        $this->assertFalse($emitir['cuenta_regresiva']);
        $this->assertCount(1, $this->llamadasA('liveBroadcasts/bind'));
        $this->assertSame([0, 1], array_column($e->destinos, 'indice'));

        // Un destino se cae y el otro termina: queda emitido con la nota
        $this->emisor = ['estado' => 'emitido', 'error' => 'Algún destino no completó la transmisión', 'emision' => ['total' => 120, 'posicion' => 120, 'destinos' => [['estado' => 'terminado'], ['estado' => 'error', 'error' => 'Connection reset']]]];
        $svc->tick();
        $e->refresh();
        $this->assertSame('terminado', $e->estado);
        $this->assertSame(['terminado', 'error'], array_column($e->destinos, 'estado'));
    }

    public function test_aviso_firmado_del_emisor_cancelar_y_vencidas(): void
    {
        $this->simular();
        $e = EmisionProgramada::find($this->programar($this->admin)->json('emision.id'));
        $e->fill(['estado' => 'procesando'])->save();
        $cuerpo = json_encode(['evento' => 'listo', 'video' => ['id' => $e->emisor_id, 'estado' => 'listo', 'duracion' => 90]]);
        $ts = time(); $nonce = 'n-1';
        $cab = ['X-Emisor-Ts' => (string) $ts, 'X-Emisor-Nonce' => $nonce, 'CONTENT_TYPE' => 'application/json'];
        $this->call('POST', '/webhooks/emisor', [], [], [], $this->servidor($cab + ['X-Emisor-Firma' => 'falsa']), $cuerpo)->assertStatus(401);
        $firma = EmisorClient::firmar($ts, $nonce, 'POST', '/webhooks/emisor', $cuerpo);
        $this->call('POST', '/webhooks/emisor', [], [], [], $this->servidor($cab + ['X-Emisor-Firma' => $firma]), $cuerpo)->assertOk();
        $this->assertSame('listo', $e->refresh()->estado);
        $this->call('POST', '/webhooks/emisor', [], [], [], $this->servidor($cab + ['X-Emisor-Firma' => $firma]), $cuerpo)->assertStatus(401); // nonce repetido

        // Cancelar una anunciada: se borra la publicación de anuncio y el archivo
        app(EmisionesService::class)->anunciar($e);
        $this->actingAs($this->admin)->postJson("/en-vivo/pregrabados/{$e->id}/cancelar")->assertOk()->assertJsonPath('emision.estado', 'cancelado');
        $this->assertCount(1, $this->llamadasA('/501_77', 'DELETE'));
        $this->assertCount(1, $this->llamadasA('/videos/' . $e->emisor_id, 'DELETE'));

        // Vencida: no terminó de prepararse antes de la hora
        $v = EmisionProgramada::find($this->programar($this->admin)->json('emision.id'));
        $v->fill(['estado' => 'procesando'])->save();
        $this->emisor = ['estado' => 'procesando', 'progreso' => 80];
        Carbon::setTestNow($v->programada_en->copy()->addMinutes(31));
        app(EmisionesService::class)->tick();
        $this->assertSame('error', $v->refresh()->estado);
        $this->assertStringContainsString('no terminó de prepararse', $v->error);

        // "Salir en 2 minutos" adelanta una lista
        $l = EmisionProgramada::find($this->programar($this->admin)->json('emision.id'));
        $l->fill(['estado' => 'listo'])->save();
        $this->actingAs($this->admin)->postJson("/en-vivo/pregrabados/{$l->id}/ahora")->assertOk();
        $this->assertEqualsWithDelta(120, now()->diffInSeconds($l->refresh()->programada_en), 60);
    }

    public function test_paginas_del_modulo_y_tarea_sin_configurar(): void
    {
        $this->simular();
        $this->actingAs($this->admin)->get('/en-vivo/pregrabados')->assertOk()->assertSee('Pregrabados programados')->assertSee('Subir y programar')->assertSee('Opa Noticias');
        $this->darPlan('basico');
        $this->actingAs($this->cliente)->get('/en-vivo/pregrabados')->assertOk()->assertSee('videos de hasta 2 horas, 5 programados a la vez, sin logo ni marco')->assertDontSee('Ajena');
        config(['services.emisor.url' => null]);
        $this->artisan('editus:emisiones')->assertSuccessful();
        $this->actingAs($this->admin)->get('/en-vivo/pregrabados')->assertOk()->assertSee('El servidor de pregrabados no está configurado');
    }

    private function servidor(array $cabeceras): array
    {
        $s = [];
        foreach ($cabeceras as $k => $v) $s[$k === 'CONTENT_TYPE' ? $k : 'HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        return $s;
    }
}
