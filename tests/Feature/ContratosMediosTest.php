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
        (require database_path('migrations/2026_11_06_000001_canales_de_los_medios.php'))->up();
        (require database_path('migrations/2026_11_06_000002_modelos_de_contrato.php'))->up();
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
            'nombre' => 'Contrato emisoras',
            'cuerpo' => '<h2>Objeto</h2><p>Contrato No. {numero} entre {contratante} y {medio} ({canales}), representado por {representante} con {documento_tipo} {documento}, correo {correo}.</p><script>alert(1)</script>',
        ])->assertRedirect();
        $this->actingAs($this->admin)->post(route('campaigns.contratos.config', $this->c), [
            'prefijo' => 'SALUD-2026-', 'inicio' => 7, 'digitos' => 3, 'contratante' => 'DIME MEDIA S.A.S.', 'contratante_nit' => '901.000.000-1',
            'contratante_representante' => 'Juan Charry', 'copia_a' => 'contratos@dimemedia.co, no-es-correo',
        ])->assertRedirect();
        $tipo = ContratoTipo::firstOrFail();
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.bloque', $this->c), [
            'lista' => "Canal 6 Alfasurt\nLa Voz de Pitalito; voz@pitalito.co\nCanal 6 Alfasurt", 'canales' => ['radio', 'facebook'], 'pauta' => ['facebook'], 'tipo_id' => $tipo->id,
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
            'medio_nombre' => 'Canal 6 Alfasurt Pitalito', 'razon_social' => 'Canal 6 Alfasurt S.A.S.', 'nit' => '800.123.456-7',
            'banco' => 'Bancolombia', 'tipo_cuenta' => 'Ahorros', 'numero_cuenta' => '123-456789-01', 'titular_cuenta' => 'Canal 6 Alfasurt S.A.S.', 'documento_titular' => '800.123.456-7',
            'canal' => ['web' => 'www.canal6.co', 'facebook' => 'facebook.com/canal6', 'instagram' => '@canal6', 'tiktok' => '@canal6', 'radio' => 'Canal 6 · 96.3 FM'],
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
            ->assertSee('Medios y contratos')->assertSee('Canal 6 Alfasurt')->assertSee('SALUD-2026-007')->assertSee('Sin inscribir')
            ->assertSee('Facebook (con pauta)')->assertSee('Emisora');
        $this->actingAs($this->admin)->get(route('campaigns.contratos.tipos.vista', [$this->c, ContratoTipo::first()->id]))->assertOk()
            ->assertSee('Medio de ejemplo')->assertSee('DIME MEDIA S.A.S.')->assertSee('página web, Facebook con pauta y TikTok')->assertDontSee('<script>alert(1)</script>', false);
        // Un medio sin canales no se puede crear, y la pauta solo va en redes sociales
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.bloque', $this->c), ['lista' => 'Sin canales'])->assertSessionHasErrors('canales');
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.bloque', $this->c), ['lista' => 'X', 'canales' => ['radio'], 'pauta' => ['radio']])->assertSessionHasErrors('pauta.0');
        $this->assertSame(['tiktok' => ['pauta' => true], 'radio' => ['pauta' => false]], MedioCampana::desdeFormulario(['radio', 'tiktok', 'fax'], ['tiktok', 'radio']));
        $this->assertSame('página web, Facebook con pauta y emisora', MedioCampana::textoDe(['radio' => [], 'facebook' => ['pauta' => true], 'web' => []]));

        $u = $this->inscribir('Alba Luz Erazo');
        // Primero el contrato: el certificado (vincular el medio) y cualquier pantalla lo llevan a firmarlo
        $this->actingAs($u)->get(route('certificados.formulario', $this->c))->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->get(route('profile.edit'))->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('¿Qué medio representas?')->assertSee('La Voz de Pitalito');

        $canal6 = MedioCampana::where('nombre', 'Canal 6 Alfasurt')->firstOrFail();
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $canal6->id])->assertRedirect(route('contratos.formulario', $this->c));
        $this->actingAs($u)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('Contrato emisoras')->assertSee('SALUD-2026-007')
            ->assertSee('data-var="representante"', false)->assertSee('Canales que cubre el contrato')->assertSee('Nombre y frecuencia de la emisora')
            ->assertSee('Página de Facebook')->assertDontSee('Usuario de TikTok');

        // Faltan datos, documentos o la firma
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), ['medio_nombre' => 'X'])
            ->assertSessionHasErrors(['logo', 'rut', 'certificado_bancario', 'representante', 'documento', 'correo', 'acepto', 'firma', 'canal.radio', 'canal.facebook']);

        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma())->assertRedirect(route('certificados.formulario', $this->c));
        $k = Contrato::firstOrFail();
        $this->assertSame([7, 'SALUD-2026-007', 'alba@canal6.co'], [$k->numero, $k->codigo, $k->dato('correo')]);
        $this->assertStringContainsString('Contrato No. SALUD-2026-007 entre DIME MEDIA S.A.S. y Canal 6 Alfasurt Pitalito (Facebook con pauta y emisora), representado por Alba Luz Erazo con C.C. 36.123.456', $k->cuerpo);
        // Quedan los canales que marcó el administrador con el dato que escribió el medio (la emisora no lleva pauta)
        $this->assertSame(['facebook' => ['pauta' => true, 'dato' => 'facebook.com/canal6'], 'radio' => ['pauta' => false, 'dato' => 'Canal 6 · 96.3 FM']], $k->canales());
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

        // La firma del contratante se cargó después de que el medio firmó: al descargarlo, el PDF se rehace con ella
        $antes = $k->fresh()->pdf_path;
        $im = imagecreatetruecolor(240, 120); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        ob_start(); imagepng($im); $png = ob_get_clean();
        $this->actingAs($this->admin)->post(route('campaigns.contratos.config', $this->c), array_merge(app(\App\Services\ContratosService::class)->config($this->c->fresh()), ['copia_a' => 'contratos@dimemedia.co',
            'firma' => \Illuminate\Http\UploadedFile::fake()->createWithContent('firma.png', $png)]))->assertSessionHasNoErrors();
        $firma = app(\App\Services\ContratosService::class)->config($this->c->fresh())['contratante_firma'];
        $this->assertNotNull($firma);
        touch(Storage::disk('local')->path($antes), time() - 60); // el PDF es de antes de la firma
        $this->actingAs($u)->get(route('contratos.pdf', $k))->assertOk();
        $this->assertNotSame($antes, $k->fresh()->pdf_path, 'PDF rehecho con la firma del contratante');
        Storage::disk('local')->assertMissing($antes);
        $ahora = $k->fresh()->pdf_path;
        $this->actingAs($u)->get(route('contratos.pdf', $k))->assertOk();
        $this->assertSame($ahora, $k->fresh()->pdf_path, 'solo se rehace una vez');

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

        // Canal 6 tiene página web, TikTok con pauta y emisora (el administrador edita sus canales)
        $canal6 = MedioCampana::where('nombre', 'Canal 6 Alfasurt')->firstOrFail();
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.guardar', $this->c), [
            'id' => $canal6->id, 'nombre' => 'Canal 6 Alfasurt', 'canales' => ['web', 'tiktok', 'radio'], 'pauta' => ['tiktok'], 'tipo_id' => $tipo->id, 'activo' => '1',
        ])->assertRedirect();
        $this->assertSame(['web' => ['pauta' => false], 'tiktok' => ['pauta' => true], 'radio' => ['pauta' => false]], $canal6->fresh()->canales());

        $u = $this->inscribir('Alba Luz Erazo');
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

        // La Voz de Pitalito solo tiene emisora y Facebook: en su portal no le aparecen TikTok ni banners, y no puede reportarlos
        $voz = $this->inscribir('Pedro Pitalito');
        $this->actingAs($voz)->post(route('contratos.elegir', $this->c), ['medio_id' => MedioCampana::where('nombre', 'La Voz de Pitalito')->value('id')]);
        $this->actingAs($voz)->post(route('contratos.firmar', $this->c), $this->datosFirma(['medio_nombre' => 'La Voz de Pitalito', 'correo' => 'pedro@voz.co']))->assertRedirect();
        $pv = CampaignParticipante::where('user_id', $voz->id)->firstOrFail();
        $this->actingAs($voz)->get(route('certificados.ver', $pv))->assertOk()->assertDontSee('TikTok: pantallazos con el alcance')->assertDontSee('Banners publicados en tu web');
        $this->actingAs($voz)->post(route('reportes-medio.guardar', [$pv, 'tiktok']), ['subcampana_id' => $sub->id, 'tipo_id' => $tk->id, 'fecha' => '2026-03-02',
            'alcance' => 10, 'captura' => UploadedFile::fake()->image('x.png')])->assertForbidden();

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

    public function test_los_modelos_del_pic_2026_quedan_listos_y_basta_con_elegirlos(): void
    {
        // Al abrir «Medios y contratos» los tres modelos ya están, con sus canales y las partes (arrendador / arrendatario)
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertOk()
            ->assertSee('Modelo 1 · Publicaciones en el medio digital')->assertSee('Modelo 3 · Publicaciones, Instagram, TikTok, banners y radio');
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertOk(); // no se cargan dos veces
        $tipos = ContratoTipo::where('campaign_id', $this->c->id)->orderBy('orden')->get()->keyBy('clave');
        $this->assertSame(['pic-2026-1', 'pic-2026-2', 'pic-2026-3'], $tipos->keys()->all());
        $m1 = $tipos['pic-2026-1']; $m2 = $tipos['pic-2026-2']; $m3 = $tipos['pic-2026-3'];
        $this->assertSame(['facebook'], array_keys($m1->canalesPorDefecto()));
        $this->assertSame(['web', 'facebook', 'instagram', 'tiktok', 'radio'], array_keys($m3->canalesPorDefecto()));
        $this->assertSame(['EL ARRENDADOR', 'EL ARRENDATARIO'], [$m3->rolMedio(), $m3->rolContratante()]);
        $this->assertTrue($m3->usaValor());
        $this->assertStringNotContainsString('___', $m3->cuerpo);
        $cfg = app(ContratosService::class)->config($this->c->fresh());
        $this->assertSame(['DIME MEDIA S.A.S.', '901.908.077-3', 'JUAN CARLOS CHARRY GONZALEZ'], [$cfg['contratante'], $cfg['contratante_nit'], $cfg['contratante_representante']]);
        Storage::disk('local')->assertExists($cfg['contratante_membrete']);
        $this->actingAs($this->admin)->get(route('campaigns.contratos.tipos.vista', [$this->c, $m3->id]))->assertOk()
            ->assertSee('Medio de Ejemplo S.A.S.')->assertSee('DOS MILLONES QUINIENTOS MIL')->assertSee('cédula de ciudadanía');

        // Basta con elegir el modelo: el medio queda con sus canales; el valor se escribe como sea
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.guardar', $this->c), ['nombre' => 'Opanoticias', 'tipo_id' => $m3->id, 'valor' => '$ 2.500.000'])->assertRedirect();
        $opa = MedioCampana::where('nombre', 'Opanoticias')->firstOrFail();
        $this->assertSame([['web', 'facebook', 'instagram', 'tiktok', 'radio'], 2500000, '$ 2.500.000'], [array_keys($opa->canales()), $opa->valor, $opa->valorTexto()]);
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.bloque', $this->c), [
            'lista' => "Neiva 24; contacto@neiva24.com; 1.800.000\nLa Voz del Huila", 'tipo_id' => $m2->id,
        ])->assertSessionHas('success', 'Se agregaron 2 medios.');
        $this->assertSame([['web', 'facebook'], 1800000, 'contacto@neiva24.com'], [array_keys(MedioCampana::where('nombre', 'Neiva 24')->first()->canales()), MedioCampana::where('nombre', 'Neiva 24')->value('valor'), MedioCampana::where('nombre', 'Neiva 24')->value('correo')]);
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.guardar', $this->c), ['nombre' => 'Sin nada'])->assertSessionHasErrors('canales');
        // El contrato divide el valor en dos cuotas iguales: un valor impar no se acepta
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.guardar', $this->c), ['nombre' => 'Impar', 'tipo_id' => $m3->id, 'valor' => '2.500.001'])->assertSessionHasErrors('valor');
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.bloque', $this->c), ['lista' => "Impar; 1.000.001\nPar; 1.000.000", 'tipo_id' => $m2->id])->assertSessionHasErrors('lista');
        $this->assertFalse(MedioCampana::whereIn('nombre', ['Impar', 'Par'])->exists());

        // Un medio sin valor no puede firmar (el contrato lo pide)
        $voz = $this->inscribir('Pedro Pitalito');
        $this->actingAs($voz)->post(route('contratos.elegir', $this->c), ['medio_id' => MedioCampana::where('nombre', 'La Voz del Huila')->value('id')]);
        $this->actingAs($voz)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('todavía no ha puesto el valor de tu contrato');
        $this->actingAs($voz)->post(route('contratos.firmar', $this->c), $this->datosFirma())->assertSessionHasErrors('valor');
        $this->assertSame(0, Contrato::count());

        // Opanoticias firma el modelo 3: número, partes, valor en letras, cuenta y canales quedan en el contrato
        $u = $this->inscribir('Alba Luz Erazo');
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $opa->id]);
        $this->actingAs($u)->get(route('contratos.formulario', $this->c))->assertOk()->assertSee('Valor del contrato: $ 2.500.000')
            ->assertSee('Razón social')->assertSee('Número de cuenta')->assertSee('Nombre y frecuencia de la emisora');
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma(['medio_nombre' => 'Opanoticias', 'razon_social' => 'Opa Noticias S.A.S.',
            'canal' => ['web' => 'www.opanoticias.com', 'facebook' => 'facebook.com/opanoticias', 'instagram' => '@opanoticias', 'tiktok' => '@opanoticias', 'radio' => 'Opa Radio 98.5 FM']]))
            ->assertRedirect(route('certificados.formulario', $this->c));
        $k = Contrato::firstOrFail();
        foreach (['No. 001 DE 2026', 'Entre los suscritos a saber: <strong>Opa Noticias S.A.S.</strong>, representado legalmente por <strong>Alba Luz Erazo</strong>',
                  'identificado con cédula de ciudadanía No. <strong>36.123.456</strong>', 'su medio digital denominado <strong>Opanoticias</strong>',
                  'la suma de <strong>$ 2.500.000</strong> (<strong>DOS MILLONES QUINIENTOS MIL</strong> pesos M/cte.)',
                  'en dos (2) cuotas mensuales iguales, mes vencido', 'es decir, la suma de $ 1.250.000 (UN MILLÓN DOSCIENTOS CINCUENTA MIL pesos M/cte.) por mes',
                  'Banco: <strong>Bancolombia</strong>', 'Tipo de cuenta: <strong>Ahorros</strong>', 'Número de cuenta: <strong>123-456789-01</strong>',
                  'Hasta 20 publicaciones por mes en TikTok, publicadas directamente por EL ARRENDADOR'] as $texto) {
            $this->assertStringContainsString($texto, $k->cuerpo, $texto);
        }
        $this->assertSame(['EL ARRENDADOR', 'EL ARRENDATARIO', 2500000], [$k->dato('rol_medio'), $k->dato('rol_contratante'), $k->datos['valor']]);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($k->pdf_path));

        // Si borran un modelo (sin medios), se puede volver a cargar
        $m1->delete();
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertSee('Cargar los modelos del PIC 2026');
        $this->actingAs($this->admin)->post(route('campaigns.contratos.modelos', $this->c))->assertSessionHas('success', 'Se cargó 1 modelo de contrato.');
        $this->assertSame(3, ContratoTipo::where('campaign_id', $this->c->id)->whereNotNull('clave')->count());
    }

    public function test_la_firma_de_dime_media_sale_aunque_el_contrato_se_haya_firmado_antes(): void
    {
        $svc = app(ContratosService::class);
        // Campaña con los modelos del PIC cargados antes de que existiera la firma: queda con la de Dime Media
        $this->c->forceFill(['contratos' => ['modelos_cargados' => true, 'contratante' => 'DIME MEDIA S.A.S.']])->save();
        $this->assertSame(1, $svc->ponerFirmaDeLosModelos());
        $firma = $svc->config($this->c->fresh())['contratante_firma'];
        $this->assertNotNull($firma);
        $this->assertSame(file_get_contents(resource_path('contratos/' . ContratosService::MODELOS_FIRMA)), Storage::disk('local')->get($firma));
        $this->assertSame(0, $svc->ponerFirmaDeLosModelos(), 'no la duplica');

        // Si el contratante es otra empresa, no se le pone la firma de Dime Media
        $this->c->forceFill(['contratos' => ['modelos_cargados' => true, 'contratante' => 'OTRA EMPRESA S.A.S.']])->save();
        $this->assertSame(0, $svc->ponerFirmaDeLosModelos($this->c->fresh()));
        $this->assertNull($svc->config($this->c->fresh())['contratante_firma']);
    }

    public function test_los_modelos_ya_cargados_se_ponen_al_dia_si_nadie_los_edito(): void
    {
        // Así quedaron los modelos cargados antes del cambio de la cláusula quinta (forma de pago)
        $nueva = 'El valor del contrato se pagará en dos (2) cuotas mensuales iguales, mes vencido, cada una equivalente al cincuenta por ciento (50%) del valor total del contrato, es decir, la suma de $ {valor_mitad} ({valor_mitad_letras} pesos M/cte.) por mes, previa presentación de los siguientes documentos:';
        $vieja = 'EL ARRENDATARIO pagará a EL ARRENDADOR el valor de las publicaciones efectivamente realizadas en cada periodo mensual, previa presentación de los siguientes documentos:';
        $antes = fn(int $n) => ContratosService::limpiarHtml(str_replace($nueva, $vieja, file_get_contents(resource_path("contratos/pic-2026/modelo-$n.html"))));
        foreach ([1, 2] as $n) $this->assertContains(ContratosService::huella($antes($n)), ContratosService::MODELOS["pic-2026-$n"]['anteriores']);
        $sinEditar = ContratoTipo::create(['campaign_id' => $this->c->id, 'clave' => 'pic-2026-1', 'nombre' => 'Modelo 1', 'cuerpo' => str_replace("\n", "\r\n", $antes(1)), 'activo' => true]);
        $editado = ContratoTipo::create(['campaign_id' => $this->c->id, 'clave' => 'pic-2026-2', 'nombre' => 'Modelo 2', 'cuerpo' => $antes(2) . '<p>Cláusula propia.</p>', 'activo' => true]);

        // Al abrir «Medios y contratos»: el que nadie editó queda con el texto nuevo; el editado se respeta
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertOk();
        $this->assertStringContainsString('dos (2) cuotas mensuales iguales', $sinEditar->fresh()->cuerpo);
        $this->assertTrue($sinEditar->fresh()->usaMitad());
        $this->assertStringNotContainsString('dos (2) cuotas mensuales iguales', $editado->fresh()->cuerpo);
        $this->assertSame(3, ContratoTipo::where('campaign_id', $this->c->id)->count()); // y el modelo 3, que faltaba, se cargó
        $this->assertSame(0, app(ContratosService::class)->actualizarModelos()); // ya no hay nada que actualizar
    }

    public function test_sin_publicaciones_se_borra_y_con_publicaciones_solo_se_deshabilita(): void
    {
        $tipo = $this->prepararContratos();
        // Un medio que nadie ha elegido se borra
        $voz = MedioCampana::where('nombre', 'La Voz de Pitalito')->firstOrFail();
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertSee('Aún no ha publicado nada: se puede borrar.');
        $this->actingAs($this->admin)->delete(route('campaigns.contratos.medios.borrar', [$this->c, $voz->id]))->assertSessionHas('success', 'Medio «La Voz de Pitalito» borrado.');
        $this->assertNull($voz->fresh());

        // Canal 6 firmó pero no ha publicado nada: también se borra, y su contrato queda anulado (con su número, para el registro)
        $otro = MedioCampana::create(['campaign_id' => $this->c->id, 'nombre' => 'Otro medio', 'canales' => ['facebook' => ['pauta' => false]], 'tipo_id' => $tipo->id, 'activo' => true, 'orden' => 9]);
        $u = $this->inscribir('Alba Luz Erazo');
        $canal6 = MedioCampana::where('nombre', 'Canal 6 Alfasurt')->firstOrFail();
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $canal6->id]);
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma())->assertRedirect(route('certificados.formulario', $this->c));
        $k = Contrato::firstOrFail();
        $this->actingAs($this->admin)->delete(route('campaigns.contratos.medios.borrar', [$this->c, $canal6->id]))
            ->assertSessionHas('success', 'Medio «Canal 6 Alfasurt» borrado. Su contrato No. SALUD-2026-007 quedó anulado.');
        $this->assertNull($canal6->fresh());
        $this->assertNotNull($k->fresh()->anulado_at);
        $this->actingAs($u)->get(route('profile.edit'))->assertRedirect(route('contratos.formulario', $this->c)); // vuelve a elegir y firmar

        // Elige el otro medio, firma y publica: ya no se puede borrar, solo deshabilitar
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $otro->id]);
        $this->actingAs($u)->post(route('contratos.firmar', $this->c), $this->datosFirma(['medio_nombre' => 'Otro medio', 'canal' => ['facebook' => 'facebook.com/otro']]))->assertRedirect();
        $this->assertSame('SALUD-2026-008', Contrato::whereNull('anulado_at')->value('codigo')); // el número anulado no se reutiliza
        $p = CampaignParticipante::where('user_id', $u->id)->firstOrFail();
        $pagina = MetaPage::create(['page_id' => '900', 'name' => 'Otro medio']);
        \Illuminate\Support\Facades\DB::table('meta_page_user')->insert(['meta_page_id' => $pagina->id, 'user_id' => $u->id]);
        $p->forceFill(['meta_page_ids' => [$pagina->id]])->save();
        app(\App\Services\CertificadosService::class)->sincronizarPaginas($this->c);
        $this->assertDatabaseHas('campana_pagina', ['meta_page_id' => $pagina->id, 'origen' => 'inscrito']);
        $this->assertSame([], app(ContratosService::class)->medioIdsConActividad($this->c)); // vinculado, pero aún sin publicar
        \App\Models\MetaPost::create(['user_id' => $this->admin->id, 'meta_page_id' => $pagina->id, 'campaign_id' => $this->c->id, 'type' => 'photo', 'network' => 'facebook', 'status' => 'success', 'published_at' => now()]);
        $this->assertSame([$otro->id], app(ContratosService::class)->medioIdsConActividad($this->c));
        $this->actingAs($this->admin)->get(route('campaigns.contratos', $this->c))->assertSee('Con publicaciones')->assertSee('no se puede borrar, solo deshabilitar');
        $this->actingAs($this->admin)->delete(route('campaigns.contratos.medios.borrar', [$this->c, $otro->id]))
            ->assertSessionHas('error', '«Otro medio» ya tiene publicaciones en la campaña: no se puede borrar, solo deshabilitar.');
        $this->assertNotNull($otro->fresh());

        // Deshabilitado: sale de las páginas de la campaña y no puede reportar; su contrato y lo publicado quedan
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.estado', [$this->c, $otro->id]))->assertSessionHas('success');
        $this->assertFalse($otro->fresh()->activo);
        $this->assertDatabaseMissing('campana_pagina', ['meta_page_id' => $pagina->id, 'origen' => 'inscrito']);
        $this->actingAs($u)->get(route('certificados.ver', $p))->assertOk()->assertSee('Este medio está deshabilitado en la campaña')->assertSee('Contrato No. SALUD-2026-008 firmado');
        $this->actingAs($u)->post(route('reportes-medio.guardar', [$p, 'tiktok']), ['tipo_id' => 1])->assertForbidden();
        $this->assertSame(1, \App\Models\MetaPost::where('campaign_id', $this->c->id)->count());
        // Guardar sus datos no lo vuelve a habilitar; el botón sí
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.guardar', $this->c), ['id' => $otro->id, 'nombre' => 'Otro medio', 'tipo_id' => $tipo->id, 'canales' => ['facebook']])->assertRedirect();
        $this->assertFalse($otro->fresh()->activo);
        $this->actingAs($this->admin)->post(route('campaigns.contratos.medios.estado', [$this->c, $otro->id]))->assertSessionHas('success', '«Otro medio» quedó habilitado.');
        $this->assertTrue($otro->fresh()->activo);
        $this->assertDatabaseHas('campana_pagina', ['meta_page_id' => $pagina->id, 'origen' => 'inscrito']);
    }

    public function test_sin_listado_de_medios_el_certificado_sigue_como_antes(): void
    {
        $u = $this->inscribir('Medio sin contrato');
        $this->actingAs($u)->get(route('certificados.formulario', $this->c))->assertOk()->assertSee('Términos y condiciones');
    }

    public function test_los_medios_que_tenian_clase_quedan_con_su_canal(): void
    {
        $id = \Illuminate\Support\Facades\DB::table('campaign_medios')->insertGetId(['campaign_id' => $this->c->id, 'nombre' => 'Emisora vieja', 'clase' => 'emisora',
            'activo' => true, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
        (require database_path('migrations/2026_11_06_000001_canales_de_los_medios.php'))->up();
        $this->assertSame(['radio' => ['pauta' => false]], MedioCampana::findOrFail($id)->canales());
    }

    public function test_un_medio_sin_tipo_de_contrato_no_se_puede_elegir(): void
    {
        MedioCampana::create(['campaign_id' => $this->c->id, 'nombre' => 'Sin tipo', 'activo' => true]);
        $u = $this->inscribir('Alguien');
        $m = MedioCampana::firstOrFail();
        $this->actingAs($u)->post(route('contratos.elegir', $this->c), ['medio_id' => $m->id])->assertSessionHasErrors('medio_id');
    }
}
