<?php

namespace Tests\Feature;

use App\Models\DispositivoPush;
use App\Models\EmisionProgramada;
use App\Models\MetaPage;
use App\Models\Role;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use App\Models\YoutubeCanal;
use App\Services\EmisionesService;
use App\Services\EnVivoProgramadoService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Notificaciones al celular (Expo), correo corporativo, miniatura del anuncio y API de la app (programados y pregrabados). */
class AvisosYAppTest extends TestCase
{
    private const TOKEN = 'token-de-prueba';
    private User $web;
    private array $llamadas = [];
    private array $expo = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00', 'America/Bogota'));
        date_default_timezone_set('America/Bogota');
        Storage::fake('public');
        config(['app.timezone' => 'America/Bogota', 'services.facebook.version' => 'v23.0', 'services.editus.ingest_token' => self::TOKEN,
            'services.livekit.url' => 'wss://live.prueba.test', 'services.livekit.api_key' => 'APIkey', 'services.livekit.api_secret' => 'secreto-muy-largo-de-prueba-1234567890',
            'services.emisor.url' => 'https://live.prueba.co/emisor', 'services.emisor.secreto' => str_repeat('s', 64)]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->json('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('youtube_canales', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('channel_id', 64); $t->string('titulo', 150); $t->text('foto')->nullable(); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expira_en')->nullable(); $t->string('stream_id', 64)->nullable(); $t->boolean('visible_en_editor')->default(true); $t->json('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('transmisiones_en_vivo', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->string('usuario_app', 60)->nullable(); $t->string('titulo', 200); $t->text('descripcion')->nullable(); $t->string('room', 80)->unique();
            $t->string('fb_live_id', 60)->nullable(); $t->string('fb_video_id', 60)->nullable(); $t->string('fb_permalink', 500)->nullable(); $t->text('stream_url')->nullable(); $t->string('egress_id', 80)->nullable(); $t->json('destinos')->nullable();
            $t->string('estado', 20)->default('creada'); $t->json('plantilla')->nullable(); $t->json('escena')->nullable(); $t->json('invitaciones')->nullable(); $t->text('error')->nullable(); $t->timestamp('iniciada_en')->nullable(); $t->timestamp('terminada_en')->nullable(); $t->timestamps();
        });
        foreach (['2026_10_12_000001_add_user_id_to_transmisiones_en_vivo', '2026_10_13_000001_create_suscripciones', '2026_10_15_000001_create_emisiones_programadas', '2026_10_16_000001_programar_transmisiones_en_vivo', '2026_10_17_000001_notificaciones_y_miniaturas'] as $m) {
            (require database_path("migrations/{$m}.php"))->up();
        }
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->web = User::factory()->create(['name' => 'Dario', 'email' => 'dario@medio.co', 'role_id' => $rol->id]);
        // Página propia del periodista 7 en la app y una de otro
        $p = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        \Illuminate\Support\Facades\DB::table('meta_page_user')->insert(['meta_page_id' => $p->id, 'usuario_app' => '7', 'page_access_token' => 'tok-7', 'is_active' => true]);
        $p->users()->attach($this->web->id, ['page_access_token' => 'tok-web', 'is_active' => true]);
        $o = MetaPage::create(['page_id' => '222', 'name' => 'De otro']);
        \Illuminate\Support\Facades\DB::table('meta_page_user')->insert(['meta_page_id' => $o->id, 'usuario_app' => '9', 'page_access_token' => 'tok-9', 'is_active' => true]);

        Http::fake(function (PeticionHttp $r) {
            $url = $r->url();
            $this->llamadas[] = ['url' => $url, 'metodo' => $r->method(), 'datos' => $r->isMultipart() ? [] : $r->data(), 'multipart' => $r->isMultipart() ? $r->data() : null, 'cuerpo' => $r->isMultipart() ? '' : $r->body()];
            if (str_starts_with($url, 'https://exp.host/')) {
                $this->expo[] = $r->data();
                return Http::response(['data' => array_map(fn($m) => str_contains($m['to'], 'Muerto') ? ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']] : ['status' => 'ok', 'id' => 'x'], $r->data())]);
            }
            if (str_contains($url, '/111/live_videos')) return Http::response(['id' => '9001', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE']);
            if (str_contains($url, '/111/photos') || str_contains($url, '/111/feed')) return Http::response(['id' => 'foto-1', 'post_id' => '111_9']);
            if (str_contains($url, 'graph.facebook.com')) return Http::response(['success' => true, 'permalink_url' => '/opa/videos/9001/']);
            if (str_contains($url, 'thumbnails/set')) return Http::response(['items' => [['default' => ['url' => 'x']]]]);
            if (str_contains($url, 'liveBroadcasts')) return Http::response(['id' => 'yt-1']);
            if (str_starts_with($url, 'https://live.prueba.co/emisor/')) return Http::response(['estado' => 'listo', 'duracion' => 60]);
            return Http::response(['name' => 'x', 'success' => true]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(string $metodo, string $ruta, array $datos = [])
    {
        return $this->withHeader('X-Editus-Token', self::TOKEN)->json($metodo, '/api' . $ruta, $datos);
    }

    private function jpgBase64(int $w = 1280, int $h = 720): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 2, 79));
        ob_start(); imagejpeg($im, null, 80); $b = ob_get_clean();
        return 'data:image/jpeg;base64,' . base64_encode($b);
    }

    public function test_celulares_y_notificacion_push_gratuita_de_expo(): void
    {
        $this->api('POST', '/dispositivos', ['usuario' => '7', 'token' => 'no-es-un-token'])->assertStatus(422);
        $this->api('POST', '/dispositivos', ['usuario' => '7', 'token' => 'ExponentPushToken[abcdefghij123]', 'plataforma' => 'ios'])->assertOk();
        $this->api('POST', '/dispositivos', ['usuario' => '7', 'token' => 'ExponentPushToken[Muerto0123456]', 'plataforma' => 'android'])->assertOk();
        $this->api('POST', '/dispositivos', ['usuario' => '7', 'token' => 'ExponentPushToken[abcdefghij123]', 'plataforma' => 'ios'])->assertOk(); // no duplica
        $this->assertSame(2, DispositivoPush::count());

        // En vivo programado desde la app: a 10 minutos le llega la notificación al celular
        $r = $this->api('POST', '/en-vivo/programar', ['usuario' => '7', 'page_ids' => ['111'], 'titulo' => 'Debate', 'programada_en' => now()->addDay()->setTime(19, 0)->toIso8601String()])->assertOk();
        $t = TransmisionEnVivo::find($r->json('transmision.id'));
        $this->assertSame('7', $t->usuario_app);
        Carbon::setTestNow($t->programada_en->copy()->subMinutes(9));
        app(EnVivoProgramadoService::class)->tick();
        $this->assertCount(1, $this->expo);
        $m = $this->expo[0][0];
        $this->assertSame('En 9 min: Debate', $m['title']);
        $this->assertSame(['pantalla' => 'en-vivo', 'transmision_id' => $t->id], $m['data']);
        $this->assertSame('avisos', $m['channelId']);
        $this->assertSame(1, DispositivoPush::count(), 'el token que Expo reporta como no registrado se borra');
        $this->api('POST', '/dispositivos/quitar', ['token' => 'ExponentPushToken[abcdefghij123]'])->assertOk();
        $this->assertSame(0, DispositivoPush::count());
    }

    public function test_correo_corporativo_con_plantilla(): void
    {
        $this->artisan('editus:probar-correo', ['correo' => 'yo@medio.co'])->assertFailed(); // MAIL_MAILER=log en pruebas
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $this->artisan('editus:probar-correo', ['correo' => 'yo@medio.co'])->assertSuccessful();
        // Aviso del en vivo programado en la web: correo HTML a quien lo programó
        $this->actingAs($this->web)->postJson('/en-vivo/programar', ['titulo' => 'Rueda de prensa', 'page_ids' => ['111'], 'programada_en' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')])->assertOk();
        $t = TransmisionEnVivo::latest('id')->first();
        Carbon::setTestNow($t->programada_en->copy()->subMinutes(10));
        app(EnVivoProgramadoService::class)->tick();
        $this->assertNotNull($t->refresh()->avisada_en);
        $html = view('emails.aviso', ['titulo' => 'En 10 min: Rueda de prensa', 'cuerpo' => 'Tu en vivo empieza', 'accion' => ['texto' => 'Abrir el estudio', 'url' => 'https://app.editus.online/en-vivo'], 'lineas' => ['Enlace para tus invitados: x'], 'nombre' => 'Dario'])->render();
        $this->assertStringContainsString('Abrir el estudio', $html);
        $this->assertStringContainsString('Hola Dario', $html);
    }

    public function test_miniatura_del_anuncio_en_facebook_y_youtube(): void
    {
        $canal = YoutubeCanal::create(['channel_id' => 'UC1', 'titulo' => 'Opa TV', 'access_token' => 'yt', 'expira_en' => now()->addDays(30), 'visible_en_editor' => true, 'usuario_app' => '7']);
        $this->api('POST', '/en-vivo/programar', ['usuario' => '7', 'page_ids' => ['111'], 'titulo' => 'X', 'programada_en' => now()->addDay()->toIso8601String(), 'miniatura' => $this->jpgBase64(200, 100)])
            ->assertStatus(422)->assertJsonFragment(['success' => false]);
        $r = $this->api('POST', '/en-vivo/programar', ['usuario' => '7', 'page_ids' => ['111'], 'youtube_canal_ids' => [$canal->id], 'titulo' => 'Debate', 'programada_en' => now()->addDays(2)->toIso8601String(), 'miniatura' => $this->jpgBase64()])->assertOk();
        $this->assertStringContainsString('/storage/en-vivo/miniaturas/', $r->json('transmision.miniatura'));
        $this->assertNull(collect($this->llamadas)->first(fn($l) => str_contains($l['url'], '/111/live_videos')), 'sin en vivo programado (Meta lo eliminó)');
        $fb = collect($this->llamadas)->first(fn($l) => str_contains($l['url'], '/111/photos'));
        $this->assertNotNull($fb['multipart'], 'la publicación de anuncio lleva la imagen');
        $partes = collect($fb['multipart'])->keyBy('name');
        $this->assertTrue($partes->has('source'));
        $this->assertStringContainsString('Debate', $partes['message']['contents']);
        $this->assertSame('111_9', TransmisionEnVivo::find($r->json('transmision.id'))->destinosLista()[0]['anuncio_post_id']);
        $this->assertNotNull(collect($this->llamadas)->first(fn($l) => str_contains($l['url'], 'thumbnails/set?videoId=yt-1')), 'YouTube recibe la miniatura');
    }

    public function test_api_de_la_app_solo_lo_suyo_y_pregrabados(): void
    {
        // Página de otro usuario: no
        $this->api('POST', '/en-vivo/programar', ['usuario' => '7', 'page_ids' => ['222'], 'titulo' => 'X', 'programada_en' => now()->addDay()->toIso8601String()])->assertStatus(422);
        $t = TransmisionEnVivo::find($this->api('POST', '/en-vivo/programar', ['usuario' => '7', 'page_ids' => ['111'], 'titulo' => 'Mío', 'programada_en' => now()->addDay()->toIso8601String()])->json('transmision.id'));
        $this->api('GET', '/en-vivo/programadas?usuario=7')->assertOk()->assertJsonCount(1, 'transmisiones')->assertJsonPath('transmisiones.0.titulo', 'Mío');
        $this->api('GET', '/en-vivo/programadas?usuario=9')->assertOk()->assertJsonCount(0, 'transmisiones');
        $this->api('POST', "/en-vivo/{$t->id}/abrir", ['usuario' => '9'])->assertForbidden();
        $this->api('POST', "/en-vivo/{$t->id}/abrir", ['usuario' => '7'])->assertOk()->assertJsonPath('transmision.estado', 'sala')->assertJsonPath('livekit.url', 'wss://live.prueba.test');

        // Pregrabado desde la app: sube directo al emisor; el aviso "listo" llega al celular
        $this->api('POST', '/dispositivos', ['usuario' => '7', 'token' => 'ExponentPushToken[abcdefghij123]'])->assertOk();
        $r = $this->api('POST', '/pregrabados', ['usuario' => '7', 'page_ids' => ['111'], 'titulo' => 'Especial', 'programada_en' => now()->addDays(3)->toIso8601String(),
            'archivo_nombre' => 'especial.mov', 'archivo_bytes' => 50 * 1024 * 1024, 'etiqueta' => true, 'cuenta' => true])->assertOk();
        $this->assertMatchesRegularExpression('#^https://live\.prueba\.co/emisor/subidas/em-[a-z0-9]+\?token=#', $r->json('subida'));
        $e = EmisionProgramada::find($r->json('pregrabado.id'));
        $this->assertSame('7', $e->usuario_app);
        $this->api('POST', "/pregrabados/{$e->id}/subido", ['usuario' => '9'])->assertForbidden();
        $e->fill(['estado' => 'procesando'])->save();
        app(EmisionesService::class)->aplicar($e, ['estado' => 'listo', 'duracion' => 60]);
        $this->assertSame('listo', $e->refresh()->estado);
        $this->assertStringStartsWith('Listo para salir: Especial', collect($this->expo)->flatten(1)->last()['title']);
        $this->api('GET', '/pregrabados?usuario=7')->assertOk()->assertJsonPath('pregrabados.0.titulo', 'Especial')->assertJsonPath('configurado', true);
        $this->api('GET', '/pregrabados?usuario=9')->assertOk()->assertJsonCount(0, 'pregrabados');
        $this->api('POST', "/pregrabados/{$e->id}/ahora", ['usuario' => '7'])->assertOk();
        $this->api('POST', "/pregrabados/{$e->id}/cancelar", ['usuario' => '7'])->assertOk()->assertJsonPath('pregrabado.estado', 'cancelado');
        $this->api('POST', "/pregrabados/{$e->id}/quitar", ['usuario' => '7'])->assertOk();
        $this->assertNull(EmisionProgramada::find($e->id));
        // Sin X-Editus-Token: nada
        $this->flushHeaders()->postJson('/api/pregrabados', [])->assertStatus(401);
    }
}
