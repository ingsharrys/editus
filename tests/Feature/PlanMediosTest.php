<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignCupo;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Role;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\PlanMediosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Plan de medios: qué campañas lo usan, subcampañas, cupos de piezas por página y métricas. */
class PlanMediosTest extends TestCase
{
    use Concerns\EsquemaPlanMedios;

    protected User $admin;
    protected Campaign $c;
    protected MetaPage $canal6;
    protected MetaPage $otra;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias', 'neiva24' => 'Neiva 24'], 'services.facebook.version' => 'v23.0']);
        $this->crearEsquemaPlanMedios();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->canal6 = MetaPage::create(['page_id' => '600', 'name' => 'Canal 6 Alfasurt', 'medio_slug' => 'opanoticias']);
        $this->otra = MetaPage::create(['page_id' => '700', 'name' => 'Otra página', 'medio_slug' => 'opanoticias']);
        foreach ([$this->canal6, $this->otra] as $pg) $pg->users()->attach($this->admin->id, ['page_access_token' => 'tok-' . $pg->page_id, 'is_active' => true]);
        $this->c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias']], $this->admin->id);
    }

    private function guardarCampana(array $extra = []): void
    {
        $this->actingAs($this->admin)->post(route('campaigns.update', $this->c), $extra + [
            'name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias'], 'plan_medios' => '1',
            'cert_tipos_pieza' => "Video Corto\nVideo Largo\nPost",
        ])->assertRedirect();
        $this->c->refresh();
    }

    /** Tipos de publicación de la campaña: Videos cortos (Video Corto), Videos largos (Video Largo), Post 600x600 (Post). */
    private function tiposPublicacion(): array
    {
        $this->actingAs($this->admin)->post(route('campaigns.plan.tipos.guardar', $this->c), ['tipos' => [
            ['nombre' => 'Videos cortos', 'etiqueta' => 'Video Corto', 'clase' => 'redes', 'tipo' => 'VIDEO', 'is_active' => '1'],
            ['nombre' => 'Videos largos', 'etiqueta' => 'Video Largo', 'clase' => 'redes', 'tipo' => 'VIDEO', 'unidad' => 'video largo', 'is_active' => '1'],
            ['nombre' => 'Post 600x600', 'etiqueta' => 'Post', 'clase' => 'redes', 'formato' => '600 x 600 px', 'is_active' => '1'],
            ['nombre' => '', 'etiqueta' => 'fila vacía: no se crea'],
        ]])->assertRedirect(route('campaigns.plan.tipos', $this->c));
        return TipoPublicacion::orderBy('orden')->get()->all();
    }

    /** Subcampañas = temas: «Dengue» (Videos cortos ×3 y Videos largos) y «Vacunación» (sin tipos asignados = todos). */
    private function subcampanas(): array
    {
        [$vc, $vl] = $this->tiposPublicacion();
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['nombre' => 'Dengue', 'subprograma' => 'Dengue', 'is_active' => '1', 'tipos' => [$vc->id => ['on' => '1', 'cantidad' => '3'], $vl->id => ['on' => '1', 'cantidad' => '']]],
            ['nombre' => 'Vacunación', 'is_active' => '1'],
            ['nombre' => '', 'subprograma' => 'fila vacía: no se crea'],
        ]])->assertRedirect(route('campaigns.plan.subcampanas', $this->c));
        return Subcampana::orderBy('orden')->get()->all();
    }

    private function publicar(array $datos)
    {
        return $this->actingAs($this->admin)->post(route('meta.pages.publish'), $datos + [
            'campaign_id' => $this->c->id, 'type' => 'text', 'networks' => ['facebook'], 'message' => 'Pieza ' . Str::random(4),
        ]);
    }

    public function test_solo_las_campanas_con_plan_de_medios_tienen_inscripcion_tablero_y_cupos(): void
    {
        // Sin plan: aunque se marque la inscripción, no se abre, y las pantallas del plan no existen
        $this->guardarCampana(['plan_medios' => '0', 'inscripcion_activa' => '1']);
        $this->assertFalse($this->c->plan_medios);
        $this->assertFalse($this->c->inscripcion_activa);
        $this->actingAs($this->admin)->get(route('campaigns.plan', $this->c))->assertNotFound();
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertDontSee('Tablero del plan');

        $this->guardarCampana(['inscripcion_activa' => '1']);
        $this->assertTrue($this->c->plan_medios);
        $this->assertTrue($this->c->inscripcion_activa);
        $this->actingAs($this->admin)->get(route('campaigns.index'))->assertSee('Plan de medios')->assertSee('Tablero del plan');
        $this->actingAs($this->admin)->get(route('campaigns.edit', $this->c))->assertOk()->assertSee('Esta campaña es un plan de medios')->assertSee(route('campaigns.plan.cupos', $this->c), false);
        foreach (['campaigns.plan', 'campaigns.plan.subcampanas', 'campaigns.plan.cupos', 'campaigns.piezas'] as $r) {
            $this->actingAs($this->admin)->get(route($r, $this->c))->assertOk();
        }
        // Quien no tiene el permiso de campañas no entra
        $normal = User::factory()->create(['role_id' => null]);
        $this->actingAs($normal)->get(route('campaigns.plan', $this->c))->assertForbidden();
    }

    public function test_subcampanas_son_temas_con_sus_tipos_de_publicacion(): void
    {
        $this->guardarCampana();
        [$dengue, $vacunas] = $this->subcampanas();
        [$vc, $vl, $post] = TipoPublicacion::orderBy('orden')->get()->all();
        $this->assertSame(2, Subcampana::count());
        $this->assertSame(['Dengue', 'Dengue', 0], [$dengue->nombre, $dengue->subprograma, $dengue->orden]);
        $this->assertSame([$vc->id => 3, $vl->id => null], $dengue->tipos->mapWithKeys(fn($t) => [$t->id => $t->pivot->cantidad])->all());
        $plan = app(PlanMediosService::class);
        $this->assertSame(['Video Corto', 'Video Largo', 'Post'], $plan->tipos($this->c), 'los tipos de pieza son los tipos de publicación');
        $this->assertSame(['Video Corto', 'Video Largo'], $plan->tiposDe($this->c, $dengue));
        $this->assertSame(['Video Corto', 'Video Largo', 'Post'], $plan->tiposDe($this->c, $vacunas), 'sin tipos asignados: todos');
        $this->actingAs($this->admin)->get(route('campaigns.plan.subcampanas', $this->c))->assertOk()->assertSee('Dengue')->assertSee('Tipos de publicación de esta subcampaña');
        $this->actingAs($this->admin)->get(route('campaigns.plan.tipos', $this->c))->assertOk()->assertSee('Videos largos');

        // Renombrar el singular de un tipo: cambian sus publicaciones y su cupo
        $p = MetaPost::create(['meta_page_id' => $this->canal6->id, 'user_id' => $this->admin->id, 'campaign_id' => $this->c->id, 'type' => 'video', 'status' => 'success',
            'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Corto']);
        $this->actingAs($this->admin)->post(route('campaigns.plan.cupos.guardar', $this->c), ['cupos' => ['defecto' => ['20', '56', '']]])->assertRedirect();
        $this->actingAs($this->admin)->post(route('campaigns.plan.tipos.guardar', $this->c), ['tipos' => [
            ['id' => $vc->id, 'nombre' => 'Videos cortos', 'etiqueta' => 'Reel', 'clase' => 'redes', 'is_active' => '1'],
            ['id' => $vl->id, 'nombre' => 'Videos largos', 'etiqueta' => 'Video Largo', 'clase' => 'redes', 'is_active' => '0'],
            ['id' => $post->id, 'nombre' => 'Post 600x600', 'etiqueta' => 'Post', 'clase' => 'redes', 'is_active' => '1'],
        ]])->assertRedirect();
        $this->assertSame('Reel', $p->fresh()->tipo_pieza);
        $this->assertSame(20, $plan->cupoDe($plan->cupos($this->c), $this->canal6->id, 'Reel'));
        $this->assertFalse($vl->fresh()->is_active);

        // Quitar un tipo de la subcampaña y borrar la subcampaña: la publicación queda sin subcampaña
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['id' => $dengue->id, 'nombre' => 'Dengue', 'is_active' => '1', 'tipos' => [$vc->id => ['on' => '1', 'cantidad' => '5'], $vl->id => ['on' => '0']]],
            ['id' => $vacunas->id, 'nombre' => 'Vacunación', 'is_active' => '1'],
        ]])->assertRedirect();
        $this->assertSame([$vc->id => 5], $dengue->fresh()->tipos->mapWithKeys(fn($t) => [$t->id => $t->pivot->cantidad])->all());
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['id' => $dengue->id, 'nombre' => 'Dengue', 'borrar' => '1'],
        ]])->assertRedirect();
        $this->assertNull(Subcampana::find($dengue->id));
        $this->assertNull($p->fresh()->subcampana_id);
    }

    public function test_publicar_exige_subcampana_y_uno_de_sus_tipos_y_respeta_los_cupos_por_pagina(): void
    {
        $this->guardarCampana();
        [$dengue, $vacunas] = $this->subcampanas();
        // Por defecto 2 «Video Corto» por página; Canal 6 tiene 3
        $this->actingAs($this->admin)->post(route('campaigns.plan.cupos.guardar', $this->c), ['cupos' => [
            'defecto' => [0 => '2', 1 => '', 2 => '10'],
            $this->canal6->id => [0 => '3'],
            999 => [0 => '50'], // página que no es de la campaña: se ignora
        ]])->assertRedirect(route('campaigns.plan.cupos', $this->c));
        $this->assertSame(3, CampaignCupo::count());
        $plan = app(PlanMediosService::class);
        $cupos = $plan->cupos($this->c);
        $this->assertSame(3, $plan->cupoDe($cupos, $this->canal6->id, 'video corto'));
        $this->assertSame(2, $plan->cupoDe($cupos, $this->otra->id, 'Video Corto'));
        $this->assertNull($plan->cupoDe($cupos, $this->otra->id, 'Video Largo'), 'sin cupo = sin límite');

        // La pantalla de publicar trae las subcampañas con sus tipos y los cupos de la campaña
        $r = $this->actingAs($this->admin)->get(route('meta.pages.index'))->assertOk()->assertSee('id="piezaSub"', false);
        preg_match("/data-piezas='([^']*)'/", $r->getContent(), $mm);
        $conf = json_decode(html_entity_decode($mm[1] ?? '{}'), true)[$this->c->id] ?? null;
        $this->assertSame(['Dengue', ['Video Corto', 'Video Largo']], [$conf['subs'][0]['nombre'] ?? null, $conf['subs'][0]['tipos'] ?? null]);
        $this->assertSame([0, 3], $conf['cupos'][$this->canal6->id]['video corto'] ?? null);

        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1_1', 'permalink_url' => 'https://fb.com/x'], 200)]);
        $paginas = [$this->canal6->id, $this->otra->id];

        $this->publicar(['page_ids' => $paginas, 'tipo_pieza' => 'Video Corto'])->assertSessionHasErrors('subcampana_id');
        $this->publicar(['page_ids' => $paginas, 'subcampana_id' => 99999, 'tipo_pieza' => 'Video Corto'])->assertSessionHasErrors('subcampana_id');
        $this->publicar(['page_ids' => $paginas, 'subcampana_id' => $dengue->id])->assertSessionHasErrors('tipo_pieza');
        $this->publicar(['page_ids' => $paginas, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Post'])->assertSessionHasErrors('tipo_pieza');
        $this->assertSame(0, MetaPost::count());

        $this->publicar(['page_ids' => $paginas, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'video corto'])->assertSessionHasNoErrors();
        $post = MetaPost::latest('id')->first();
        $this->assertSame([$dengue->id, 'Video Corto', 'Dengue'], [(int) $post->subcampana_id, $post->tipo_pieza, $post->subprograma]);
        $this->publicar(['page_ids' => $paginas, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Corto'])->assertSessionHasNoErrors();

        // «Otra página» ya usó sus 2: no se publica en ninguna (hay que quitarla o subir el cupo)
        $r = $this->publicar(['page_ids' => $paginas, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Corto']);
        $r->assertSessionHasErrors('page_ids');
        $this->assertStringContainsString('Otra página (2 de 2)', session('errors')->first('page_ids'));
        $this->assertSame(4, MetaPost::count());
        // Canal 6 aún tiene uno
        $this->publicar(['page_ids' => [$this->canal6->id], 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Corto'])->assertSessionHasNoErrors();
        $this->publicar(['page_ids' => [$this->canal6->id], 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Corto'])->assertSessionHasErrors('page_ids');
        // Otro tipo sin cupo: sin límite; la subcampaña sin tipos asignados acepta cualquiera
        $this->publicar(['page_ids' => $paginas, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Largo'])->assertSessionHasNoErrors();
        $this->publicar(['page_ids' => [$this->otra->id], 'subcampana_id' => $vacunas->id, 'tipo_pieza' => 'Post'])->assertSessionHasNoErrors();

        $this->assertSame(['video corto' => 3, 'video largo' => 1], $plan->usados($this->c)[$this->canal6->id]);
        $this->actingAs($this->admin)->get(route('campaigns.plan', $this->c))->assertOk()->assertSee('3 / 3')->assertSee('2 / 2');
    }

    public function test_sin_subcampanas_se_elige_el_tipo_de_pieza(): void
    {
        $this->guardarCampana();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1_1', 'permalink_url' => 'https://fb.com/x'], 200)]);
        $this->publicar(['page_ids' => [$this->canal6->id]])->assertSessionHasErrors('tipo_pieza');
        $this->publicar(['page_ids' => [$this->canal6->id], 'tipo_pieza' => 'Inventado'])->assertSessionHasErrors('tipo_pieza');
        $this->publicar(['page_ids' => [$this->canal6->id], 'tipo_pieza' => 'video largo'])->assertSessionHasNoErrors();
        $this->assertSame('Video Largo', MetaPost::latest('id')->first()->tipo_pieza);
    }

    public function test_una_pieza_en_facebook_e_instagram_cuenta_una_vez_y_las_fallidas_no_cuentan(): void
    {
        $this->guardarCampana();
        $lote = (string) Str::uuid();
        $base = ['meta_page_id' => $this->canal6->id, 'user_id' => $this->admin->id, 'campaign_id' => $this->c->id, 'type' => 'video', 'tipo_pieza' => 'Video Corto'];
        MetaPost::create($base + ['batch_uuid' => $lote, 'network' => 'facebook', 'status' => 'success']);
        MetaPost::create($base + ['batch_uuid' => $lote, 'network' => 'instagram', 'status' => 'pending']);
        MetaPost::create($base + ['batch_uuid' => (string) Str::uuid(), 'network' => 'facebook', 'status' => 'fail']);
        $this->assertSame(1, app(PlanMediosService::class)->usados($this->c)[$this->canal6->id]['video corto']);
    }

    public function test_metricas_por_subcampana_y_de_toda_la_campana(): void
    {
        $this->guardarCampana();
        [$dengue, $vacunas] = $this->subcampanas();
        $post = fn(array $d) => MetaPost::create($d + ['user_id' => $this->admin->id, 'campaign_id' => $this->c->id, 'type' => 'video', 'network' => 'facebook', 'status' => 'success', 'published_at' => now(), 'batch_uuid' => (string) Str::uuid()]);
        $post(['meta_page_id' => $this->canal6->id, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Corto', 'alcance' => 1000, 'visualizaciones' => 800, 'interacciones' => 50]);
        $post(['meta_page_id' => $this->otra->id, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Video Largo', 'alcance' => 500, 'visualizaciones' => 300, 'interacciones' => 20]);
        $post(['meta_page_id' => $this->canal6->id, 'subcampana_id' => $vacunas->id, 'tipo_pieza' => 'Post', 'alcance' => 200, 'visualizaciones' => 0, 'interacciones' => 5]);
        $post(['meta_page_id' => $this->canal6->id, 'subcampana_id' => null, 'tipo_pieza' => null, 'alcance' => 100, 'interacciones' => 1]);
        $post(['meta_page_id' => $this->canal6->id, 'subcampana_id' => $dengue->id, 'tipo_pieza' => 'Post', 'alcance' => 99999, 'status' => 'fail']);

        $m = app(PlanMediosService::class)->metricas($this->c);
        $this->assertSame(['piezas' => 4, 'alcance' => 1800, 'interacciones' => 76], array_intersect_key($m['total'], array_flip(['piezas', 'alcance', 'interacciones'])));
        $porSub = collect($m['por_subcampana'])->mapWithKeys(fn($r) => [$r['sub']?->nombre ?? 'sin' => [$r['piezas'], $r['alcance']]])->all();
        $this->assertSame(['Dengue' => [2, 1500], 'Vacunación' => [1, 200], 'sin' => [1, 100]], $porSub);

        $d = app(PlanMediosService::class)->metricas($this->c, $dengue->id);
        $this->assertSame(1500, $d['seleccion']['alcance']);
        $this->assertSame(['Video Corto', 'Video Largo'], $d['por_tipo']->keys()->all());
        $this->assertSame(['Canal 6 Alfasurt', 'Otra página'], collect($d['por_pagina'])->pluck('pagina')->all());

        $this->actingAs($this->admin)->get(route('campaigns.plan', $this->c))->assertOk()->assertSee('Dengue')->assertSee('1.800')->assertSee('Sin subcampaña');
        $this->actingAs($this->admin)->get(route('campaigns.plan', [$this->c, 'sub' => $dengue->id]))->assertOk()->assertSee('1.500');
        $this->actingAs($this->admin)->get(route('campaigns.plan', [$this->c, 'sub' => 0]))->assertOk();
        $this->actingAs($this->admin)->get(route('campaigns.plan', [$this->c, 'sub' => 9999]))->assertNotFound();
    }

    public function test_clasificar_publicaciones_ya_hechas_con_subcampana_y_tipo(): void
    {
        $this->guardarCampana();
        [$dengue] = $this->subcampanas();
        $p = MetaPost::create(['meta_page_id' => $this->canal6->id, 'user_id' => $this->admin->id, 'campaign_id' => $this->c->id, 'type' => 'video', 'status' => 'success', 'message' => 'Cuidado con el zancudo']);
        $this->actingAs($this->admin)->get(route('campaigns.piezas', [$this->c, 'sin' => 1]))->assertOk()->assertSee('Cuidado con el zancudo')->assertSee('— Sin subcampaña —');
        $this->actingAs($this->admin)->post(route('campaigns.piezas.guardar', $this->c), ['piezas' => [$p->id => ['subcampana_id' => $dengue->id, 'tipo_pieza' => 'video largo']]])->assertRedirect();
        $p->refresh();
        $this->assertSame([$dengue->id, 'Video Largo', 'Dengue'], [(int) $p->subcampana_id, $p->tipo_pieza, $p->subprograma]);
        $this->actingAs($this->admin)->post(route('campaigns.piezas.guardar', $this->c), ['piezas' => [$p->id => ['subcampana_id' => '', 'tipo_pieza' => '']]])->assertRedirect();
        $this->assertSame([null, null], [$p->fresh()->subcampana_id, $p->fresh()->tipo_pieza]);
    }

    public function test_la_migracion_convierte_el_catalogo_de_programas_en_subcampanas(): void
    {
        $this->crearEsquemaPlanMedios(false);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $pg = MetaPage::create(['page_id' => '800', 'name' => 'Pág']);
        $c = Campaign::create(['name' => 'Salud 2027', 'slug' => 'salud-2027', 'is_active' => true, 'piezas' => [
            ['programa' => 'Programa 6. ETV', 'subprograma' => 'Dengue'], ['programa' => 'Programa 9. Pólvora', 'subprograma' => ''],
        ]]);
        $otra = Campaign::create(['name' => 'Ferretería', 'slug' => 'ferreteria', 'is_active' => true]);
        $p = MetaPost::create(['meta_page_id' => $pg->id, 'user_id' => $admin->id, 'campaign_id' => $c->id, 'type' => 'video', 'status' => 'success', 'programa' => 'Programa 6. ETV', 'subprograma' => 'Dengue']);

        $m = require database_path('migrations/2026_10_27_000001_plan_de_medios.php');
        $m->up();
        $m->up(); // idempotente

        $this->assertTrue((bool) DB::table('campaigns')->where('id', $c->id)->value('plan_medios'));
        $this->assertFalse((bool) DB::table('campaigns')->where('id', $otra->id)->value('plan_medios'));
        $this->assertSame(['Dengue', 'Programa 9. Pólvora'], Subcampana::where('campaign_id', $c->id)->orderBy('orden')->pluck('nombre')->all());
        $this->assertSame(Subcampana::where('nombre', 'Dengue')->value('id'), (int) DB::table('meta_posts')->where('id', $p->id)->value('subcampana_id'));
    }
}
