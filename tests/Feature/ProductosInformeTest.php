<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\MetaPostMetric;
use App\Models\Producto;
use App\Models\ProductoPublicacion;
use App\Models\Programa;
use App\Models\Role;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\InformeMetricasService;
use App\Services\ProductosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Control de productos del plan de medios e informe de métricas (Word, PDF, Excel e importación). */
class ProductosInformeTest extends TestCase
{
    use Concerns\EsquemaPlanMedios;

    private User $admin;
    private Campaign $c;
    private MetaPage $canal4;
    private MetaPage $clic;
    private TipoPublicacion $largos;
    private TipoPublicacion $banner;
    private TipoPublicacion $envivo;
    private Subcampana $vacunacion;
    private Programa $p2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias'], 'services.facebook.version' => 'v23.0']);
        $this->crearEsquemaPlanMedios();
        (require database_path('migrations/2025_09_25_203730_create_meta_post_metrics_table.php'))->up();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->canal4 = MetaPage::create(['page_id' => '400', 'name' => 'Canal 4', 'medio_slug' => 'opanoticias']);
        $this->clic = MetaPage::create(['page_id' => '401', 'name' => 'Clic Informativo', 'medio_slug' => 'opanoticias']);
        foreach ([$this->canal4, $this->clic] as $pg) $pg->users()->attach($this->admin->id, ['page_access_token' => 'tok', 'is_active' => true]);
        $this->c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias'],
            'starts_on' => '2025-12-01', 'ends_on' => '2025-12-31'], $this->admin->id);
        $this->c->forceFill(['plan_medios' => true])->save();

        $this->actingAs($this->admin)->post(route('campaigns.plan.tipos.guardar', $this->c), ['tipos' => [
            ['nombre' => 'Videos largos', 'etiqueta' => 'Video Largo', 'clase' => 'redes', 'tipo' => 'VIDEO', 'unidad' => 'video largo', 'is_active' => '1'],
            ['nombre' => 'Banner 1600x185', 'etiqueta' => 'Banner', 'clase' => 'web', 'tipo' => 'IMAGEN', 'formato' => '1600 x 185 px', 'unidad' => 'banner de 1.600x185 px', 'titulo_informe' => 'BANNER 1.600 X 185 PX', 'is_active' => '1'],
            ['nombre' => 'En vivos', 'etiqueta' => 'En vivo', 'clase' => 'envivo', 'is_active' => '1'],
        ]])->assertRedirect();
        [$this->largos, $this->banner, $this->envivo] = TipoPublicacion::orderBy('orden')->get()->all();
        $this->actingAs($this->admin)->post(route('campaigns.plan.programas.guardar', $this->c), ['programas' => [
            ['codigo' => '2', 'nombre' => 'Salud infantil', 'subprogramas' => 'Vacunación, PAI; Nutrición'],
            ['codigo' => '9', 'nombre' => 'Prevención de lesiones por pólvora', 'subprogramas' => ''],
        ]])->assertRedirect();
        $this->p2 = Programa::where('codigo', '2')->first();
        // Subcampaña (tema) con su programa y los tipos que lleva
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['nombre' => 'Vacunación', 'programa_id' => $this->p2->id, 'subprograma' => 'Vacunación', 'is_active' => '1',
                'tipos' => [$this->largos->id => ['on' => '1', 'cantidad' => '4'], $this->banner->id => ['on' => '1'], $this->envivo->id => ['on' => '1']]],
        ]])->assertRedirect();
        $this->vacunacion = Subcampana::first();
    }

    private function pieza(array $d = []): Producto
    {
        $this->actingAs($this->admin)->post(route('campaigns.productos.guardar', $this->c), $d + [
            'tipo_id' => $this->largos->id, 'subcampana_id' => $this->vacunacion->id, 'nombre' => 'P2 Salud Infantil Sp2 Vacunación',
            'estado' => 'Aprobado', 'acta_aprobacion' => '103', 'acta_publicacion' => '103', 'link_archivo' => 'https://drive.google.com/file/d/abc/view',
            'muestra' => UploadedFile::fake()->image('muestra.jpg', 800, 450),
        ])->assertRedirect();
        return Producto::latest('id')->first();
    }

    private function publicacion(Producto $p, MetaPage $pg, array $d = []): MetaPost
    {
        return MetaPost::create($d + ['meta_page_id' => $pg->id, 'user_id' => $this->admin->id, 'campaign_id' => $this->c->id, 'producto_id' => $p->id,
            'type' => 'video', 'network' => 'facebook', 'status' => 'success', 'published_at' => '2025-12-17 10:00:00', 'batch_uuid' => (string) Str::uuid(),
            'fb_permalink_url' => 'https://web.facebook.com/share/v/' . $pg->page_id, 'alcance' => 684, 'visualizaciones' => 111, 'interacciones' => 0]);
    }

    public function test_programas_y_piezas_con_numeracion_y_muestra(): void
    {
        $this->assertSame(['Vacunación', 'PAI', 'Nutrición'], $this->p2->listaSubprogramas());
        $this->assertSame('2. Salud infantil', $this->p2->etiqueta());
        $a = $this->pieza();
        $b = $this->pieza(['nombre' => 'P2 Salud Infantil Sp2 PAI']);
        $w = $this->pieza(['tipo_id' => $this->banner->id, 'nombre' => 'Banner vacunación']);
        $this->assertSame([$this->p2->id, 'Vacunación'], [(int) $a->programa_id, $a->subprograma], 'programa y subprograma vienen de la subcampaña');
        $this->assertSame([1, 2, 1], [$a->numero, $b->numero, $w->numero], 'se numera por tipo de publicación');
        $this->assertSame('Video Largo No. 2', $b->codigo());
        Storage::disk('local')->assertExists($a->muestra_path);
        $this->actingAs($this->admin)->get(route('campaigns.archivo', [$this->c, 'f' => base64_encode($a->muestra_path)]))->assertOk();
        $this->actingAs($this->admin)->get(route('campaigns.archivo', [$this->c, 'f' => base64_encode('../.env')]))->assertNotFound();

        // Pantallas
        $this->actingAs($this->admin)->get(route('campaigns.productos', $this->c))->assertOk()->assertSee('P2 Salud Infantil Sp2 PAI')->assertSee('Resumen por programa y tipo de publicación');
        $this->actingAs($this->admin)->get(route('campaigns.productos.ver', [$this->c, $a]))->assertOk()->assertSee('Video Largo No. 1');
        $this->actingAs($this->admin)->get(route('campaigns.productos.crear', $this->c))->assertOk();
        $this->actingAs($this->admin)->get(route('campaigns.plan.programas', $this->c))->assertOk()->assertSee('Salud infantil');
        $this->actingAs($this->admin)->get(route('campaigns.plan.tipos', $this->c))->assertOk()->assertSee('Banner 1600x185');
        $this->actingAs($this->admin)->get(route('campaigns.plan.subcampanas', $this->c))->assertOk()->assertSee('Vacunación')->assertSee('3/');
        $this->actingAs($this->admin)->get(route('campaigns.productos', [$this->c, 'subcampana' => $this->vacunacion->id]))->assertOk()->assertSee('2 / 4');
        $this->actingAs($this->admin)->get(route('campaigns.informes', $this->c))->assertOk()->assertSee('Descargar Word');

        // Resumen por programa y tipo
        $r = app(ProductosService::class)->resumen($this->c);
        $this->assertSame(3, $r['total']);
        $this->assertSame([2, 1, 0], array_values($r['filas'][0]['por_tipo']));
        $av = app(ProductosService::class)->avanceSubcampanas($this->c)->first();
        $this->assertSame([['Videos largos', 4, 2], ['Banner 1600x185', null, 1], ['En vivos', null, 0]], array_map(fn($x) => [$x['tipo']->nombre, $x['planeadas'], $x['piezas']], $av['tipos']));

        // Validación: el tipo es obligatorio y el programa debe ser de la campaña
        $this->actingAs($this->admin)->post(route('campaigns.productos.guardar', $this->c), ['nombre' => 'X', 'estado' => 'Aprobado', 'programa_id' => 9999])
            ->assertSessionHasErrors(['tipo_id', 'programa_id']);
    }

    public function test_publicar_dentro_de_la_subcampana_crea_la_pieza_y_respeta_cupos(): void
    {
        $this->actingAs($this->admin)->post(route('campaigns.plan.cupos.guardar', $this->c), ['cupos' => ['defecto' => [0 => '1']]])->assertRedirect();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1_1', 'post_id' => '1_1', 'permalink_url' => 'https://fb.com/x'], 200)]);
        $otra = MetaPage::create(['page_id' => '999', 'name' => 'Ajena a la campaña']);
        $otra->users()->attach($this->admin->id, ['page_access_token' => 'tok', 'is_active' => true]);

        // La pantalla de publicar de la subcampaña: campaña y subcampaña fijas, solo las páginas de la campaña
        $r = $this->actingAs($this->admin)->get(route('campaigns.subcampanas.publicar', [$this->c, $this->vacunacion]))->assertOk()
            ->assertSee('Publicando en')->assertSee('Vacunación')->assertSee('Canal 4')->assertDontSee('Ajena a la campaña');
        $this->assertStringContainsString('data-old-sub="' . $this->vacunacion->id . '"', $r->getContent());
        preg_match("/data-piezas='([^']*)'/", $r->getContent(), $mm);
        $conf = json_decode(html_entity_decode($mm[1] ?? '{}'), true)[$this->c->id];
        $this->assertSame([], $conf['productos'], 'no se elige una pieza: cada publicación es una pieza nueva');
        $this->assertSame(['Video Largo', 'Banner', 'En vivo'], $conf['subs'][0]['tipos']);

        $pub = fn(array $d) => $this->actingAs($this->admin)->post(route('meta.pages.publish'), $d + ['campaign_id' => $this->c->id, 'subcampana_id' => $this->vacunacion->id,
            'type' => 'text', 'networks' => ['facebook'], 'message' => 'Vacúnate en tu IPS más cercana', 'page_ids' => [$this->canal4->id, $this->clic->id]]);
        $pub(['tipo_pieza' => ''])->assertSessionHasErrors('tipo_pieza');
        $pub(['tipo_pieza' => 'Video Largo'])->assertSessionHasNoErrors();

        // Un lote = una pieza de la subcampaña, con el programa de la subcampaña
        $this->assertSame(1, Producto::count());
        $pieza = Producto::first();
        $this->assertSame([$this->vacunacion->id, $this->largos->id, $this->p2->id, 'Vacunación', 'Publicado / Entregado'], [(int) $pieza->subcampana_id, (int) $pieza->tipo_id, (int) $pieza->programa_id, $pieza->subprograma, $pieza->estado]);
        $this->assertSame([2, 'Programa 2. Salud infantil'], [MetaPost::where('producto_id', $pieza->id)->count(), MetaPost::first()->programa]);

        // Cupo de 1 «Video Largo» por página
        $pub(['tipo_pieza' => 'Video Largo'])->assertSessionHasErrors('page_ids');

        // La subcampaña muestra su avance y la pieza; el tablero, el cuadro por subcampaña
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $this->vacunacion]))->assertOk()
            ->assertSee('Publicar en Vacunación')->assertSee('Vacúnate en tu IPS')->assertSee('1 pieza(s) publicadas en 2 página(s)', false);
        $this->assertSame(['piezas' => 1, 'publicaciones' => 2, 'planeadas' => 4], app(ProductosService::class)->matrizSubcampanas($this->c)[$this->vacunacion->id][$this->largos->id]);
        $this->actingAs($this->admin)->get(route('campaigns.plan', $this->c))->assertOk()->assertSee('Publicado por subcampaña')->assertSee('/ 4');
    }

    public function test_banner_en_paginas_web_con_pantallazos_y_mover_piezas(): void
    {
        $this->actingAs($this->admin)->get(route('campaigns.banners.crear', [$this->c, 'subcampana' => $this->vacunacion->id]))->assertOk()->assertSee('Páginas web donde salió');
        $this->actingAs($this->admin)->post(route('campaigns.banners.guardar', $this->c), ['tipo_id' => $this->banner->id, 'fecha_publicacion' => '2025-12-01',
            'paginas' => [['medio' => ''], ['medio' => '']]])->assertSessionHasErrors('paginas');
        $this->actingAs($this->admin)->post(route('campaigns.banners.guardar', $this->c), [
            'subcampana_id' => $this->vacunacion->id, 'tipo_id' => $this->banner->id, 'fecha_publicacion' => '2025-12-01', 'fecha_fin' => '2025-12-30', 'acta_aprobacion' => '095',
            'muestra' => UploadedFile::fake()->image('banner.png', 1600, 185),
            'paginas' => [
                ['medio' => 'Neiva al Día', 'url' => 'https://neivaaldia.com/', 'captura' => UploadedFile::fake()->image('a.png', 900, 600)],
                ['medio' => '', 'url' => ''],
                ['medio' => 'La Surco', 'url' => 'https://lasurco.co/', 'captura' => UploadedFile::fake()->image('b.png', 900, 600)],
            ],
        ])->assertRedirect(route('campaigns.subcampanas.ver', [$this->c, $this->vacunacion]));
        $b = Producto::where('tipo_id', $this->banner->id)->first();
        $this->assertSame(['Banner en 2 páginas web', '095', '2025-12-30'], [$b->nombre, $b->acta_aprobacion, $b->fecha_fin->toDateString()]);
        $pubs = app(ProductosService::class)->publicaciones($b);
        $this->assertSame(['Neiva al Día', 'La Surco'], $pubs->pluck('medio')->all());
        $this->assertSame(['local', 'local'], $pubs->map(fn($x) => $x['evidencia'][0])->all());
        $d = app(InformeMetricasService::class)->datos($this->c, []);
        $banner = collect($d['capitulos'][0]['secciones'])->firstWhere('titulo', 'BANNER 1.600 X 185 PX')['piezas'][0];
        $this->assertSame('Publicación de un (1) banner de 1.600x185 px en dos (2) páginas web.', $banner['descripcion']);
        $this->assertContains(['FECHA', '01 al 30 de Diciembre de 2025.'], $banner['datos']);

        // Mover: una publicación sin subcampaña se pasa completa a la subcampaña
        $lote = (string) Str::uuid();
        foreach ([$this->canal4, $this->clic] as $pg) MetaPost::create(['meta_page_id' => $pg->id, 'user_id' => $this->admin->id, 'campaign_id' => $this->c->id, 'batch_uuid' => $lote,
            'type' => 'video', 'network' => 'facebook', 'status' => 'success', 'published_at' => '2025-12-17', 'message' => 'Video sin subcampaña', 'tipo_pieza' => 'Video Largo']);
        $this->actingAs($this->admin)->get(route('campaigns.piezas', $this->c))->assertOk()->assertSee('Video sin subcampaña')->assertSee('2 medio(s)', false);
        $suelta = Producto::where('nombre', 'Video sin subcampaña')->first();
        $this->assertSame([null, $this->largos->id], [$suelta->subcampana_id, (int) $suelta->tipo_id], 'se agrupó como pieza con su tipo');
        $this->actingAs($this->admin)->post(route('campaigns.piezas.guardar', $this->c), ['mover' => [$suelta->id => ['subcampana_id' => $this->vacunacion->id, 'tipo_id' => $this->largos->id]]])->assertRedirect();
        $this->assertSame([$this->vacunacion->id, $this->p2->id], [(int) $suelta->fresh()->subcampana_id, (int) $suelta->fresh()->programa_id]);
        $this->assertSame([$this->vacunacion->id, $this->vacunacion->id], MetaPost::where('batch_uuid', $lote)->pluck('subcampana_id')->map(fn($v) => (int) $v)->all());
        $this->assertSame('Programa 2. Salud infantil', MetaPost::where('batch_uuid', $lote)->value('programa'));
    }

    public function test_el_programa_de_la_subcampana_se_escribe_y_lo_heredan_sus_piezas(): void
    {
        $p = $this->pieza();
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['id' => $this->vacunacion->id, 'nombre' => 'Vacunación', 'programa_texto' => '9. Prevención de lesiones por pólvora', 'subprograma' => 'Pólvora', 'is_active' => '1'],
            ['nombre' => 'Sexualidad', 'programa_texto' => '4. Salud sexual y reproductiva', 'is_active' => '1'],
        ]])->assertRedirect();
        $p9 = Programa::where('codigo', '9')->first();
        $this->assertSame($p9->id, (int) $this->vacunacion->fresh()->programa_id, 'usa el programa que ya existía');
        $this->assertSame(['4', 'Salud sexual y reproductiva'], [Programa::where('codigo', '4')->value('codigo'), Programa::where('codigo', '4')->value('nombre')], 'y crea el nuevo');
        $this->assertSame([$p9->id, 'Pólvora'], [(int) $p->fresh()->programa_id, $p->fresh()->subprograma], 'las piezas de la subcampaña lo heredan');
    }

    public function test_publicaciones_de_la_pieza_editus_mas_registradas_con_captura_y_correcciones(): void
    {
        $p = $this->pieza();
        $a = $this->publicacion($p, $this->canal4);
        $b = $this->publicacion($p, $this->clic, ['alcance' => 546, 'visualizaciones' => 92]);
        $this->publicacion($p, $this->clic, ['status' => 'fail', 'alcance' => 99999]);
        // Captura subida en «Mis publicaciones» (ronda 2) para Canal 4
        Storage::disk('public')->put('meta_posts/1/ev.png', UploadedFile::fake()->image('ev.png', 600, 400)->get());
        MetaPostMetric::create(['meta_post_id' => $a->id, 'round' => 2, 'evidencia_path' => 'meta_posts/1/ev.png']);

        // Corregir métricas y subir captura de Clic Informativo
        $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $p]), [
            'meta_post_id' => $b->id, 'alcance' => '600', 'evidencia' => UploadedFile::fake()->image('c.png', 500, 300),
        ])->assertRedirect();
        // Registrar un medio fuera de editus
        $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $p]), [
            'medio' => 'De Pindo', 'url' => 'https://web.facebook.com/share/v/pindo', 'fecha' => '2025-12-18', 'alcance' => '942', 'visualizaciones' => '151', 'interacciones' => '0',
        ])->assertRedirect();
        $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $p]), ['url' => 'https://x.co'])->assertSessionHasErrors('medio');

        $pubs = app(ProductosService::class)->publicaciones($p);
        $this->assertSame(['Canal 4', 'Clic Informativo', 'De Pindo'], $pubs->pluck('medio')->all());
        $this->assertSame([684, 600, 942], $pubs->pluck('alcance')->all());
        $this->assertSame(['public', 'local', null], $pubs->map(fn($x) => $x['evidencia'][0] ?? null)->all());
        $this->assertSame(['medios' => 3, 'publicaciones' => 3, 'alcance' => 2226, 'visualizaciones' => 354, 'interacciones' => 0], app(ProductosService::class)->totales($pubs));
        $this->actingAs($this->admin)->get(route('campaigns.productos.ver', [$this->c, $p]))->assertOk()->assertSee('De Pindo')->assertSee('2.226');

        // Vincular una publicación de editus sin pieza y desvincularla
        $suelta = $this->publicacion($p, $this->canal4, ['producto_id' => null, 'message' => 'Suelta']);
        $this->actingAs($this->admin)->post(route('campaigns.productos.vincular', [$this->c, $p]), ['posts' => [$suelta->id]])->assertRedirect();
        $this->assertSame([$p->id, 'Video Largo'], [(int) $suelta->fresh()->producto_id, $suelta->fresh()->tipo_pieza]);
        $this->actingAs($this->admin)->post(route('campaigns.productos.vincular', [$this->c, $p]), ['quitar' => $suelta->id])->assertRedirect();
        $this->assertNull($suelta->fresh()->producto_id);

        // Borrar la registrada
        $reg = ProductoPublicacion::whereNull('meta_post_id')->first();
        $this->actingAs($this->admin)->delete(route('campaigns.productos.publicaciones.borrar', [$this->c, $p, $reg]))->assertRedirect();
        $this->assertSame(2, app(ProductosService::class)->publicaciones($p)->count());
    }

    private function armarInforme(): array
    {
        $vl = $this->pieza();
        $this->publicacion($vl, $this->canal4);
        $this->publicacion($vl, $this->clic, ['alcance' => 546, 'visualizaciones' => 92, 'fb_permalink_url' => 'https://web.facebook.com/share/v/1AScufF58r/']);
        $ban = $this->pieza(['tipo_id' => $this->banner->id, 'nombre' => 'Banner vacunación', 'fecha_publicacion' => '2025-12-01', 'fecha_fin' => '2025-12-30', 'acta_aprobacion' => '095', 'acta_publicacion' => '095']);
        foreach (['Neiva al Día' => 'https://neivaaldia.com/', 'Neiva 24' => 'https://neiva24.com/', 'La Surco' => 'https://lasurco.co/'] as $m => $u) {
            $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $ban]), ['medio' => $m, 'url' => $u, 'evidencia' => UploadedFile::fake()->image('w.png', 900, 500)]);
        }
        $ev = $this->pieza(['tipo_id' => $this->envivo->id, 'nombre' => 'En vivo IRA y EDA', 'fecha_publicacion' => '2025-12-13', 'acta_aprobacion' => '', 'acta_publicacion' => '',
            'descripcion' => 'Transmisión de un (1) en vivo realizado en la Secretaría de Salud Departamental del Huila con la Enfermera Diana Cortes.']);
        $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $ev]), ['medio' => 'Opanoticias', 'url' => 'https://www.facebook.com/share/v/1SFW82y7K9/', 'alcance' => '599', 'visualizaciones' => '624', 'interacciones' => '7']);
        // Pieza sin publicaciones: no sale
        $this->pieza(['nombre' => 'Sin publicar']);
        return [$vl, $ban, $ev];
    }

    public function test_datos_del_informe_por_programa_tipo_y_pieza(): void
    {
        $this->armarInforme();
        $d = app(InformeMetricasService::class)->datos($this->c, ['corte' => '2026-01-06']);
        $this->assertCount(1, $d['capitulos']);
        $cap = $d['capitulos'][0];
        $this->assertSame('PROGRAMA 2. SALUD INFANTIL', $cap['titulo']);
        $this->assertSame(['VIDEOS LARGOS', 'BANNER 1.600 X 185 PX', 'EN VIVOS'], array_column($cap['secciones'], 'titulo'));

        [$vl] = $cap['secciones'][0]['piezas'];
        $this->assertCount(1, $cap['secciones'][0]['piezas'], 'la pieza sin publicaciones no sale');
        $this->assertSame('Video Largo No. 1:', $vl['etiqueta']);
        $this->assertSame('Publicación de un (1) video largo en dos (2) medios digitales.', $vl['descripcion']);
        $this->assertSame([
            ['PROGRAMA', '2. Salud infantil.'], ['SUBPROGRAMA', 'Vacunación.'], ['FECHA PUBLICACIÓN', '17 de Diciembre de 2025.'],
            ['FECHA MÉTRICAS', 'Del 17 de Diciembre de 2025 al 06 de Enero de 2026.'], ['ACTA DE APROBACIÓN', 'No. 103.'], ['ACTA DE PUBLICACIÓN', 'No. 103.'],
        ], $vl['datos']);
        $this->assertNotNull($vl['muestra']);

        $ban = $cap['secciones'][1]['piezas'][0];
        $this->assertSame('Banner No. 1:', $ban['etiqueta']);
        $this->assertSame('Publicación de un (1) banner de 1.600x185 px en tres (3) páginas web.', $ban['descripcion']);
        $this->assertContains(['FECHA', '01 al 30 de Diciembre de 2025.'], $ban['datos']);

        $ev = $cap['secciones'][2]['piezas'][0];
        $this->assertStringStartsWith('Transmisión de un (1) en vivo realizado', $ev['descripcion']);
        $this->assertSame(['FECHA EMISIÓN', '13 de Diciembre de 2025.'], $ev['datos'][2]);
        $this->assertNull($ev['muestra'], 'en los en vivo no va muestra');

        $this->assertSame(['titulo' => 'VIDEOS LARGOS', 'piezas' => 1, 'publicaciones' => 2, 'alcance' => 1230, 'visualizaciones' => 203, 'interacciones' => 0], $cap['resumen'][0]);

        // Filtros: solo banners / otro programa / fechas
        $this->assertSame(['BANNER 1.600 X 185 PX'], array_column(app(InformeMetricasService::class)->datos($this->c, ['tipo_ids' => [$this->banner->id]])['capitulos'][0]['secciones'], 'titulo'));

        // Capítulos por subcampaña
        $porSub = app(InformeMetricasService::class)->datos($this->c, ['agrupar' => 'subcampana', 'subcampana_ids' => [$this->vacunacion->id], 'corte' => '2026-01-06']);
        $this->assertSame(['SUBCAMPAÑA VACUNACIÓN'], array_column($porSub['capitulos'], 'titulo'));
        $this->assertSame(3, count($porSub['capitulos'][0]['secciones']));
        $this->assertSame([], app(InformeMetricasService::class)->datos($this->c, ['programa_id' => Programa::where('codigo', '9')->value('id')])['capitulos']);
        $this->assertSame(['BANNER 1.600 X 185 PX', 'EN VIVOS'], array_column(app(InformeMetricasService::class)->datos($this->c, ['hasta' => '2025-12-15'])['capitulos'][0]['secciones'], 'titulo'));
    }

    public function test_descarga_word_pdf_vista_y_excel(): void
    {
        $this->armarInforme();
        $this->actingAs($this->admin)->post(route('campaigns.informes.membrete', $this->c), [
            'encabezado' => UploadedFile::fake()->image('membrete.png', 1600, 200), 'pie_texto' => 'Calle 14 No. 1F-02 Neiva (H)', 'titulo' => 'CAPÍTULO II. PROGRAMA 2',
        ])->assertRedirect();
        $q = ['corte' => '2026-01-06'];

        // Word
        $r = $this->actingAs($this->admin)->get(route('campaigns.informes.generar', [$this->c, 'word'] + $q))->assertOk();
        $archivo = $r->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archivo) === true);
        $xml = $zip->getFromName('word/document.xml');
        foreach (['CAPÍTULO II. PROGRAMA 2', 'VIDEOS LARGOS', 'Video Largo No. 1:', 'Publicación de un (1) video largo en dos (2) medios digitales.', 'FECHA MÉTRICAS: ',
            'Del 17 de Diciembre de 2025 al 06 de Enero de 2026.', 'ALCANCE', 'VISUALIZACIÓN', 'https://web.facebook.com/share/v/1AScufF58r/', 'PÁGINAS WEB:', 'Neiva al Día: ',
            'FECHA EMISIÓN: ', 'RESUMEN DE MÉTRICAS', '1,230'] as $t) {
            $this->assertStringContainsString(htmlspecialchars($t, ENT_XML1), $xml, "Word: falta «{$t}»");
        }
        $medios = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) if (str_starts_with($zip->getNameIndex($i), 'word/media/')) $medios++;
        $this->assertSame(6, $medios, 'membrete + 2 muestras (el en vivo no lleva) + 3 capturas de banners');
        $this->assertStringContainsString('Calle 14 No. 1F-02 Neiva (H)', implode('', array_map(fn($n) => (string) $zip->getFromName($n), array_filter(array_map(fn($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)), fn($n) => str_starts_with($n, 'word/footer')))));
        $zip->close();

        // PDF y vista previa
        $pdf = $this->actingAs($this->admin)->get(route('campaigns.informes.generar', [$this->c, 'pdf'] + $q))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->actingAs($this->admin)->get(route('campaigns.informes.generar', [$this->c, 'vista'] + $q))->assertOk()
            ->assertSee('Video Largo No. 1:')->assertSee('Del 17 de Diciembre de 2025 al 06 de Enero de 2026.')->assertSee('Neiva al Día');

        // Excel del control de productos
        $x = $this->actingAs($this->admin)->get(route('campaigns.informes.excel', $this->c))->assertOk();
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($x->baseResponse->getFile()->getPathname());
        $this->assertSame(['Resumen', 'Métricas', 'Videos largos', 'Banner 1600x185', 'En vivos'], $ss->getSheetNames());
        $res = $ss->getSheetByName('Resumen');
        $this->assertSame('Campaña 2: Salud infantil', $res->getCell('A5')->getValue());
        $this->assertSame([2, 1, 1, 0, 4], [$res->getCell('B5')->getValue(), $res->getCell('C5')->getValue(), $res->getCell('D5')->getValue(), $res->getCell('E5')->getValue(), $res->getCell('F5')->getValue()]);
        $vl = $ss->getSheetByName('Videos largos');
        $this->assertSame('Nombre de la pieza', $vl->getCell('H4')->getValue());
        $this->assertSame('P2 Salud Infantil Sp2 Vacunación', $vl->getCell('H5')->getValue());
        $this->assertGreaterThanOrEqual(1, count($vl->getDrawingCollection()), 'la miniatura de la muestra');
        $this->assertSame(1230, (int) $vl->getCell('R5')->getValue());
        $this->assertSame(6, $ss->getSheetByName('Métricas')->getHighestDataRow() - 1, '2 + 3 + 1 publicaciones');
    }

    public function test_importar_el_excel_del_control_de_productos(): void
    {
        // Excel como el de la Secretaría: título, encabezados en la fila 4, una hoja por tipo y la miniatura en la fila
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $ss->getActiveSheet()->setTitle('Videos largos ');
        $ws->setCellValue('A1', 'CONTROL DE PRODUCTOS');
        $ws->fromArray(['#', 'Programa', 'Categoría (carpeta)', 'Tipo', 'Formato / medida', 'Nombre de la pieza', 'Responsable', 'Fecha solicitud', 'Fecha entrega', 'Estado', 'Link a la carpeta / archivo', 'Observaciones'], null, 'A4');
        $ws->fromArray(['=IF(C5="","",ROW()-4)', 'CAMPAÑA 8-2: Movilidad segura', 'VIDEOS LARGOS', 'VIDEO', null, 'P8-MOVILIDAD SEGURIDA.mp4', null, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-12-30')), null, 'Aprobado', 'https://drive.google.com/file/d/1/view', null], null, 'A5');
        $ws->fromArray([2, "Campaña 2-2 :\n Salud nutricional", 'VIDEOS LARGOS', 'VIDEO', null, 'P2 Salud nutricional', 'Laura', null, null, 'en revisión', null, 'Revisar audio'], null, 'A6');
        $ws->fromArray([3, 'CAMAPAÑA 5-1: Enfermedades no transmisibles', 'VIDEOS LARGOS', 'VIDEO', null, '', null, null, null, null, null, null], null, 'A7'); // sin nombre: se salta
        $png = tempnam(sys_get_temp_dir(), 'png') . '.png';
        file_put_contents($png, UploadedFile::fake()->image('m.png', 200, 120)->get());
        $dr = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $dr->setPath($png)->setCoordinates('E5')->setWorksheet($ws);
        $ws2 = $ss->createSheet()->setTitle('Cuñas');
        $ws2->fromArray(['#', 'Programa', 'Categoría (carpeta)', 'Tipo', 'Formato / medida', 'Nombre de la pieza', 'Responsable', 'Fecha solicitud', 'Fecha entrega', 'Estado', 'Link a la carpeta / archivo', 'Observaciones', 'Subcampaña'], null, 'A4');
        $ws2->fromArray([1, 'Campaña 9: Prevención de lesiones por pólvora', 'CUÑAS', 'AUDIO', null, 'P9-PREV LESIONES POLVORA (01 - Pólvora).mp3', null, null, null, 'Aprobado', null, null, 'Pólvora'], null, 'A5');
        $ss->createSheet()->setTitle('Resumen')->setCellValue('A1', 'RESUMEN POR CATEGORÍA');
        $ruta = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($ruta);

        $subir = fn() => $this->actingAs($this->admin)->post(route('campaigns.productos.importar', $this->c), ['archivo' => new UploadedFile($ruta, 'control.xlsx', null, null, true)]);
        $subir()->assertRedirect(route('campaigns.productos', $this->c))->assertSessionHas('success');
        $largos = Producto::where('tipo_id', $this->largos->id)->orderBy('numero')->get();
        $this->assertSame(['P8-MOVILIDAD SEGURIDA.mp4', 'P2 Salud nutricional'], $largos->pluck('nombre')->all(), 'la hoja «Videos largos » va al tipo existente');
        $this->assertSame(['8-2', 'Movilidad segura'], [$largos[0]->programa->codigo, $largos[0]->programa->nombre]);
        $this->assertSame(['2-2', 'Salud nutricional', 'En revisión', 'Laura', 'Revisar audio'], [$largos[1]->programa->codigo, $largos[1]->programa->nombre, $largos[1]->estado, $largos[1]->responsable, $largos[1]->observaciones]);
        $this->assertSame(['2026-12-30', 'https://drive.google.com/file/d/1/view'], [$largos[0]->fecha_solicitud->toDateString(), $largos[0]->link_archivo]);
        $this->assertNotNull($largos[0]->muestra_path, 'la miniatura queda como muestra');
        $cunas = TipoPublicacion::where('nombre', 'Cuñas')->first();
        $this->assertSame(['Cuña', 'audio'], [$cunas->etiqueta, $cunas->clase]);
        $this->assertSame(1, Producto::where('tipo_id', $cunas->id)->count());
        $c9 = Producto::where('tipo_id', $cunas->id)->first();
        $this->assertSame(['9', 'Pólvora'], [$c9->programa->codigo, $c9->subcampana?->nombre], 'la columna «Subcampaña» crea la subcampaña');
        $this->assertTrue($c9->subcampana->tipos->contains('id', $cunas->id), 'y le asigna el tipo');

        // Importar otra vez no repite
        $subir()->assertRedirect();
        $this->assertSame(3, Producto::count());
    }

    public function test_textos_en_letras_y_lectura_de_programas(): void
    {
        $s = app(ProductosService::class);
        $this->assertSame(['un', 'quince', 'dieciséis', 'veintiún', 'treinta y un', 'cien', 'ciento un', 'doscientas una', 'mil quinientos'],
            [$s->enLetras(1), $s->enLetras(15), $s->enLetras(16), $s->enLetras(21), $s->enLetras(31), $s->enLetras(100), $s->enLetras(101), $s->enLetras(201, true), $s->enLetras(1500)]);
        $this->assertSame('quince (15) medios digitales', $s->cantidad(15, 'medio digital', 'medios digitales'));
        $this->assertSame('una (1) página web', $s->cantidad(1, 'página web', 'páginas web', true));
        $this->assertSame('Del 29 de Diciembre de 2025 al 06 de Enero de 2026', $s->rango(\Carbon\Carbon::parse('2025-12-29'), \Carbon\Carbon::parse('2026-01-06')));
        $inf = app(InformeMetricasService::class);
        $this->assertSame(['2-1', 'Salud infantil y adolescencia'], $inf->leerPrograma('CAMPAÑA 2-1: Salud infantil y adolescencia'));
        $this->assertSame(['5-1', 'Enfermedades no transmisibles'], $inf->leerPrograma('CAMAPAÑA 5-1: Enfermedades no transmisibles'));
        $this->assertSame(['2-2', 'Salud nutricional'], $inf->leerPrograma("Campaña 2-2 :\n Salud nutricional"));
        $this->assertSame(['', 'Otro texto'], $inf->leerPrograma('Otro texto'));
        $this->assertSame(['Video Largo', 'Cuña', 'En Vivo', 'Banner 600x600', 'Red Social', 'Podcast'],
            array_map(fn($n) => $inf->singular($n), ['Videos largos', 'Cuñas', 'En vivos', 'Banner 600x600', 'Redes sociales', 'Podcast']));
    }

    public function test_la_migracion_convierte_las_subcampanas_que_eran_tipos_de_publicacion(): void
    {
        $this->crearEsquemaPlanMedios(true);
        (require database_path('migrations/2026_10_29_000001_tipos_de_publicacion.php'))->down(); // estado antes de esta migración
        $admin = User::factory()->create();
        $c = Campaign::create(['name' => 'Salud 2027', 'slug' => 'salud-2027', 'is_active' => true, 'plan_medios' => true]);
        $pg = MetaPage::create(['page_id' => '900', 'name' => 'Pág']);
        $dengue = Subcampana::create(['campaign_id' => $c->id, 'nombre' => 'Dengue', 'subprograma' => 'Dengue', 'is_active' => true]);
        $vl = Subcampana::create(['campaign_id' => $c->id, 'nombre' => 'Videos largos', 'etiqueta' => 'Video Largo', 'clase' => 'redes', 'unidad' => 'video largo', 'is_active' => true, 'orden' => 1]);
        $cunas = Subcampana::create(['campaign_id' => $c->id, 'nombre' => 'Cuñas', 'is_active' => true, 'orden' => 2]);
        \Illuminate\Support\Facades\DB::table('campaign_productos')->insert(['campaign_id' => $c->id, 'subcampana_id' => $vl->id, 'numero' => 1, 'nombre' => 'Pieza', 'estado' => 'Aprobado', 'created_at' => now(), 'updated_at' => now()]);
        $post = MetaPost::create(['meta_page_id' => $pg->id, 'user_id' => $admin->id, 'campaign_id' => $c->id, 'type' => 'video', 'status' => 'success', 'subcampana_id' => $vl->id, 'tipo_pieza' => 'Video Largo']);
        $postDengue = MetaPost::create(['meta_page_id' => $pg->id, 'user_id' => $admin->id, 'campaign_id' => $c->id, 'type' => 'video', 'status' => 'success', 'subcampana_id' => $dengue->id]);

        $m = require database_path('migrations/2026_10_29_000001_tipos_de_publicacion.php');
        $m->up();
        $m->up(); // idempotente

        $this->assertSame(['Dengue'], Subcampana::where('campaign_id', $c->id)->pluck('nombre')->all(), 'los temas siguen siendo subcampañas');
        $this->assertSame([['Videos largos', 'Video Largo', 'video largo'], ['Cuñas', null, null]], TipoPublicacion::where('campaign_id', $c->id)->orderBy('orden')->get()->map(fn($t) => [$t->nombre, $t->etiqueta, $t->unidad])->all());
        $pieza = Producto::first();
        $this->assertSame([TipoPublicacion::where('nombre', 'Videos largos')->value('id'), null], [(int) $pieza->tipo_id, $pieza->subcampana_id]);
        $this->assertSame([null, 'Video Largo'], [$post->fresh()->subcampana_id, $post->fresh()->tipo_pieza]);
        $this->assertSame($dengue->id, (int) $postDengue->fresh()->subcampana_id);
    }
}
