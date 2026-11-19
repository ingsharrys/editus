<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Producto;
use App\Models\Role;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\EnVivoCampanaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** En vivos de una campaña con plan de medios: se eligen campaña y subcampaña en el estudio y suman al informe. */
class EnVivoCampanaTest extends TestCase
{
    use Concerns\EsquemaPlanMedios;

    private User $admin;
    private Campaign $c;
    private Subcampana $sub;
    private Subcampana $sinVivo;
    private MetaPage $pagina;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias'], 'services.facebook.version' => 'v23.0',
            'services.livekit.url' => 'wss://live.prueba.test', 'services.livekit.api_key' => 'APIkey', 'services.livekit.api_secret' => 'secreto-muy-largo-de-prueba-1234567890',
            'services.editus.ingest_token' => 'tok-integracion']);
        $this->crearEsquemaPlanMedios();
        Schema::create('transmisiones_en_vivo', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->string('usuario_app', 60)->nullable(); $t->string('titulo', 200); $t->text('descripcion')->nullable(); $t->string('room', 80)->unique();
            $t->string('fb_live_id', 60)->nullable(); $t->string('fb_video_id', 60)->nullable(); $t->string('fb_permalink', 500)->nullable(); $t->text('stream_url')->nullable(); $t->string('egress_id', 80)->nullable(); $t->json('destinos')->nullable();
            $t->string('estado', 20)->default('creada'); $t->json('plantilla')->nullable(); $t->json('escena')->nullable(); $t->json('invitaciones')->nullable(); $t->text('error')->nullable(); $t->timestamp('iniciada_en')->nullable(); $t->timestamp('terminada_en')->nullable(); $t->timestamps();
        });
        Schema::create('youtube_canales', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('channel_id', 64); $t->string('titulo', 150); $t->text('foto')->nullable(); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expira_en')->nullable(); $t->string('stream_id', 64)->nullable(); $t->boolean('visible_en_editor')->default(true); $t->timestamps(); });
        Schema::create('recursos_en_vivo', function (Blueprint $t) { $t->id(); $t->string('tipo', 10); $t->string('uso', 15)->default('publicidad'); $t->string('nombre', 80); $t->string('archivo', 300); $t->unsignedInteger('duracion')->nullable(); $t->unsignedSmallInteger('orden')->default(0); $t->boolean('activo')->default(true); $t->timestamps(); });
        (require database_path('migrations/2026_10_12_000001_add_user_id_to_transmisiones_en_vivo.php'))->up();
        (require database_path('migrations/2026_10_16_000001_programar_transmisiones_en_vivo.php'))->up();
        (require database_path('migrations/2026_10_17_000001_notificaciones_y_miniaturas.php'))->up();
        (require database_path('migrations/2026_10_18_000001_estudio_marcas_activos_notas.php'))->up();
        (require database_path('migrations/2026_10_19_000001_estudio_app_usuario.php'))->up();
        (require database_path('migrations/2026_11_02_000001_campana_en_transmisiones.php'))->up();

        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->pagina = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias', 'medio_slug' => 'opanoticias']);
        $this->pagina->users()->attach($this->admin->id, ['page_access_token' => 'tok-pagina', 'is_active' => true]);
        $this->c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias']], $this->admin->id);
        $this->c->forceFill(['plan_medios' => true])->save();
        $vivo = TipoPublicacion::create(['campaign_id' => $this->c->id, 'nombre' => 'En vivos · Live', 'etiqueta' => 'En vivo', 'clase' => 'envivo', 'canal' => 'digital', 'is_active' => true, 'orden' => 1]);
        $banner = TipoPublicacion::create(['campaign_id' => $this->c->id, 'nombre' => 'Banner', 'etiqueta' => 'Banner', 'clase' => 'web', 'canal' => 'digital', 'is_active' => true, 'orden' => 2]);
        $this->sub = Subcampana::create(['campaign_id' => $this->c->id, 'nombre' => 'Salud infantil', 'is_active' => true, 'orden' => 1]);
        $this->sub->tipos()->attach([$vivo->id => ['cantidad' => 3], $banner->id => ['cantidad' => 4]]);
        $this->sinVivo = Subcampana::create(['campaign_id' => $this->c->id, 'nombre' => 'Zoonosis', 'is_active' => true, 'orden' => 2]);
        $this->sinVivo->tipos()->attach([$banner->id => ['cantidad' => 2]]);

        Http::fake([
            'graph.facebook.com/v23.0/111/live_videos' => Http::response(['id' => '7001', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE', 'stream_url' => 'rtmp://x'], 200),
            'graph.facebook.com/v23.0/7001?*' => Http::response(['permalink_url' => '/opa/videos/7002/', 'status' => 'LIVE', 'video' => ['id' => '7002']], 200),
            'graph.facebook.com/v23.0/7001' => Http::response(['success' => true], 200),
            'live.prueba.test/twirp/livekit.RoomService/*' => Http::response(['name' => 'x'], 200),
            'live.prueba.test/twirp/livekit.Egress/StartRoomCompositeEgress' => Http::response(['egress_id' => 'EG_1', 'status' => 'EGRESS_STARTING'], 200),
            'live.prueba.test/twirp/livekit.Egress/*' => Http::response(['items' => []], 200),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_opciones_solo_subcampanas_con_en_vivo(): void
    {
        $op = app(EnVivoCampanaService::class)->opciones();
        $this->assertCount(1, $op);
        $this->assertSame('Salud 2026', $op[0]['nombre']);
        $this->assertSame(['111'], $op[0]['paginas']);
        $this->assertSame([['id' => $this->sub->id, 'nombre' => 'Salud infantil', 'tipos' => ['En vivo']]], $op[0]['subs']);

        // El estudio muestra la campaña y la pantalla de publicar manda los en vivo al estudio
        $this->actingAs($this->admin)->get('/en-vivo')->assertOk()->assertSee('Campaña (plan de medios)')->assertSee('Salud 2026');
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.publicar', [$this->c, $this->sub]))->assertOk()
            ->assertSee('piezaVivo', false)->assertSee('Hacer el en vivo');
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $this->sub]))->assertOk()->assertSee('Hacer en vivo');
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $this->sinVivo]))->assertOk()->assertDontSee('Hacer en vivo');
    }

    public function test_en_vivo_de_la_campana_suma_una_pieza_al_informe(): void
    {
        $base = ['page_ids' => ['111'], 'titulo' => 'Vacunación infantil en vivo', 'campaign_id' => $this->c->id];
        // Sin subcampaña, o en una sin en vivos, no deja abrir el estudio
        $this->actingAs($this->admin)->postJson(route('en-vivo.web.preparar'), $base)->assertStatus(422)->assertJsonPath('error', 'Elige la subcampaña del en vivo.');
        $this->actingAs($this->admin)->postJson(route('en-vivo.web.preparar'), $base + ['subcampana_id' => $this->sinVivo->id])->assertStatus(422)->assertJsonFragment(['success' => false]);

        $r = $this->actingAs($this->admin)->postJson(route('en-vivo.web.preparar'), $base + ['subcampana_id' => $this->sub->id])->assertOk()->assertJsonPath('success', true);
        $t = TransmisionEnVivo::findOrFail($r->json('transmision.id'));
        $this->assertSame([$this->c->id, $this->sub->id, 'En vivo'], [(int) $t->campaign_id, (int) $t->subcampana_id, $t->tipo_pieza]);
        $this->assertSame('Salud 2026 · Salud infantil · En vivo', $r->json('transmision.campana'));
        $this->assertSame(0, MetaPost::count()); // en la sala de espera aún no cuenta

        // Al salir al aire queda como publicación de la campaña y pieza del informe
        $this->actingAs($this->admin)->postJson(route('en-vivo.web.aire', $t))->assertOk()->assertJsonPath('success', true);
        $post = MetaPost::firstOrFail();
        $this->assertSame([$this->c->id, $this->sub->id, 'En vivo', 'success', 'video', (int) $this->pagina->id], [(int) $post->campaign_id, (int) $post->subcampana_id, $post->tipo_pieza, $post->status, $post->type, (int) $post->meta_page_id]);
        $this->assertNotNull($post->fb_post_id);
        $pieza = Producto::findOrFail($post->producto_id);
        $this->assertSame(['En vivo', $this->sub->id], [$pieza->tipo->etiquetaSingular(), (int) $pieza->subcampana_id]);
        $this->assertSame(1, app(\App\Services\PlanMediosService::class)->usados($this->c)[$this->pagina->id]['en vivo'] ?? null);

        // Al terminar no se duplica: se actualiza con el video definitivo
        $this->actingAs($this->admin)->postJson(route('en-vivo.web.terminar', $t))->assertOk();
        $this->assertSame(1, MetaPost::count());
        $this->assertSame(1, Producto::count());
        $this->assertSame('7002', MetaPost::first()->fb_post_id);
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $this->sub]))->assertOk()->assertSee('Vacunación infantil en vivo');
    }

    public function test_en_vivo_sin_campana_sigue_igual(): void
    {
        $r = $this->actingAs($this->admin)->postJson(route('en-vivo.web.preparar'), ['page_ids' => ['111'], 'titulo' => 'Noticias'])->assertOk();
        $t = TransmisionEnVivo::findOrFail($r->json('transmision.id'));
        $this->actingAs($this->admin)->postJson(route('en-vivo.web.aire', $t))->assertOk();
        $this->assertNull($t->fresh()->campaign_id);
        $this->assertSame(0, MetaPost::count());
    }

    public function test_la_app_lista_las_campanas_con_en_vivo(): void
    {
        $this->withHeader('X-Editus-Token', 'tok-integracion')->getJson('/api/en-vivo/campanas')->assertOk()->assertJsonPath('campanas.0.subs.0.tipos.0', 'En vivo');
    }

    /** Pregrabados: tabla, emisor de prueba y el Live de Facebook (7001 → video 7002). */
    private function prepararPregrabados(): void
    {
        config(['services.emisor.url' => 'https://emisor.prueba.test/emisor', 'services.emisor.secreto' => str_repeat('e', 40)]);
        (require database_path('migrations/2026_10_15_000001_create_emisiones_programadas.php'))->up();
        (require database_path('migrations/2026_10_17_000001_notificaciones_y_miniaturas.php'))->up();
        (require database_path('migrations/2026_11_19_000002_campana_en_pregrabados.php'))->up();
    }

    private function emisionQueSalio(array $extra = []): \App\Models\EmisionProgramada
    {
        return \App\Models\EmisionProgramada::create($extra + [
            'user_id' => $this->admin->id, 'emisor_id' => 'em-prueba-123456', 'titulo' => 'Leishmaniasis: síntomas y prevención', 'descripcion' => 'Charla',
            'programada_en' => now()->subHour(), 'estado' => 'terminado', 'progreso' => 100, 'archivo_nombre' => 'Leishmaniasis.mp4', 'archivo_bytes' => 363093964,
            'inicio_real' => now()->subHour(), 'fin_real' => now()->subMinutes(20),
            'destinos' => [['red' => 'facebook', 'meta_page_id' => $this->pagina->id, 'nombre' => 'Opa Noticias', 'live_id' => '7001', 'stream_url' => null,
                'permalink' => 'https://www.facebook.com/opa/videos/7002/', 'estado' => 'terminado', 'error' => null]],
        ]);
    }

    public function test_pregrabado_programado_en_la_campana_suma_al_informe_al_salir_al_aire(): void
    {
        $this->prepararPregrabados();
        $this->actingAs($this->admin)->get(route('pregrabados.index'))->assertOk()
            ->assertSee('Campaña (plan de medios)')->assertSee('data-page-id="111"', false);

        $datos = ['titulo' => 'Rabia: vacuna a tus mascotas', 'programada_en' => now()->addHour()->format('Y-m-d\TH:i'), 'meta_page_ids' => [$this->pagina->id],
            'archivo_nombre' => 'rabia.mp4', 'archivo_bytes' => 5_000_000, 'campaign_id' => $this->c->id];
        $this->actingAs($this->admin)->postJson(route('pregrabados.store'), $datos)->assertStatus(422)->assertJsonPath('error', 'Elige la subcampaña del en vivo.');
        $r = $this->actingAs($this->admin)->postJson(route('pregrabados.store'), $datos + ['subcampana_id' => $this->sub->id])->assertOk();
        $e = \App\Models\EmisionProgramada::findOrFail($r->json('emision.id'));
        $this->assertSame([$this->c->id, $this->sub->id, 'En vivo'], [(int) $e->campaign_id, (int) $e->subcampana_id, $e->tipo_pieza]);
        $this->assertSame('Salud 2026 · Salud infantil · En vivo', $r->json('emision.campana'));
        $this->assertSame(0, MetaPost::count());

        // A la hora sale al aire: cada página de Facebook queda como publicación de la campaña (una pieza)
        $e->forceFill(['estado' => 'listo'])->save();
        app(\App\Services\EmisionesService::class)->arrancar($e);
        $e->refresh();
        $this->assertSame('al_aire', $e->estado);
        $post = MetaPost::firstOrFail();
        $this->assertSame([$this->c->id, $this->sub->id, 'En vivo', 'video', '7002'], [(int) $post->campaign_id, (int) $post->subcampana_id, $post->tipo_pieza, $post->type, (string) $post->fb_post_id]);
        $pieza = Producto::findOrFail($post->producto_id);
        $this->assertSame('Rabia: vacuna a tus mascotas', MetaPost::first()->message);
        $this->assertSame((int) $this->sub->id, (int) $pieza->subcampana_id);
        // Volver a registrarlo (al terminar) no duplica
        app(EnVivoCampanaService::class)->registrarEmision($e);
        $this->assertSame([1, 1], [MetaPost::count(), Producto::count()]);
    }

    public function test_pregrabado_que_ya_salio_se_suma_despues_a_la_campana(): void
    {
        $this->prepararPregrabados();
        $e = $this->emisionQueSalio();
        $vista = $e->paraVista();
        $this->assertTrue($vista['puede_campana']);
        $this->assertNull($vista['campana']);

        $this->actingAs($this->admin)->postJson(route('pregrabados.campana', $e), ['campaign_id' => $this->c->id, 'subcampana_id' => $this->sinVivo->id])
            ->assertStatus(422)->assertJsonFragment(['success' => false]);
        $r = $this->actingAs($this->admin)->postJson(route('pregrabados.campana', $e), ['campaign_id' => $this->c->id, 'subcampana_id' => $this->sub->id])
            ->assertOk()->assertJsonPath('publicaciones', 1)->assertJsonPath('emision.campana', 'Salud 2026 · Salud infantil · En vivo')->assertJsonPath('emision.puede_campana', false);
        $post = MetaPost::firstOrFail();
        // El id del video lo pide a Facebook (el Live 7001 quedó como video 7002) y la fecha es la de salida al aire
        $this->assertSame(['7002', 'https://www.facebook.com/opa/videos/7002/', 'success'], [(string) $post->fb_post_id, $post->fb_permalink_url, $post->status]);
        $this->assertSame($e->inicio_real->toDateTimeString(), $post->published_at->toDateTimeString());
        $this->assertNotNull($post->producto_id);
        $this->assertSame('7002', $e->fresh()->destinos[0]['video_id']);
        // Ya está en la campaña: no se puede volver a sumar
        $this->actingAs($this->admin)->postJson(route('pregrabados.campana', $e), ['campaign_id' => $this->c->id, 'subcampana_id' => $this->sub->id])
            ->assertStatus(422)->assertJsonPath('error', 'Este pregrabado ya está en una campaña.');

        // Uno de otro usuario no se puede tocar; uno cancelado no ofrece la opción
        $otro = User::factory()->create(['role_id' => Role::where('slug', 'user')->value('id') ?? Role::where('slug', 'admin')->value('id')]);
        $ajena = $this->emisionQueSalio(['emisor_id' => 'em-prueba-999999']);
        if (!$otro->isAdmin()) $this->actingAs($otro)->postJson(route('pregrabados.campana', $ajena), ['campaign_id' => $this->c->id, 'subcampana_id' => $this->sub->id])->assertStatus(403);
        $this->assertFalse($this->emisionQueSalio(['emisor_id' => 'em-prueba-888888', 'estado' => 'cancelado'])->paraVista()['puede_campana']);
    }
}
