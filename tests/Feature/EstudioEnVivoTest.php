<?php

namespace Tests\Feature;

use App\Models\ActivoEnVivo;
use App\Models\MarcaEnVivo;
use App\Models\MetaPage;
use App\Models\Role;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use App\Models\YoutubeCanal;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Estudio al estilo StreamYard: marcas, archivos propios, banners, comentarios, widgets, pantallas, sonidos, música, notas y chat. */
class EstudioEnVivoTest extends TestCase
{
    private User $admin;
    private MetaPage $pagina;
    private array $llamadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00', 'America/Bogota'));
        config(['app.timezone' => 'America/Bogota', 'services.facebook.version' => 'v23.0',
            'services.livekit.url' => 'wss://live.prueba.test', 'services.livekit.api_key' => 'APIkey', 'services.livekit.api_secret' => 'secreto-muy-largo-de-prueba-1234567890']);
        date_default_timezone_set('America/Bogota');
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('youtube_canales', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('channel_id', 64); $t->string('titulo', 150); $t->text('foto')->nullable(); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expira_en')->nullable(); $t->string('stream_id', 64)->nullable(); $t->boolean('visible_en_editor')->default(true); $t->timestamps(); });
        Schema::create('recursos_en_vivo', function (Blueprint $t) { $t->id(); $t->string('tipo', 10); $t->string('uso', 15)->default('publicidad'); $t->string('nombre', 80); $t->string('archivo', 300); $t->unsignedInteger('duracion')->nullable(); $t->unsignedSmallInteger('orden')->default(0); $t->boolean('activo')->default(true); $t->timestamps(); });
        Schema::create('transmisiones_en_vivo', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->string('usuario_app', 60)->nullable(); $t->string('titulo', 200); $t->text('descripcion')->nullable(); $t->string('room', 80)->unique();
            $t->string('fb_live_id', 60)->nullable(); $t->string('fb_video_id', 60)->nullable(); $t->string('fb_permalink', 500)->nullable(); $t->text('stream_url')->nullable(); $t->string('egress_id', 80)->nullable(); $t->json('destinos')->nullable();
            $t->string('estado', 20)->default('creada'); $t->json('plantilla')->nullable(); $t->json('escena')->nullable(); $t->json('invitaciones')->nullable(); $t->text('error')->nullable(); $t->timestamp('iniciada_en')->nullable(); $t->timestamp('terminada_en')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_12_000001_add_user_id_to_transmisiones_en_vivo.php'))->up();
        (require database_path('migrations/2026_10_16_000001_programar_transmisiones_en_vivo.php'))->up();
        (require database_path('migrations/2026_10_17_000001_notificaciones_y_miniaturas.php'))->up();
        (require database_path('migrations/2026_10_18_000001_estudio_marcas_activos_notas.php'))->up();
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin = User::factory()->create(['name' => 'Dario', 'email' => 'dario@prueba.co', 'role_id' => $rol->id]);
        $this->pagina = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $this->pagina->users()->attach($this->admin->id, ['page_access_token' => 'tok-pagina', 'is_active' => true]);
        Storage::fake('public');
        Cache::flush();
        $this->simular();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function simular(): void
    {
        Http::fake(function (PeticionHttp $r) {
            $url = $r->url();
            $this->llamadas[] = ['url' => $url, 'metodo' => $r->method(), 'datos' => $r->isMultipart() ? [] : $r->data()];
            if (str_contains($url, '/L1/comments')) return Http::response(['data' => [
                ['id' => 'c1', 'message' => 'Saludos desde Garzón', 'created_time' => '2026-10-11T14:00:05+0000', 'from' => ['name' => 'María', 'picture' => ['data' => ['url' => 'https://fb.test/m.jpg']]]],
                ['id' => 'c2', 'message' => '', 'created_time' => '2026-10-11T14:00:06+0000'],
            ]]);
            if (str_contains($url, 'liveBroadcasts') && $r->method() === 'GET') return Http::response(['items' => [['snippet' => ['liveChatId' => 'chat-9']]]]);
            if (str_contains($url, 'liveChat/messages')) return Http::response(['nextPageToken' => 'p2', 'pollingIntervalMillis' => 4000, 'items' => [
                ['id' => 'm1', 'snippet' => ['displayMessage' => '¡Hola desde Neiva!', 'publishedAt' => '2026-10-11T14:00:09Z'], 'authorDetails' => ['displayName' => 'Pedro', 'profileImageUrl' => 'https://yt.test/p.jpg']],
            ]]);
            if (str_contains($url, 'oauth2') || str_contains($url, 'token')) return Http::response(['access_token' => 'yt-nuevo', 'expires_in' => 3600]);
            if (str_contains($url, 'twirp')) return Http::response(['name' => 'x', 'participants' => []]);
            return Http::response(['success' => true]);
        });
    }

    private function sala(array $extra = []): TransmisionEnVivo
    {
        return TransmisionEnVivo::create($extra + [
            'meta_page_id' => $this->pagina->id, 'user_id' => $this->admin->id, 'titulo' => 'Debate', 'room' => 'envivo-111-abc', 'estado' => 'sala',
            'plantilla' => \App\Http\Controllers\Api\EnVivoController::normalizarPlantilla([], 'Debate'), 'escena' => \App\Http\Controllers\Api\EnVivoController::escenaInicial(),
            'destinos' => [['red' => 'facebook', 'meta_page_id' => $this->pagina->id, 'page_id' => '111', 'pagina' => 'Opa Noticias', 'fb_live_id' => null, 'estado' => 'pendiente']],
        ]);
    }

    private function metadata(): array
    {
        $ultima = collect($this->llamadas)->last(fn($l) => str_contains($l['url'], 'UpdateRoomMetadata'));
        return json_decode($ultima['datos']['metadata'] ?? '{}', true);
    }

    public function test_estudio_carga_con_marca_y_escena_sirve_sonidos(): void
    {
        $t = $this->sala();
        $html = $this->actingAs($this->admin)->get('/en-vivo')->assertOk()->getContent();
        foreach (['id="vista-estudio"', 'id="riel"', 'id="ev-bastidores"', 'id="ev-layouts"', 'en-vivo/sonidos.js'] as $parte) $this->assertStringContainsString($parte, $html);
        $this->assertSame(1, MarcaEnVivo::where('user_id', $this->admin->id)->count(), 'se crea «Marca 1»');
        $this->get('/en-vivo/sonidos.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8')->assertSee('EditusAudio');
        $this->get('/en-vivo/escena')->assertOk()->assertSee('START_RECORDING')->assertSee('sonidos.js');
        // Al preparar con una marca, la plantilla nace con su color, tema y fondo
        $m = MarcaEnVivo::first();
        $m->update(['datos' => array_merge(MarcaEnVivo::DEFECTO, ['color_etiqueta' => '#2563EB', 'tema' => 'burbuja', 'fondo' => ['tipo' => 'preset', 'valor' => 'olas-azules']])]);
        $t->update(['estado' => 'terminada']);
        $r = $this->actingAs($this->admin)->postJson('/en-vivo/preparar', ['titulo' => 'Rueda de prensa', 'page_ids' => ['111'], 'marca_id' => $m->id])->assertOk();
        $nueva = TransmisionEnVivo::find($r->json('transmision.id'));
        $this->assertSame('#2563EB', $nueva->plantilla['color_etiqueta']);
        $this->assertSame('burbuja', $nueva->plantilla['tema']);
        $this->assertSame('olas-azules', $nueva->plantilla['fondo']['valor']);
        $this->assertSame('Rueda de prensa', $nueva->plantilla['titulo']);
        $this->assertSame($m->id, (int) $nueva->marca_id);
    }

    public function test_marcas_se_guardan_normalizadas_y_se_aplican_en_pantalla(): void
    {
        $t = $this->sala();
        $m = MarcaEnVivo::find($this->actingAs($this->admin)->postJson('/en-vivo/marcas', ['nombre' => 'Elecciones'])->assertOk()->json('marca.id'));
        $r = $this->postJson("/en-vivo/marcas/{$m->id}", ['transmision_id' => $t->id, 'datos' => [
            'color_etiqueta' => '#16a34a', 'tema' => 'inventado', 'fuente' => 'oswald', 'logo_pos' => 'der', 'logo_url' => 'javascript:alert(1)',
            'superposicion' => ['tipo' => 'preset', 'valor' => 'ultima', 'texto' => 'Resultados'], 'fondo' => ['tipo' => 'color', 'valor' => '#112233'],
            'banners' => [['titulo' => 'Dato', 'texto' => 'Participación del 62 %', 'cinta' => true], ['texto' => '']],
        ]])->assertOk();
        $d = $m->refresh()->datos;
        $this->assertSame('#16A34A', $d['color_etiqueta']);
        $this->assertSame('clasico', $d['tema'], 'tema desconocido → clásico');
        $this->assertSame('', $d['logo_url'], 'solo URLs http(s)');
        $this->assertSame(['tipo' => 'preset', 'valor' => 'ultima', 'texto' => 'Resultados'], $d['superposicion']);
        $this->assertCount(1, $d['banners']);
        $this->assertTrue($d['banners'][0]['cinta']);
        // Se aplicó a la transmisión y a la sala (la escena lo ve al instante), sin los banners guardados
        $this->assertSame('oswald', $t->refresh()->plantilla['fuente']);
        $this->assertSame($m->id, (int) $t->marca_id);
        $meta = $this->metadata();
        $this->assertSame('der', $meta['logo_pos']);
        $this->assertSame(['tipo' => 'color', 'valor' => '#112233'], $meta['fondo']);
        $this->assertArrayNotHasKey('banners', $meta);
        $this->assertSame('Debate', $meta['titulo'], 'el titular de la transmisión se conserva');
        $this->assertSame($r->json('plantilla.color_etiqueta'), '#16A34A');
        // Cambiar de marca y borrar (siempre queda al menos una)
        $otra = MarcaEnVivo::where('user_id', $this->admin->id)->where('id', '!=', $m->id)->first() ?? MarcaEnVivo::create(['user_id' => $this->admin->id, 'nombre' => 'Marca 1', 'datos' => MarcaEnVivo::DEFECTO]);
        $this->postJson("/en-vivo/{$t->id}/marca", ['marca_id' => $otra->id])->assertOk()->assertJsonPath('plantilla.tema', 'clasico');
        $this->deleteJson("/en-vivo/marcas/{$m->id}")->assertOk();
        $this->deleteJson("/en-vivo/marcas/{$otra->id}")->assertStatus(422);
        // Marca de otra persona: no
        $ajeno = User::factory()->create();
        $suya = MarcaEnVivo::create(['user_id' => $ajeno->id, 'nombre' => 'Suya', 'datos' => []]);
        $this->postJson("/en-vivo/marcas/{$suya->id}", ['nombre' => 'Mía'])->assertForbidden();
        $this->postJson("/en-vivo/{$t->id}/marca", ['marca_id' => $suya->id])->assertForbidden();
    }

    public function test_archivos_propios_se_suben_validan_y_borran(): void
    {
        $this->actingAs($this->admin);
        $logo = $this->post('/en-vivo/activos', ['tipo' => 'logo', 'archivo' => UploadedFile::fake()->image('opa.png', 400, 120)], ['Accept' => 'application/json'])->assertOk()->json('activo');
        $this->assertSame('logo', $logo['tipo']);
        $this->assertSame('opa', $logo['nombre']);
        $a = ActivoEnVivo::find($logo['id']);
        Storage::disk('public')->assertExists($a->archivo);
        $this->assertStringStartsWith('en-vivo/recursos/u' . $this->admin->id . '-logo-', $a->archivo);
        // Con el VPS sirviendo los recursos, la URL apunta allá
        config(['services.livekit.recursos_url' => 'https://live.prueba.test/recursos']);
        $this->assertSame('https://live.prueba.test/recursos/' . basename($a->archivo), $a->url());
        // Formatos y tipos
        $this->post('/en-vivo/activos', ['tipo' => 'clip', 'archivo' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/en-vivo/activos', ['tipo' => 'otro', 'archivo' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/en-vivo/activos', ['tipo' => 'fondo', 'archivo' => UploadedFile::fake()->create('fondo.png', 10, 'text/plain')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonFragment(['success' => false]);
        $clip = $this->post('/en-vivo/activos', ['tipo' => 'clip', 'duracion' => 12, 'archivo' => UploadedFile::fake()->create('intro.mp4', 200, 'video/mp4')], ['Accept' => 'application/json'])->assertOk()->json('activo');
        $this->assertSame(12, $clip['duracion']);
        $musica = $this->post('/en-vivo/activos', ['tipo' => 'musica', 'archivo' => UploadedFile::fake()->create('fondo.mp3', 300, 'audio/mpeg')], ['Accept' => 'application/json'])->assertOk()->json('activo');
        $this->assertStringStartsWith('audio/', $musica['mime']);
        // Borrar: solo lo propio
        $ajeno = User::factory()->create();
        $this->actingAs($ajeno)->deleteJson('/en-vivo/activos/' . $logo['id'])->assertForbidden();
        $this->actingAs($this->admin)->deleteJson('/en-vivo/activos/' . $logo['id'])->assertOk();
        Storage::disk('public')->assertMissing($a->archivo);
    }

    public function test_banner_comentario_widgets_pantallas_clips_sonidos_musica_y_escenario_vacio(): void
    {
        $t = $this->sala();
        $this->actingAs($this->admin);
        $e = fn(array $d) => $this->postJson("/en-vivo/{$t->id}/escena", $d);
        $e(['banner' => ['titulo' => 'Ahora', 'texto' => 'Propuestas sobre vías', 'cinta' => true], 'layout' => 'lider'])->assertOk()->assertJsonPath('escena.banner.cinta', true);
        $this->assertSame('lider', $this->metadata()['escena']['layout']);
        $e(['comentario' => ['nombre' => 'María', 'texto' => 'Saludos', 'red' => 'facebook', 'foto' => 'https://fb.test/m.jpg']])->assertOk();
        $this->assertSame('María', $this->metadata()['escena']['comentario']['nombre']);
        $e(['widgets' => ['reloj' => true, 'cuenta' => ['segundos' => 90, 'texto' => 'Empezamos en']]])->assertOk();
        $w = $this->metadata()['escena']['widgets'];
        $this->assertTrue($w['reloj']);
        $this->assertEqualsWithDelta(now()->getTimestampMs() + 90000, $w['cuenta']['fin'], 2000);
        $e(['widgets' => ['cuenta' => null]])->assertOk();
        $this->assertArrayNotHasKey('cuenta', $this->metadata()['escena']['widgets']);
        $this->assertTrue($this->metadata()['escena']['widgets']['reloj'], 'el reloj sigue');
        $e(['pantalla' => ['preset' => 'temporizador']])->assertOk()->assertJsonPath('escena.recurso.tipo', 'pantalla')->assertJsonPath('escena.recurso.duracion', 10);
        $e(['pantalla' => ['preset' => 'inventada']])->assertStatus(422);
        // Clip propio en bucle, efecto y música
        $clip = ActivoEnVivo::create(['user_id' => $this->admin->id, 'tipo' => 'clip', 'nombre' => 'Intro', 'archivo' => 'en-vivo/recursos/intro.mp4', 'mime' => 'video/mp4', 'duracion' => 12]);
        $e(['activo_id' => $clip->id, 'bucle' => true])->assertOk()->assertJsonPath('escena.recurso.tipo', 'video')->assertJsonPath('escena.recurso.bucle', true);
        $e(['sonido' => ['preset' => 'aplausos']])->assertOk()->assertJsonPath('escena.sonido.preset', 'aplausos');
        $e(['sonido' => ['preset' => 'explosion']])->assertStatus(422);
        $e(['musica' => ['preset' => 'noticias'], 'vol_musica' => 0.2])->assertOk()->assertJsonPath('escena.musica.preset', 'noticias')->assertJsonPath('escena.vol_musica', 0.2);
        $pista = ActivoEnVivo::create(['user_id' => $this->admin->id, 'tipo' => 'musica', 'nombre' => 'Mi pista', 'archivo' => 'en-vivo/recursos/pista.mp3', 'mime' => 'audio/mpeg']);
        $e(['musica' => ['activo_id' => $pista->id]])->assertOk()->assertJsonPath('escena.musica.nombre', 'Mi pista');
        $e(['quitar_musica' => true, 'quitar_banner' => true, 'quitar_comentario' => true, 'vacio' => true])->assertOk();
        $esc = $this->metadata()['escena'];
        $this->assertArrayNotHasKey('musica', $esc);
        $this->assertArrayNotHasKey('banner', $esc);
        $this->assertTrue($esc['vacio']);
        // Un archivo de otra persona no se puede sacar al aire
        $ajeno = User::factory()->create();
        $suyo = ActivoEnVivo::create(['user_id' => $ajeno->id, 'tipo' => 'clip', 'nombre' => 'Ajeno', 'archivo' => 'en-vivo/recursos/a.mp4', 'mime' => 'video/mp4']);
        $no = User::factory()->create();
        $t2 = $this->sala(['user_id' => $no->id, 'room' => 'envivo-111-xyz']);
        $this->actingAs($no)->postJson("/en-vivo/{$t2->id}/escena", ['activo_id' => $suyo->id])->assertStatus(422);
        $this->actingAs($no)->postJson("/en-vivo/{$t2->id}/escena", ['sonido' => ['activo_id' => $suyo->id]])->assertStatus(422);
    }

    public function test_notas_y_comentarios_de_facebook_y_youtube_juntos(): void
    {
        $canal = YoutubeCanal::create(['channel_id' => 'UC1', 'titulo' => 'Opa TV', 'access_token' => 'yt', 'expira_en' => now()->addDays(30), 'visible_en_editor' => true]);
        $t = $this->sala(['estado' => 'sala']);
        $this->actingAs($this->admin)->postJson("/en-vivo/{$t->id}/notas", ['notas' => "1. Saludo\n2. Preguntas"])->assertOk();
        $this->assertSame("1. Saludo\n2. Preguntas", $t->refresh()->notas);
        $this->getJson("/en-vivo/{$t->id}/comentarios")->assertOk()->assertJsonCount(0, 'comentarios');
        $t->update(['estado' => 'en_vivo', 'destinos' => [
            ['red' => 'facebook', 'meta_page_id' => $this->pagina->id, 'page_id' => '111', 'pagina' => 'Opa Noticias', 'fb_live_id' => 'L1', 'estado' => 'ok'],
            ['red' => 'youtube', 'canal_id' => $canal->id, 'page_id' => 'yt:UC1', 'pagina' => 'Opa TV', 'fb_live_id' => 'yt-b1', 'estado' => 'ok'],
        ]]);
        $j = $this->getJson("/en-vivo/{$t->id}/comentarios")->assertOk()->json();
        $this->assertSame(['youtube', 'facebook'], array_column($j['comentarios'], 'red'), 'los más recientes primero; los vacíos se omiten');
        $this->assertSame('Pedro', $j['comentarios'][0]['nombre']);
        $this->assertSame('María', $j['comentarios'][1]['nombre']);
        $this->assertSame('https://fb.test/m.jpg', $j['comentarios'][1]['foto']);
        // En caché unos segundos (no gasta cuota de YouTube) y luego sigue desde la página siguiente del chat
        $this->getJson("/en-vivo/{$t->id}/comentarios")->assertOk();
        $this->assertCount(1, collect($this->llamadas)->filter(fn($l) => str_contains($l['url'], 'liveChat/messages')));
        Carbon::setTestNow(now()->addSeconds(10));
        $this->getJson("/en-vivo/{$t->id}/comentarios")->assertOk();
        $ultima = collect($this->llamadas)->last(fn($l) => str_contains($l['url'], 'liveChat/messages'));
        $this->assertSame('p2', $ultima['datos']['pageToken']);
        $this->assertCount(1, collect($this->llamadas)->filter(fn($l) => str_contains($l['url'], 'liveBroadcasts') && $l['metodo'] === 'GET'), 'el id del chat se pide una vez');
    }

    public function test_invitado_tiene_chat_privado(): void
    {
        $t = $this->sala(['invitaciones' => [['codigo' => 'abc123defg', 'nombre' => 'Ana', 'modo' => 'camara']]]);
        $this->get('/en-vivo/invitado/abc123defg')->assertOk()->assertSee('Chat privado con el estudio')->assertSee("topic: 'chat'", false);
    }
}
