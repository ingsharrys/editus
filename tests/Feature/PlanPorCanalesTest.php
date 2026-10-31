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
            ['TIPO DE PRODUCTO', 'PRODUCTO', 'FORMATO', 'CARACTERÍSTICA', 'VALOR UNITARIO', '', '', 'Campaña 1: Poblaciones vulnerables', '', 'Campaña 6-2: Zoonosis', 'Campaña 6-2: Zoonosis'],
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
        ])->assertRedirect(route('campaigns.plan', $this->c))->assertSessionHas('success', fn($m) => str_contains($m, 'quedó como Campaña 6-3'));

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
        $this->assertSame(3, Subcampana::where('campaign_id', $this->c->id)->count(), 'Discapacidad, Envejecimiento y Zoonosis (las dos columnas de zoonosis van a la misma subcampaña)');
        $this->assertStringContainsString('6-3', Subcampana::where('nombre', 'Zoonosis')->value('programa'));

        // Volver a importar no duplica
        app(FichaTecnicaService::class)->importar($this->c, $ruta);
        $this->assertSame(5, TipoPublicacion::where('campaign_id', $this->c->id)->count());
        $this->assertSame(3, Subcampana::where('campaign_id', $this->c->id)->count());

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
}
