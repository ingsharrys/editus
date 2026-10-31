<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\EmisionRadio;
use App\Models\MetaPage;
use App\Models\Role;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\EmisionesRadioService;
use App\Services\FichaTecnicaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Plan de medios por canal: importar la ficha técnica y radio reportado y firmado por las emisoras. */
class PlanPorCanalesTest extends TestCase
{
    use Concerns\EsquemaPlanMedios;

    private User $admin;
    private Campaign $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias'], 'services.facebook.version' => 'v23.0']);
        $this->crearEsquemaPlanMedios();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias']], $this->admin->id);
        $this->c->forceFill(['plan_medios' => true])->save();
    }

    /** Ficha pequeña con la misma forma que la del cliente (incluye el «6-2» repetido). */
    private function ficha(): string
    {
        $x = new Spreadsheet();
        $h = $x->getActiveSheet()->setTitle('Ficha técnica 1 - cantidades');
        $h->fromArray([
            ['TIPO DE PRODUCTO', 'PRODUCTO', 'FORMATO', 'CARACTERÍSTICA', 'VALOR UNITARIO', '', '', "Campaña 1:\nPoblaciones vulnerables+H1:Q2", '', 'Campaña 6-2: Zoonosis', 'Campaña 6-2: Zoonosis'],
            ['', '', '', '', '', '', '', 'Discapacidad', 'Envejecimiento y vejez', '', ''],
            ['RADIO', 'Comercial', 'Cuña', 'Cuña de 30 segundos en programa general', '$ 25,000', '', '', '71', '40', '10', ''],
            ['', 'Comercial', 'Entrevista', 'Entrevista de 3 min', '$ 90,000', '', '', '2', '', '', '1'],
            ['MEDIOS DIGITALES', 'Banner', 'Banner largo', 'Banner en portada', '$ 50,000', '', '', '4', '3', '', ''],
            ['REDES SOCIALES FACEBOOK', 'Post / Flyer', 'Imagen', 'Campaña de alcance de 100.000 personas', '$ 300,000', '', '', '3', '2', '', ''],
            ['REDES SOCIALES TIK TOK', 'Video corto', 'Reel', 'Campaña de alcance de 50.000 personas', '$ 400,000', '', '', '1', '', '', ''],
            ['Total', '', '', '', '', '', '', '', '', '', ''],
        ]);
        $h->mergeCells('H1:I1');
        $ruta = tempnam(sys_get_temp_dir(), 'ficha') . '.xlsx';
        (new Xlsx($x))->save($ruta);
        return $ruta;
    }

    public function test_importa_la_ficha_tecnica_por_canal_y_es_idempotente(): void
    {
        $ruta = $this->ficha();
        $this->actingAs($this->admin)->post(route('campaigns.plan.ficha', $this->c), [
            'archivo' => new UploadedFile($ruta, 'plan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertRedirect(route('campaigns.plan.subcampanas', $this->c))->assertSessionHas('success', fn($m) => str_contains($m, 'quedó como Campaña 6-3') && str_contains($m, '1 de ellas con sus propias subcampañas'));

        $tipos = TipoPublicacion::where('campaign_id', $this->c->id)->get()->keyBy('nombre');
        $this->assertSame(['Radio comercial · Cuña programa general', 'Radio comercial · Entrevista 3 min', 'Digital · Banner largo', 'Facebook · Post / Flyer', 'TikTok · Video corto (Reel)'], $tipos->keys()->all());
        $fb = $tipos['Facebook · Post / Flyer'];
        $this->assertSame(['facebook', 100000, 300000.0], [$fb->canal, (int) $fb->meta_alcance, (float) $fb->valor_unitario]);
        $this->assertSame('pauta', $fb->grupo());
        $this->assertTrue($fb->sePublicaEnEditus());
        $this->assertFalse($tipos['TikTok · Video corto (Reel)']->sePublicaEnEditus());
        $this->assertTrue($tipos['TikTok · Video corto (Reel)']->conPauta());
        $this->assertSame('radio', $tipos['Radio comercial · Cuña programa general']->grupo());
        $this->assertSame('digital', $tipos['Digital · Banner largo']->grupo());

        $disc = Subcampana::where('nombre', 'Discapacidad')->with('tipos')->firstOrFail();
        $this->assertStringContainsString('Poblaciones vulnerables', $disc->programa);
        $this->assertSame([71, 2, 4, 3, 1], $disc->tipos->sortBy('orden')->pluck('pivot.cantidad')->map(fn($n) => (int) $n)->values()->all());
        $this->assertSame(4, Subcampana::where('campaign_id', $this->c->id)->count(), 'Poblaciones vulnerables (con Discapacidad y Envejecimiento) y Zoonosis');
        $pv = Subcampana::where('nombre', 'Poblaciones vulnerables')->firstOrFail();
        $this->assertNull($pv->parent_id);
        $this->assertSame([$pv->id, $pv->id], Subcampana::whereIn('nombre', ['Discapacidad', 'Envejecimiento y vejez'])->pluck('parent_id')->map(fn($v) => (int) $v)->all());
        $this->assertSame(0, $pv->tipos()->count(), 'las cantidades van en sus subcampañas');
        $this->assertTrue($pv->esGrupo());
        $this->assertSame('Poblaciones vulnerables › Discapacidad', $disc->nombreCompleto());
        $this->assertSame(['Poblaciones vulnerables', 'Discapacidad', 'Envejecimiento y vejez', 'Zoonosis'], Subcampana::arbol(Subcampana::orderBy('orden')->get())->pluck('nombre')->all());
        $zoo = Subcampana::where('nombre', 'Zoonosis')->firstOrFail();
        $this->assertStringContainsString('6-3', $zoo->programa);
        $this->assertSame(11, (int) $zoo->tipos()->sum('cantidad'), 'sin subcampañas: la misma lleva las cantidades (10 cuñas + 1 entrevista)');

        // Volver a importar no duplica
        app(FichaTecnicaService::class)->importar($this->c, $ruta);
        $this->assertSame(5, TipoPublicacion::where('campaign_id', $this->c->id)->count());
        $this->assertSame(4, Subcampana::where('campaign_id', $this->c->id)->count());

        $this->actingAs($this->admin)->get(route('campaigns.plan.tipos', $this->c))->assertOk()->assertSee('Importar la ficha técnica (Excel)')->assertSee('Cuña de 30 segundos en programa general');
        @unlink($ruta);
    }

    public function test_la_emisora_reporta_y_firma_sus_cunas_y_cuentan_en_el_plan(): void
    {
        $ruta = $this->ficha();
        app(FichaTecnicaService::class)->importar($this->c, $ruta);
        @unlink($ruta);
        $disc = Subcampana::where('nombre', 'Discapacidad')->firstOrFail();
        $cuna = TipoPublicacion::where('nombre', 'Radio comercial · Cuña programa general')->firstOrFail();
        $banner = TipoPublicacion::where('nombre', 'Digital · Banner largo')->firstOrFail();

        $emisora = User::factory()->create(['name' => 'La Voz del Huila', 'role_id' => null]);
        Storage::disk('local')->put('certificados/firma.png', 'x');
        $p = CampaignParticipante::create([
            'campaign_id' => $this->c->id, 'user_id' => $emisora->id, 'medio_nombre' => 'La Voz del Huila', 'representante' => 'Ana Pérez',
            'firma_path' => 'certificados/firma.png', 'firma_hash' => 'abc', 'firmado_at' => now(), 'acepto_terminos_at' => now(),
        ]);

        $this->actingAs($emisora)->get(route('certificados.ver', $p))->assertOk()
            ->assertSee('Cuñas y espacios de radio emitidos')->assertSee('71 planeadas')->assertDontSee('Banner largo (');

        $base = ['subcampana_id' => $disc->id, 'tipo_id' => $cuna->id, 'cantidad' => 30, 'fecha_desde' => '2026-03-01', 'fecha_hasta' => '2026-03-15', 'programa' => 'Noticiero', 'acepto' => '1'];
        // Sin aceptar no firma; un tipo que no es de radio no se acepta
        $this->actingAs($emisora)->post(route('emisiones.guardar', $p), ['acepto' => null] + $base)->assertSessionHasErrors('acepto');
        $this->actingAs($emisora)->post(route('emisiones.guardar', $p), ['tipo_id' => $banner->id] + $base)->assertSessionHasErrors('emision');

        // Usa la firma registrada y adjunta el soporte
        $this->actingAs($emisora)->post(route('emisiones.guardar', $p), $base + ['soporte' => UploadedFile::fake()->create('certificacion.pdf', 50, 'application/pdf')])
            ->assertRedirect(route('certificados.ver', $p) . '#radio');
        $this->actingAs($emisora)->post(route('emisiones.guardar', $p), ['cantidad' => 12, 'fecha_desde' => '2026-03-16', 'fecha_hasta' => '2026-03-31'] + $base)->assertRedirect();
        $e = EmisionRadio::first();
        $this->assertSame('certificados/firma.png', $e->firma_path);
        $this->assertNotNull($e->firmado_at);
        Storage::disk('local')->assertExists($e->soporte_path);

        $this->assertSame([$disc->id => [$cuna->id => 42]], app(EmisionesRadioService::class)->totales($this->c));
        $filas = app(EmisionesRadioService::class)->paraCertificado($p);
        $this->assertSame(42, $filas[0]['cantidad']);
        $this->assertSame('01/03/2026 – 31/03/2026', $filas[0]['fechas']);
        $this->actingAs($emisora)->get(route('certificados.ver', $p))->assertOk()->assertSee('Discapacidad')->assertSee('01/03/2026 – 31/03/2026');

        // Otro usuario no reporta ni ve el soporte; el administrador ve el soporte pero no reporta en nombre de la emisora
        $otro = User::factory()->create(['role_id' => null]);
        $this->actingAs($otro)->post(route('emisiones.guardar', $p), $base)->assertForbidden();
        $this->actingAs($otro)->get(route('emisiones.soporte', $e))->assertForbidden();
        $this->actingAs($this->admin)->get(route('emisiones.soporte', $e))->assertOk();
        $this->actingAs($this->admin)->post(route('emisiones.guardar', $p), $base)->assertForbidden();

        // Borrar un reporte no borra la firma de la inscripción
        $this->actingAs($emisora)->delete(route('emisiones.borrar', $e))->assertRedirect();
        Storage::disk('local')->assertExists('certificados/firma.png');
        $this->assertSame([$disc->id => [$cuna->id => 12]], app(EmisionesRadioService::class)->totales($this->c));
    }

    public function test_pauta_por_pieza_tiktok_manual_y_avance_por_canal(): void
    {
        $ruta = $this->ficha();
        app(FichaTecnicaService::class)->importar($this->c, $ruta);
        @unlink($ruta);
        $disc = Subcampana::where('nombre', 'Discapacidad')->firstOrFail();
        $t = fn($n) => TipoPublicacion::where('nombre', $n)->firstOrFail();
        $fb = $t('Facebook · Post / Flyer'); $tk = $t('TikTok · Video corto (Reel)'); $cuna = $t('Radio comercial · Cuña programa general');

        // Al publicar desde editus no salen radio ni TikTok
        $plan = app(\App\Services\PlanMediosService::class);
        $this->assertSame(['Digital · Banner largo', 'Facebook · Post / Flyer'], $plan->tiposDe($this->c, $disc));
        $this->assertNotContains('TikTok · Video corto (Reel)', $plan->publicables($this->c));

        // Pieza de Facebook con pauta: asignado y gastado al final; su alcance frente a la meta (100.000)
        $this->actingAs($this->admin)->post(route('campaigns.productos.guardar', $this->c), [
            'tipo_id' => $fb->id, 'subcampana_id' => $disc->id, 'nombre' => 'Flyer inclusión', 'estado' => 'Publicado / Entregado',
            'pauta_asignada' => '300000', 'pauta_gastada' => '280000', 'pauta_desde' => '2026-03-01', 'pauta_hasta' => '2026-03-10',
        ])->assertRedirect();
        $pieza = \App\Models\Producto::where('nombre', 'Flyer inclusión')->firstOrFail();
        $this->assertSame([300000.0, 280000.0, '2026-03-10'], [$pieza->pauta_asignada, $pieza->pauta_gastada, $pieza->pauta_hasta->toDateString()]);
        $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $pieza]), ['medio' => 'Opa Noticias FB', 'alcance' => 120000])->assertRedirect();
        $this->actingAs($this->admin)->get(route('campaigns.productos.ver', [$this->c, $pieza]))->assertOk()
            ->assertSee('Pauta paga de esta pieza')->assertSee('Meta de alcance')->assertSee('120%');

        // TikTok: se registra a mano (enlace, alcance y pantallazo); sin medio queda «TikTok»
        $this->actingAs($this->admin)->get(route('campaigns.productos.crear', [$this->c, 'subcampana' => $disc->id, 'tipo' => $tk->id]))->assertOk();
        $this->actingAs($this->admin)->post(route('campaigns.productos.guardar', $this->c), [
            'tipo_id' => $tk->id, 'subcampana_id' => $disc->id, 'nombre' => 'Reel inclusión', 'estado' => 'Publicado / Entregado', 'pauta_asignada' => '400000',
        ])->assertRedirect();
        $reel = \App\Models\Producto::where('nombre', 'Reel inclusión')->firstOrFail();
        $this->actingAs($this->admin)->post(route('campaigns.productos.publicaciones.guardar', [$this->c, $reel]), [
            'url' => 'https://www.tiktok.com/@opa/video/1', 'alcance' => 30000, 'visualizaciones' => 45000, 'interacciones' => 900,
            'evidencia' => UploadedFile::fake()->image('tiktok.png', 400, 800),
        ])->assertRedirect();
        $this->assertSame('TikTok', $reel->publicaciones()->value('medio'));
        $this->actingAs($this->admin)->get(route('campaigns.productos.ver', [$this->c, $reel]))->assertSee('Registrar publicación en TikTok');

        // Radio reportado por una emisora
        $emisora = User::factory()->create(['role_id' => null]);
        $p = CampaignParticipante::create(['campaign_id' => $this->c->id, 'user_id' => $emisora->id, 'medio_nombre' => 'Radio Sur', 'firma_path' => 'f.png', 'firma_hash' => 'h', 'firmado_at' => now()]);
        app(EmisionesRadioService::class)->guardar($p->load('campaign'), ['subcampana_id' => $disc->id, 'tipo_id' => $cuna->id, 'cantidad' => 20, 'fecha_desde' => '2026-03-02'], null, null, null);

        $prod = app(\App\Services\ProductosService::class);
        $this->assertSame(20, $prod->matrizSubcampanas($this->c)[$disc->id][$cuna->id]['piezas']);
        $avance = collect($prod->avanceSubcampanas($this->c)->firstWhere('sub.id', $disc->id)['tipos'])->firstWhere('tipo.id', $cuna->id);
        $this->assertSame([71, 20], [(int) $avance['planeadas'], $avance['publicadas']]);

        $canal = $prod->porCanal($this->c, $disc->id);
        $this->assertSame(['radio', 'digital', 'pauta'], array_keys($canal));
        $this->assertSame([73, 20], [$canal['radio']['planeadas'], $canal['radio']['hechas']]);
        $this->assertSame(71 * 25000 + 2 * 90000, (int) $canal['radio']['valor_contratado']);
        $this->assertSame([4, 2], [$canal['pauta']['planeadas'], $canal['pauta']['hechas']]);
        $this->assertSame([700000, 280000, 150000], [(int) $canal['pauta']['pauta_asignada'], (int) $canal['pauta']['pauta_gastada'], $canal['pauta']['alcance']]);
        $this->assertSame([1, 2], [$canal['pauta']['cumplen'], $canal['pauta']['con_meta']], 'el flyer cumple 100.000; el reel no llega a 50.000');

        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $disc]))->assertOk()
            ->assertSee('Redes sociales (pauta paga)')->assertSee('Medios digitales')->assertSee('Radio Sur')
            ->assertSee('Registrar TikTok · Video corto (Reel)')->assertSee('$ 700.000');
        $this->actingAs($this->admin)->get(route('campaigns.plan', $this->c))->assertOk()->assertSee('Avance por canal')->assertDontSee('Registrar TikTok');

        // La subcampaña principal suma a sus subcampañas y no se publica directamente en ella
        $pv = Subcampana::where('nombre', 'Poblaciones vulnerables')->firstOrFail();
        $this->assertSame(['Poblaciones vulnerables › Discapacidad', 'Poblaciones vulnerables › Envejecimiento y vejez', 'Zoonosis'],
            $plan->subcampanasParaPublicar($this->c)->map->nombreCompleto()->all());
        $grupo = $prod->porCanal($this->c, $pv->id);
        $this->assertSame(73 + 40, $grupo['radio']['planeadas'], 'Discapacidad + Envejecimiento');
        $this->assertSame(20, $grupo['radio']['hechas']);
        $this->assertSame(700000, (int) $grupo['pauta']['pauta_asignada']);
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $pv]))->assertOk()
            ->assertSee('Subcampañas de Poblaciones vulnerables')->assertSee('Envejecimiento y vejez')->assertSee('Flyer inclusión')->assertSee('Radio Sur')
            ->assertDontSee('Registrar TikTok');
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.publicar', [$this->c, $pv]))->assertRedirect(route('campaigns.subcampanas.ver', [$this->c, $pv]));
        $this->actingAs($this->admin)->get(route('campaigns.subcampanas.ver', [$this->c, $disc]))->assertSee('← Poblaciones vulnerables', false);
        $this->actingAs($this->admin)->get(route('campaigns.plan', $this->c))->assertSee('↳ Discapacidad');
        $this->actingAs($this->admin)->get(route('campaigns.plan', [$this->c, 'sub' => $pv->id]))->assertOk();
        $this->actingAs($this->admin)->get(route('campaigns.plan.subcampanas', $this->c))->assertOk()->assertSee('Agrupa 2 subcampañas')->assertSee('Subcampaña de Poblaciones vulnerables');

        // Mover una subcampaña dentro de otra desde el formulario
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['id' => $zoo = Subcampana::where('nombre', 'Zoonosis')->value('id'), 'nombre' => 'Zoonosis', 'is_active' => '1', 'parent_id' => $pv->id],
        ]])->assertRedirect();
        $this->assertSame($pv->id, (int) Subcampana::find($zoo)->parent_id);
        // Una principal con hijas no puede quedar dentro de otra
        $this->actingAs($this->admin)->post(route('campaigns.plan.subcampanas.guardar', $this->c), ['subs' => [
            ['id' => $pv->id, 'nombre' => 'Poblaciones vulnerables', 'is_active' => '1', 'parent_id' => $zoo],
        ]])->assertRedirect();
        $this->assertNull(Subcampana::find($pv->id)->parent_id);
    }
}
