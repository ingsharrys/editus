<?php

namespace Tests\Feature;

use App\Mail\ContratoFirmado;
use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\Contrato;
use App\Models\ContratoTipo;
use App\Models\MedioCampana;
use App\Models\MetaPage;
use App\Models\Role;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\ContratosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Contratos de los medios: listado con tipo de contrato, elegir el medio, firmar con consecutivo, copia por correo y luego vincular. */
class ContratosMediosTest extends TestCase
{
    use Concerns\EsquemaPlanMedios;

    private User $admin;
    private Campaign $c;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias'], 'services.facebook.version' => 'v23.0', 'mail.default' => 'smtp']);
        $this->crearEsquemaPlanMedios();
        (require database_path('migrations/2026_11_05_000001_contratos_de_medios.php'))->up();
        Storage::fake('local');
        Mail::fake();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->c = app(CampanasService::class)->guardar(new Campaign(), ['name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias'], 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'], $this->admin->id);
        $this->actingAs($this->admin)->post(route('campaigns.update', $this->c), [
            'name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias'], 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'plan_medios' => '1', 'inscripcion_activa' => '1', 'cert_obligatorio' => '1',
        ])->assertRedirect();
        $this->c->refresh();
        auth()->logout();
    }

    private function firmaPng(): string
    {
        $im = imagecreatetruecolor(300, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imageline($im, 10, 80, 290, 20, imagecolorallocate($im, 10, 30, 90));
        ob_start();
        imagepng($im);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** El administrador arma el contrato, la numeración y el listado de medios. */
    private function prepararContratos(): ContratoTipo
    {
        $this->actingAs($this->admin)->post(route('campaigns.contratos.tipos.guardar', $this->c), [
            'nombre' => 'Contrato emisoras', 'clase_medio' => 'emisora',
            'cuerpo' => '<h2>Objeto</h2><p>Contrato No. {numero} entre {contratante} y {medio} ({clase_medio}), representado por {representante} con {documento_tipo} {documento}, correo {correo}.</p><script>alert(1)</script>',
        ])->assertRedirect();
        $this->actingAs($this->admin)->post(route('campaigns.contratos.config', $this->c), [
            'prefijo' => 'SALUD-2026-', 'inicio' => 7, 'digitos' => 3, 'contratante' => 'DIME MEDIA S.A.S.', 'contratante_nit' => '901.000.000-1',
            'contratante_representante' => 'Juan Charry', 'copia_a' => 'contratos@dimemedia.co, no-es-correo',
        ])->assertRedirect();
        $tipo = ContratoTipo::firstOrFail();
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.bloque', $this->c), [
            'lista' => "Canal 6 Alfasurt\nLa Voz de Pitalito; voz@pitalito.co\nCanal 6 Alfasurt", 'clase' => 'emisora', 'tipo_id' => $tipo->id,
        ])->assertSessionHas('success', 'Se agregaron 2 medios.');
        return $tipo;
    }

    private function inscribir(string $nombre): User
    {
        $u = User::factory()->create(['name' => $nombre, 'role_id' => null]);
        $this->actingAs($u)->withSession(['inscripcion_campana' => ['token' => $this->c->inscripcion_token, 'nombre' => 'Salud 2026']])
            ->get(route('profile.edit'))->assertRedirect(route('certificados.formulario', $this->c));
        return $u;
    }

    private function datosFirma(array $extra = []): array
    {
        return $extra + [
            'medio_nombre' => 'Canal 6 Alfasurt Pitalito', 'sitio' => 'Canal 6 · 96.3 FM', 'nit' => '800.123.456-7',
            'representante' => 'Alba Luz Erazo', 'documento_tipo' => 'C.C.', 'documento' => '36123456', 'correo' => 'alba@canal6.co',
            'telefono' => '3001234567', 'ciudad' => 'Pitalito (Huila)', 'acepto' => '1', 'firma' => $this->firmaPng(),
            'logo' => UploadedFile::fake()->image('logo.png', 300, 120), 'membrete' => UploadedFile::fake()->image('membrete.png', 1200, 200),
            'rut' => UploadedFile::fake()->create('rut.pdf', 120, 'application/pdf'), 'certificado_bancario' => UploadedFile::fake()->image('cert.jpg', 800, 1000),
        ];
    }

    public function test_el_medio_elige_su_medio_firma_el_contrato_y_luego_vincula(): void
    {
        $this->prepararContratos();
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertOk()
            ->assertSee('Medios y contratos')->assertSee('Canal 6 Alfasurt')->assertSee('SALUD-2026-007')->assertSee('Sin inscribir');
        $this->actingAs($this->admin)->get(route('campaigns.contratos.tipos.vista', [$this->c, ContratoTipo::first()->id]))->assertOk()
            ->assertSee('Medio de ejemplo')->assertSee('DIME MEDIA S.A.S.')->assertDontSee('<script>alert(1)</script>', false);

        $u = $this->inscribir('Alba Luz Erazo');
        // Primero el contrato: el certificado (vincular el medio) y cualquier pantalla lo llevan a firmarlo
        $this->actingAs($u)->get(route('certificados.formulario', $this->c))->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->get(route('profile.edit'))->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('¿Qué medio representas?')->assertSee('La Voz de Pitalito');

        $canal6 = MedioCampana::where('nombre', 'Canal 6 Alfasurt')->firstOrFail();
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $canal6->id])->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('Contrato emisoras')->assertSee('SALUD-2026-007')
            ->assertSee('data-var="representante"', false);

        // Faltan datos, documentos o la firma
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), ['medio_nombre' => 'X'])
            ->assertSessionHasErrors(['logo', 'rut', 'certificado_bancario', 'representante', 'documento', 'correo', 'acepto', 'firma']);

        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma())->assertRedirect(route('certificados.formulario', $this->c));
        $k = Contrato::firstOrFail();
        $this->assertSame([7, 'SALUD-2026-007', 'alba@canal6.co'], [$k->numero, $k->codigo, $k->dato('correo')]);
        $this->assertStringContainsString('Contrato No. SALUD-2026-007 entre DIME MEDIA S.A.S. y Canal 6 Alfasurt Pitalito (emisora), representado por Alba Luz Erazo con C.C. 36.123.456', $k->cuerpo);
        $this->assertStringNotContainsString('<script', $k->cuerpo);
        foreach (['logo_path', 'membrete_path', 'rut_path', 'certificado_bancario_path', 'firma_path', 'pdf_path'] as $campo) Storage::disk('local')->assertExists($k->$campo);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($k->pdf_path));
        Mail::assertSent(ContratoFirmado::class, fn($m) => $m->hasTo('alba@canal6.co') && $m->hasBcc('contratos@dimemedia.co') && count($m->attachments()) === 1);
        $this->assertNotNull($k->fresh()->enviado_at);

        // Ya puede vincular su medio: el certificado arranca con los datos del contrato
        $this->actingAs($u)->get(route('certificados.formulario', $this->c))->assertOk()->assertSee('Alba Luz Erazo');
        $this->assertSame('Canal 6 Alfasurt Pitalito', CampaignParticipante::where('user_id', $u->id)->value('medio_nombre'));
        $this->actingAs($u)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('Contrato No. SALUD-2026-007 firmado');
        $this->actingAs($u)->get(route('contratos.pdf', $k))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Otro medio: no puede tomar el que ya eligieron; el suyo lleva el número siguiente
        $u2 = $this->inscribir('Pedro Pitalito');
        $this->actingAs($u2)->post(route('contratos.elegir', $this->c), ['medio_id' => $canal6->id])->assertSessionHasErrors('medio_id');
        $voz = MedioCampana::where('nombre', 'La Voz de Pitalito')->firstOrFail();
        $this->actingAs($u2)->post(route('contratos.elegir', $this->c), ['medio_id' => $voz->id]);
        $this->actingAs($u2)->post(route('contratos.firmar', $this->c), $this->datosFirma(['medio_nombre' => 'La Voz de Pitalito', 'correo' => 'pedro@voz.co', 'representante' => 'Pedro Pitalito']));
        $this->assertSame('SALUD-2026-008', Contrato::where('user_id', $u2->id)->value('codigo'));
        // Cada uno ve solo su contrato y sus documentos; el administrador ve todo
        $this->actingAs($u2)->get(route('contratos.pdf', $k))->assertForbidden();
        $this->actingAs($u2)->get(route('contratos.archivo', [$k, 'rut']))->assertForbidden();
        $this->actingAs($this->admin)->get(route('contratos.archivo', [$k, 'rut']))->assertOk();
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertSee('Contrato SALUD-2026-007 firmado')->assertSee('Contrato SALUD-2026-008 firmado');

        // Anular: el medio vuelve a firmar y recibe un número nuevo (los números no se reutilizan)
        $this->actingAs($this->admin)->post(route('campaigns.contratos.anular', [$this->c, $k->id]))->assertSessionHas('success');
        $this->actingAs($u)->get(route('profile.edit'))->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma())->assertRedirect(route('certificados.formulario', $this->c));
        $this->assertSame('SALUD-2026-009', Contrato::where('user_id', $u->id)->whereNull('anulado_at')->value('codigo'));
    }

    public function test_el_medio_reporta_tiktok_y_banners_desde_su_portal(): void
    {
        $tipo = $this->prepararContratos();
        $tk = \App\Models\TipoPublicacion::create(['campaign_id' => $this->c->id, 'nombre' => 'TikTok · Video corto', 'etiqueta' => 'TikTok video', 'clase' => 'redes', 'canal' => 'tiktok', 'is_active' => true, 'orden' => 1]);
        $bn = \App\Models\TipoPublicacion::create(['campaign_id' => $this->c->id, 'nombre' => 'Banner portada', 'etiqueta' => 'Banner', 'clase' => 'web', 'canal' => 'digital', 'is_active' => true, 'orden' => 2]);
        $otro = \App\Models\TipoPublicacion::create(['campaign_id' => $this->c->id, 'nombre' => 'Post', 'etiqueta' => 'Post', 'clase' => 'redes', 'canal' => 'facebook', 'is_active' => true, 'orden' => 3]);
        $sub = \App\Models\Subcampana::create(['campaign_id' => $this->c->id, 'nombre' => 'Vacunación', 'is_active' => true, 'orden' => 1]);
        $sub->tipos()->attach([$tk->id => ['cantidad' => 4], $bn->id => ['cantidad' => 2], $otro->id => ['cantidad' => 3]]);

        $u = $this->inscribir('Alba Luz Erazo');
        $canal6 = MedioCampana::where('nombre', 'Canal 6 Alfasurt')->firstOrFail();
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $canal6->id]);
        $p = CampaignParticipante::where('user_id', $u->id)->firstOrFail();
        // Sin contrato firmado no puede reportar
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'tiktok']), ['tipo_id' => $tk->id])->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma());

        $this->actingAs($u)->get(route('certificados.ver', $p))->assertOk()->assertSee('TikTok: pantallazos con el alcance')->assertSee('Banners publicados en tu web')
            ->assertSee('Contrato No. SALUD-2026-007 firmado');
        // TikTok: falta el pantallazo y el alcance; un tipo que no es de TikTok no se acepta
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'tiktok']), ['subcampana_id' => $sub->id, 'tipo_id' => $tk->id, 'fecha' => '2026-03-02'])
            ->assertSessionHasErrors(['captura', 'alcance']);
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'tiktok']), ['subcampana_id' => $sub->id, 'tipo_id' => $otro->id, 'fecha' => '2026-03-02', 'alcance' => 10, 'captura' => UploadedFile::fake()->image('x.png')])
            ->assertSessionHasErrors('tipo_id');
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'tiktok']), [
            'subcampana_id' => $sub->id, 'tipo_id' => $tk->id, 'fecha' => '2026-03-02', 'url' => 'https://www.tiktok.com/@canal6/video/1',
            'alcance' => 15300, 'visualizaciones' => 22000, 'interacciones' => 480, 'descripcion' => 'Jornada', 'captura' => UploadedFile::fake()->image('stats.png', 600, 1200),
        ])->assertRedirect(route('certificados.ver', $p) . '#tiktok');
        $prod = \App\Models\Producto::where('participante_id', $p->id)->firstOrFail();
        $pub = $prod->publicaciones()->firstOrFail();
        $this->assertSame([$tk->id, $sub->id, 'Publicado / Entregado', 'TikTok · Canal 6 Alfasurt Pitalito · Jornada'], [(int) $prod->tipo_id, (int) $prod->subcampana_id, $prod->estado, $prod->nombre]);
        $this->assertSame([15300, 22000, 480, 'Canal 6 Alfasurt Pitalito · TikTok'], [$pub->alcance, $pub->visualizaciones, $pub->interacciones, $pub->medio]);
        Storage::disk('local')->assertExists($pub->evidencia_path);

        // Banner: dirección y captura obligatorias
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'banner']), ['subcampana_id' => $sub->id, 'tipo_id' => $bn->id, 'fecha' => '2026-03-01'])
            ->assertSessionHasErrors(['url', 'captura']);
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'banner']), ['subcampana_id' => $sub->id, 'tipo_id' => $bn->id, 'fecha' => '2026-03-01', 'fecha_fin' => '2026-03-31',
            'url' => 'https://canal6.co', 'captura' => UploadedFile::fake()->image('banner.png', 1200, 300)])->assertRedirect();
        $this->assertSame(2, \App\Models\Producto::where('participante_id', $p->id)->count());
        // Cuenta en el avance de la subcampaña
        $this->assertSame(1, app(\App\Services\ProductosService::class)->matrizSubcampanas($this->c)[$sub->id][$tk->id]['piezas']);
        $this->actingAs($u)->get(route('certificados.ver', $p))->assertSee('alcance 15.300')->assertSee('Banner · Canal 6 Alfasurt Pitalito');

        // Las capturas solo las ven el medio y el administrador; solo el medio borra lo suyo
        $u2 = User::factory()->create(['role_id' => null]); // otro usuario de editus
        $this->actingAs($u2)->get(route('reportes-medio.captura', $pub))->assertForbidden();
        $pendiente = $this->inscribir('Medio sin firmar'); // con el contrato pendiente, primero lo manda a firmar
        $this->actingAs($pendiente)->get(route('reportes-medio.captura', $pub))->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($this->admin)->get(route('reportes-medio.captura', $pub))->assertOk();
        $this->actingAs($u2)->delete(route('reportes-medio.borrar', $prod))->assertForbidden();
        $this->actingAs($u)->delete(route('reportes-medio.borrar', $prod))->assertRedirect();
        $this->assertSame(1, \App\Models\Producto::where('participante_id', $p->id)->count());
        Storage::disk('local')->assertMissing($pub->evidencia_path);
    }

    public function test_doble_clic_no_gasta_otro_numero_y_un_numero_con_barra_se_descarga(): void
    {
        $this->prepararContratos();
        // Numeración con «/» (p. ej. CPS/2026/007): el número se respeta y la descarga no se rompe
        $this->actingAs($this->admin)->post(route('campaigns.contratos.config', $this->c), ['prefijo' => 'CPS/2026/', 'inicio' => 7, 'digitos' => 3])->assertRedirect();
        $u = $this->inscribir('Alba Luz Erazo');
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => MedioCampana::where('nombre', 'Canal 6 Alfasurt')->value('id')]);
        $p = CampaignParticipante::where('user_id', $u->id)->firstOrFail();

        // Dos envíos del mismo formulario que llegan casi juntos: un solo contrato, un solo número y un solo correo
        $datos = $this->datosFirma();
        $archivos = ['logo' => $datos['logo'], 'rut' => $datos['rut'], 'certificado_bancario' => $datos['certificado_bancario']];
        $png = base64_decode(substr($datos['firma'], strlen('data:image/png;base64,')));
        $k1 = app(ContratosService::class)->firmar($p, $datos, $archivos, $png);
        $k2 = app(ContratosService::class)->firmar($p->fresh(), $datos, $archivos, $png);
        $this->assertSame([$k1->id, 1, 'CPS/2026/007'], [$k2->id, Contrato::count(), $k1->codigo]);
        $this->assertCount(5, Storage::disk('local')->files('contratos/' . $this->c->id)); // firma, logo, RUT, certificado y PDF (sin la firma repetida)
        Mail::assertSent(ContratoFirmado::class, 1);

        $this->actingAs($u)->get(route('contratos.pdf', $k1))->assertOk();
        $this->assertStringContainsString('Contrato CPS-2026-007 - Canal 6 Alfasurt Pitalito.pdf', $this->actingAs($u)->get(route('contratos.pdf', $k1))->headers->get('Content-Disposition'));
        $this->assertStringContainsString('rut-CPS-2026-007.pdf', $this->actingAs($u)->get(route('contratos.archivo', [$k1, 'rut']))->assertOk()->headers->get('Content-Disposition'));
    }

    public function test_sin_listado_de_medios_el_certificado_sigue_como_antes(): void
    {
        $u = $this->inscribir('Medio sin contrato');
        $this->actingAs($u)->get(route('certificados.formulario', $this->c))->assertOk()->assertSee('Términos y condiciones');
    }

    public function test_un_medio_sin_tipo_de_contrato_no_se_puede_elegir(): void
    {
        MedioCampana::create(['campaign_id' => $this->c->id, 'nombre' => 'Sin tipo', 'activo' => true]);
        $u = $this->inscribir('Alguien');
        $m = MedioCampana::firstOrFail();
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $m->id])->assertSessionHasErrors('medio_id');
    }
}
