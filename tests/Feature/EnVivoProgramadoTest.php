<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\Role;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use App\Models\YoutubeCanal;
use App\Services\EnVivoProgramadoService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** En vivo PROGRAMADO con cámaras: anuncio en Facebook/YouTube, invitados antes, aviso, salida al aire en el mismo en vivo y vencimiento. */
class EnVivoProgramadoTest extends TestCase
{
    private User $admin;
    private MetaPage $pagina;
    private array $llamadas = [];
    private bool $fbProgramadoFalla = false;

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
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin = User::factory()->create(['name' => 'Dario', 'email' => 'dario@prueba.co', 'role_id' => $rol->id]);
        $this->pagina = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $this->pagina->users()->attach($this->admin->id, ['page_access_token' => 'tok-pagina', 'is_active' => true]);
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
            $this->llamadas[] = ['url' => $url, 'metodo' => $r->method(), 'datos' => $r->data()];
            if (str_contains($url, '/111/live_videos') && ($r->data()['status'] ?? '') === 'SCHEDULED_UNPUBLISHED' && $this->fbProgramadoFalla) return Http::response(['error' => ['message' => '(#100) Invalid parameter']], 400);
            if (str_contains($url, '/111/live_videos')) return Http::response(['id' => ($r->data()['status'] ?? '') === 'SCHEDULED_UNPUBLISHED' ? '9001' : '7001', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-' . (($r->data()['status'] ?? '') === 'SCHEDULED_UNPUBLISHED' ? 'PROGRAMADA' : 'DIRECTA')]);
            if (str_contains($url, 'graph.facebook.com') && $r->method() === 'GET') return Http::response(['permalink_url' => '/opa/videos/9001/', 'video' => ['id' => '9002'], 'status' => 'LIVE']);
            if (str_contains($url, 'graph.facebook.com')) return Http::response(['success' => true]);
            if (str_contains($url, 'liveBroadcasts/bind')) return Http::response(['id' => 'yt-1']);
            if (str_contains($url, 'liveBroadcasts')) return Http::response(['id' => 'yt-1']);
            if (str_contains($url, 'liveStreams')) return Http::response(['id' => 'st-1', 'cdn' => ['ingestionInfo' => ['rtmpsIngestionAddress' => 'rtmps://a.rtmps.youtube.com/live2', 'streamName' => 'clave-yt']]]);
            if (str_contains($url, 'StartRoomCompositeEgress')) return Http::response(['egress_id' => 'EG_1']);
            if (str_contains($url, 'ListParticipants')) return Http::response(['participants' => []]);
            if (str_contains($url, 'twirp')) return Http::response(['name' => 'x']);
            return Http::response([], 404);
        });
    }

    private function llamadasA(string $fragmento, ?string $metodo = null): array
    {
        return array_values(array_filter($this->llamadas, fn($l) => str_contains($l['url'], $fragmento) && (!$metodo || $l['metodo'] === $metodo)));
    }

    private function programar(array $extra = [])
    {
        return $this->actingAs($this->admin)->postJson('/en-vivo/programar', $extra + [
            'titulo' => 'Debate de candidatos', 'descripcion' => 'En vivo desde el estudio', 'page_ids' => ['111'],
            'programada_en' => now()->addDays(2)->setTime(19, 0)->format('Y-m-d\TH:i'), 'plantilla' => ['etiqueta' => 'EN VIVO'],
        ]);
    }

    public function test_programa_anuncia_y_sale_al_aire_en_el_mismo_en_vivo(): void
    {
        $canal = YoutubeCanal::create(['channel_id' => 'UC1', 'titulo' => 'Opa TV', 'access_token' => 'yt', 'expira_en' => now()->addDays(30), 'visible_en_editor' => true]);
        $this->programar(['programada_en' => now()->addMinutes(5)->format('Y-m-d\TH:i')])->assertStatus(422);
        $r = $this->programar(['youtube_canal_ids' => [$canal->id]])->assertOk()->assertJsonPath('transmision.estado', 'programada')->assertJsonPath('transmision.anunciada', true);
        $this->assertStringNotContainsString('CLAVE-PROGRAMADA', $r->getContent(), 'la clave no sale en la respuesta');
        $t = TransmisionEnVivo::find($r->json('transmision.id'));
        // Facebook: en vivo programado con la hora; YouTube: "próximo" sin enlazar al stream
        $fb = $this->llamadasA('/111/live_videos')[0]['datos'];
        $this->assertSame('SCHEDULED_UNPUBLISHED', $fb['status']);
        $this->assertSame(['start_time' => $t->programada_en->getTimestamp()], json_decode($fb['event_params'], true));
        $this->assertCount(0, $this->llamadasA('liveBroadcasts/bind'));
        $this->assertSame(['anunciado', 'anunciado'], array_column($t->destinosLista(), 'estado'));
        $this->assertCount(0, $this->llamadasA('CreateRoom'), 'todavía no hay sala');

        // Enlace de invitados desde ya: la página muestra la hora y no da token antes de tiempo
        $codigo = $t->invitaciones[0]['codigo'];
        $this->get("/en-vivo/invitado/{$codigo}")->assertOk()->assertSee('Programada para el');
        $this->postJson("/en-vivo/invitado/{$codigo}/token")->assertStatus(422)->assertJsonFragment(['success' => false]);
        $this->actingAs($this->admin)->get('/en-vivo')->assertOk()->assertSee('Próximos en vivo')->assertSee('Debate de candidatos')->assertSee("/en-vivo/invitado/{$codigo}");

        // A la hora: abre la sala (el invitado ya puede entrar) y sale al aire en ESE en vivo
        Carbon::setTestNow($t->programada_en->copy()->subMinutes(5));
        $this->actingAs($this->admin)->postJson("/en-vivo/{$t->id}/abrir")->assertOk()->assertJsonPath('transmision.estado', 'sala')->assertJsonPath('livekit.url', 'wss://live.prueba.test');
        $this->assertCount(1, $this->llamadasA('CreateRoom'));
        $this->postJson("/en-vivo/invitado/{$codigo}/token")->assertOk()->assertJsonPath('success', true);
        $this->actingAs($this->admin)->postJson("/en-vivo/{$t->id}/aire")->assertOk()->assertJsonPath('transmision.estado', 'en_vivo');
        $this->assertCount(1, $this->llamadasA('/111/live_videos'), 'no crea otro en vivo en Facebook');
        $this->assertNotNull(collect($this->llamadasA('/9001', 'POST'))->first(fn($l) => ($l['datos']['status'] ?? '') === 'LIVE_NOW'), 'lo pone al aire ya');
        $this->assertCount(1, $this->llamadasA('liveBroadcasts/bind'), 'YouTube se enlaza a la hora');
        $egress = $this->llamadasA('StartRoomCompositeEgress')[0]['datos']['stream_outputs'][0]['urls'];
        $this->assertSame(['rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-PROGRAMADA', 'rtmps://a.rtmps.youtube.com/live2/clave-yt'], $egress);
        // Ya no cuenta como vencible
        Carbon::setTestNow($t->programada_en->copy()->addHour());
        app(EnVivoProgramadoService::class)->tick();
        $this->assertSame('en_vivo', $t->refresh()->estado);
    }

    public function test_aviso_10_minutos_antes_vencimiento_y_cancelacion(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $t = TransmisionEnVivo::find($this->programar()->json('transmision.id'));
        $svc = app(EnVivoProgramadoService::class);
        Carbon::setTestNow($t->programada_en->copy()->subMinutes(15));
        $svc->tick();
        $this->assertNull($t->refresh()->avisada_en);
        Carbon::setTestNow($t->programada_en->copy()->subMinutes(9));
        $svc->tick();
        $this->assertNotNull($t->refresh()->avisada_en);
        $svc->tick(); // no se repite
        // Nadie salió al aire: a los 30 minutos se quita el anuncio
        Carbon::setTestNow($t->programada_en->copy()->addMinutes(31));
        $svc->tick();
        $t->refresh();
        $this->assertSame('cancelada', $t->estado);
        $this->assertStringContainsString('se quitó el anuncio', $t->error);
        $this->assertNotNull(collect($this->llamadasA('/9001', 'POST'))->first(fn($l) => ($l['datos']['status'] ?? '') === 'SCHEDULED_CANCELED'));

        // Cancelar a mano; programar a más de 7 días no anuncia todavía (lo hace la tarea al entrar en la ventana)
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00'));
        $lejos = TransmisionEnVivo::find($this->programar(['programada_en' => now()->addDays(20)->format('Y-m-d\TH:i')])->json('transmision.id'));
        $this->assertFalse((bool) $lejos->anunciada_en);
        Carbon::setTestNow($lejos->programada_en->copy()->subDays(6));
        $svc->tick();
        $this->assertNotNull($lejos->refresh()->anunciada_en);
        $this->actingAs($this->admin)->postJson("/en-vivo/{$lejos->id}/cancelar")->assertOk()->assertJsonPath('transmision.estado', 'cancelada');
        $this->actingAs($this->admin)->postJson("/en-vivo/{$lejos->id}/abrir")->assertStatus(422);
    }

    public function test_si_falla_el_anuncio_sale_igual_con_en_vivo_directo(): void
    {
        $this->fbProgramadoFalla = true;
        $t = TransmisionEnVivo::find($this->programar()->assertOk()->json('transmision.id'));
        $this->assertSame('pendiente', $t->destinosLista()[0]['estado']);
        $this->assertStringContainsString('Anuncio', $t->destinosLista()[0]['error']);
        $this->actingAs($this->admin)->get('/en-vivo')->assertOk()->assertSee('saldrá igual a la hora');
        $this->actingAs($this->admin)->postJson("/en-vivo/{$t->id}/abrir")->assertOk();
        $this->actingAs($this->admin)->postJson("/en-vivo/{$t->id}/aire")->assertOk()->assertJsonPath('transmision.estado', 'en_vivo');
        $this->assertSame('rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-DIRECTA', $this->llamadasA('StartRoomCompositeEgress')[0]['datos']['stream_outputs'][0]['urls'][0]);
    }
}
