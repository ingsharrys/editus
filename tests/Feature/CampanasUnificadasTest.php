<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Campana;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Role;
use App\Models\Tema;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\Inteligencia\ClasificadorService;
use App\Services\Inteligencia\ClaudeService;
use App\Services\Inteligencia\ComentariosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Campañas unificadas: una sola campaña (se crea en el módulo Campañas) con sus medios,
 * su perfil de análisis en Inteligencia y la preselección de páginas al publicar.
 */
class CampanasUnificadasTest extends TestCase
{
    private const TOKEN = 'token-de-prueba';
    private User $admin;
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.editus.ingest_token' => self::TOKEN, 'services.facebook.version' => 'v23.0',
            'services.editus.medios' => ['opanoticias' => 'Opanoticias', 'neiva24' => 'Neiva 24', 'elcivico' => 'El Cívico'],
        ]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app')->nullable(); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->text('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('meta_posts', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->uuid('batch_uuid')->nullable(); $t->string('type'); $t->string('network')->nullable();
            $t->text('message')->nullable(); $t->string('link')->nullable(); $t->json('local_media')->nullable(); $t->json('fb_media_ids')->nullable();
            $t->string('fb_post_id')->nullable(); $t->string('fb_permalink_url')->nullable(); $t->string('status')->default('pending');
            $t->unsignedInteger('alcance')->nullable(); $t->unsignedInteger('visualizaciones')->nullable(); $t->unsignedInteger('interacciones')->nullable();
            $t->string('evidencia_path')->nullable(); $t->timestamp('last_insights_at')->nullable(); $t->text('error')->nullable();
            $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_07_26_000001_create_campaigns_table.php'))->up(); // crea "Salud 2025" y "Esnoticia"
        (require database_path('migrations/2025_10_03_100000_create_inteligencia_audiencia.php'))->up();
        (require database_path('migrations/2025_10_09_100000_consultor_ia_y_emociones.php'))->up();

        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $rol->id]);
        foreach ([['111', 'Opanoticias', 'opanoticias'], ['112', 'Opa You', 'opanoticias'], ['222', 'Neiva 24', 'neiva24'], ['333', 'El Cívico', 'elcivico'], ['444', 'Alohas BAR', null]] as [$id, $n, $m]) {
            $pg = MetaPage::create(['page_id' => $id, 'name' => $n, 'medio_slug' => $m]);
            $pg->users()->attach($this->admin->id, ['page_access_token' => "tok-{$id}", 'is_active' => true]);
            $this->p[$id] = $pg;
        }
    }

    private function unificar(): void
    {
        (require database_path('migrations/2026_10_10_000001_unificar_campanas.php'))->up();
    }

    public function test_la_migracion_une_las_campanas_sin_tocar_esnoticia(): void
    {
        // Campaña creada antes en Inteligencia, con dos páginas de Opanoticias y una sin medio
        $vieja = Campana::create(['nombre' => 'Alcaldía 2027', 'territorio' => 'Neiva', 'descripcion' => 'Candidato X', 'activa' => true]);
        $vieja->paginas()->sync([$this->p['111']->id, $this->p['112']->id, $this->p['444']->id]);
        Tema::create(['campana_id' => $vieja->id, 'nombre' => 'Seguridad', 'orden' => 0]);
        $esnoticiaAntes = DB::table('campaigns')->where('slug', 'esnoticia')->first();

        $this->unificar();
        $this->unificar(); // idempotente

        $c = Campaign::where('name', 'Alcaldía 2027')->first();
        $this->assertNotNull($c);
        $this->assertSame('politica', $c->tipo);
        $this->assertSame(['opanoticias'], $c->medios);
        $this->assertSame('Candidato X', $c->contexto);
        $perfil = $c->perfil;
        $this->assertSame($vieja->id, $perfil->id, 'el perfil es la campaña de Inteligencia de antes: conserva temas e informes');
        $this->assertSame(1, $perfil->temas()->count());
        $origenes = $perfil->paginas()->get()->mapWithKeys(fn($p) => [$p->page_id => $p->pivot->origen])->all();
        $this->assertSame(['111' => 'medio', '112' => 'medio', '444' => 'manual'], collect($origenes)->sortKeys()->all());

        // Esnoticia: intacta, con perfil de análisis que cubre todas las páginas con medio
        $esn = Campaign::where('slug', 'esnoticia')->first();
        $this->assertSame($esnoticiaAntes->name, $esn->name);
        $this->assertSame($esnoticiaAntes->description, $esn->description);
        $this->assertTrue($esn->is_system);
        $this->assertTrue($esn->is_active);
        $this->assertSame(4, $esn->perfil->paginas()->count());
        $this->assertTrue($esn->perfil->esDeSistema());

        // "Salud 2025" (de publicación) también recibe su perfil
        $this->assertNotNull(Campaign::where('slug', 'salud-2025')->first()->perfil);
        $this->assertSame(3, Campana::count());
    }

    public function test_crear_y_editar_campana_solo_en_el_modulo_campanas(): void
    {
        $this->unificar();
        $this->actingAs($this->admin)->get('/campanas')->assertOk()->assertSee('Nueva campaña')->assertSee('Esnoticia')->assertSee('Analizar con IA');
        $this->actingAs($this->admin)->get('/campanas/nueva')->assertOk()->assertSee('¿En qué medios se publica?')->assertSee('Neiva 24');

        // Sin medios no se puede
        $this->actingAs($this->admin)->post('/campanas', ['name' => 'Sin medios', 'tipo' => 'comercial'])->assertSessionHasErrors('medios');

        $this->actingAs($this->admin)->post('/campanas', [
            'name' => 'Ferretería El Tornillo', 'tipo' => 'comercial', 'description' => 'Pauta de temporada', 'contexto' => 'Ferretería del centro de Neiva',
            'territorio' => 'Neiva', 'medios' => ['neiva24', 'elcivico'], 'paginas_extra' => [$this->p['444']->id], 'temas' => "Ofertas\nHerramientas",
        ])->assertRedirect('/campanas');
        $c = Campaign::where('name', 'Ferretería El Tornillo')->first();
        $this->assertSame(['neiva24', 'elcivico'], $c->medios);
        $this->assertSame(['222', '333', '444'], $c->perfil->paginas()->pluck('page_id')->sort()->values()->all());
        $this->assertSame(['Ofertas', 'Herramientas'], $c->perfil->temas->pluck('nombre')->all());
        $this->assertSame('Ferretería del centro de Neiva', $c->perfil->descripcion);

        // Editar: cambiar medios y quitar la página extra
        $this->actingAs($this->admin)->get("/campanas/{$c->id}/editar")->assertOk()->assertSee('Ferretería El Tornillo');
        $this->actingAs($this->admin)->post("/campanas/{$c->id}", ['name' => 'Ferretería El Tornillo', 'tipo' => 'comercial', 'medios' => ['opanoticias']])->assertRedirect('/campanas');
        $this->assertSame(['111', '112'], $c->fresh()->perfil->paginas()->pluck('page_id')->sort()->values()->all());

        // Desactivar se refleja en el perfil de análisis
        $this->actingAs($this->admin)->post("/campanas/{$c->id}/toggle")->assertRedirect();
        $this->assertFalse($c->fresh()->perfil->activa);

        // Esnoticia no se edita ni se desactiva
        $esn = Campaign::where('slug', 'esnoticia')->first();
        $this->actingAs($this->admin)->get("/campanas/{$esn->id}/editar")->assertStatus(403);
        $this->actingAs($this->admin)->post("/campanas/{$esn->id}/toggle")->assertStatus(403);

        // Inteligencia ya no crea campañas y muestra todas, incluida Esnoticia
        $r = $this->actingAs($this->admin)->get('/admin/inteligencia')->assertOk()->assertSee('Se crean y se editan únicamente en el módulo')->assertSee('Esnoticia')->assertSee('Ferretería El Tornillo');
        $r->assertDontSee('name="temas"', false)->assertDontSee(route('campaigns.create'), false);
        $this->actingAs($this->admin)->get('/admin/inteligencia/' . $c->perfil->id . '?tab=config')->assertOk()->assertSee('Editar en Campañas');
        $this->actingAs($this->admin)->get('/admin/inteligencia/' . $esn->perfil->id . '?tab=config')->assertOk()->assertSee('La campaña de sistema no se edita');
    }

    public function test_cambiar_el_medio_de_una_pagina_actualiza_las_campanas(): void
    {
        $this->unificar();
        $c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Neiva', 'tipo' => 'politica', 'medios' => ['neiva24']], $this->admin->id);
        $this->assertSame(['222'], $c->perfil()->first()->paginas()->pluck('page_id')->all());
        // Alohas BAR pasa al medio Neiva 24 desde App del editor
        config(['services.esnoticia.url' => 'https://backend.prueba.test/public']);
        Http::fake(['*' => Http::response(['success' => true, 'usuarios' => []])]);
        $visibles = MetaPage::pluck('id')->all();
        $medios = MetaPage::all()->mapWithKeys(fn($p) => [$p->id => $p->medio_slug])->all();
        $medios[$this->p['444']->id] = 'neiva24';
        $this->actingAs($this->admin)->post('/admin/app-editor/paginas', ['visible' => $visibles, 'medio' => $medios])->assertRedirect();
        $this->assertSame(['222', '444'], $c->perfil()->first()->paginas()->pluck('page_id')->sort()->values()->all());
    }

    public function test_la_app_recibe_las_campanas_y_publica_dentro_de_una(): void
    {
        $this->unificar();
        $c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Salud Huila', 'tipo' => 'institucional', 'medios' => ['opanoticias']], $this->admin->id);
        $api = $this->withHeaders(['X-Editus-Token' => self::TOKEN, 'Accept' => 'application/json']);

        $r = $api->get('/api/campanas')->assertOk();
        $lista = collect($r->json('campanas'))->keyBy('nombre');
        $this->assertArrayHasKey('Salud Huila', $lista->all());
        $this->assertArrayNotHasKey('Esnoticia', $lista->all(), 'la de sistema no se elige al publicar');
        $this->assertSame(['opanoticias'], $lista['Salud Huila']['medios']);
        $this->assertSame(['111', '112'], collect($lista['Salud Huila']['page_ids'])->sort()->values()->all());

        Http::fake([
            'graph.facebook.com/v23.0/111/photos' => Http::response(['id' => '9', 'post_id' => '111_9'], 200),
            'graph.facebook.com/*' => Http::response(['permalink_url' => 'https://fb.com/x'], 200),
        ]);
        $api->postJson('/api/publicaciones/foto', ['texto' => 'Hola', 'imagen_url' => 'https://img.test/a.jpg', 'paginas' => [['page_id' => '111', 'facebook' => true]], 'campaign_id' => $c->id])->assertOk();
        $this->assertSame($c->id, (int) MetaPost::latest('id')->first()->campaign_id);
        // Esnoticia no se acepta desde la app: queda sin campaña
        $esn = Campaign::where('slug', 'esnoticia')->first();
        $api->postJson('/api/publicaciones/foto', ['texto' => 'Hola 2', 'imagen_url' => 'https://img.test/a.jpg', 'paginas' => [['page_id' => '111', 'facebook' => true]], 'campaign_id' => $esn->id])->assertOk();
        $this->assertNull(MetaPost::latest('id')->first()->campaign_id);
    }

    public function test_la_ia_automatica_no_analiza_esnoticia_salvo_que_se_pida(): void
    {
        $this->unificar();
        $esn = Campaign::where('slug', 'esnoticia')->first()->perfil;
        $salud = Campaign::where('slug', 'salud-2025')->first()->perfil;

        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $this->app->instance(ClaudeService::class, $ia);
        $clasificador = \Mockery::mock(ClasificadorService::class);
        $comentarios = \Mockery::mock(ComentariosService::class);
        $vistas = [];
        $clasificador->shouldReceive('clasificarCampana')->andReturnUsing(function ($c) use (&$vistas) { $vistas[] = $c->id; return 0; });
        $comentarios->shouldReceive('analizarCampana')->andReturn(0);
        $this->app->instance(ClasificadorService::class, $clasificador);
        $this->app->instance(ComentariosService::class, $comentarios);

        $this->artisan('inteligencia:analizar')->assertExitCode(0);
        $this->assertContains($salud->id, $vistas);
        $this->assertNotContains($esn->id, $vistas);

        $vistas = [];
        $this->artisan('inteligencia:analizar --campana=' . $esn->id)->assertExitCode(0);
        $this->assertSame([$esn->id], $vistas, 'a pedido del operador sí se analiza');
    }
}
