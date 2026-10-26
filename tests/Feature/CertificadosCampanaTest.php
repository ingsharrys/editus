<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Role;
use App\Models\User;
use App\Services\CampanasService;
use App\Services\CertificadosService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Enlace de inscripción de medios a una campaña y certificado de publicación firmado. */
class CertificadosCampanaTest extends TestCase
{
    private User $admin;
    private Campaign $c;
    private MetaPage $canal6;
    private MetaPage $otra;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias', 'neiva24' => 'Neiva 24'], 'services.facebook.version' => 'v23.0']);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app')->nullable(); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->text('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('meta_page_favorites', function (Blueprint $t) { $t->id(); $t->foreignId('user_id'); $t->foreignId('meta_page_id'); $t->timestamps(); });
        Schema::create('meta_posts', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->uuid('batch_uuid')->nullable(); $t->string('type'); $t->string('network')->nullable();
            $t->text('message')->nullable(); $t->string('link')->nullable(); $t->json('local_media')->nullable(); $t->json('fb_media_ids')->nullable();
            $t->string('fb_post_id')->nullable(); $t->string('fb_permalink_url')->nullable(); $t->string('status')->default('pending');
            $t->unsignedInteger('alcance')->nullable(); $t->unsignedInteger('visualizaciones')->nullable(); $t->unsignedInteger('interacciones')->nullable();
            $t->string('evidencia_path')->nullable(); $t->timestamp('last_insights_at')->nullable(); $t->text('error')->nullable();
            $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_07_26_000001_create_campaigns_table.php'))->up();
        (require database_path('migrations/2025_10_03_100000_create_inteligencia_audiencia.php'))->up();
        (require database_path('migrations/2025_10_09_100000_consultor_ia_y_emociones.php'))->up();
        (require database_path('migrations/2026_10_10_000001_unificar_campanas.php'))->up();
        Role::query()->insert([['name' => 'Admin', 'slug' => 'admin'], ['name' => 'User', 'slug' => 'user']]);
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        $migracion = require database_path('migrations/2026_10_26_000001_inscripcion_y_certificados_campanas.php');
        $migracion->up();
        $migracion->up(); // idempotente

        Storage::fake('local');
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->c = app(CampanasService::class)->guardar(new Campaign(), [
            'name' => 'Salud 2026', 'tipo' => 'institucional', 'description' => 'Plan de medios Secretaría de Salud 2026',
            'medios' => ['opanoticias'], 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-30',
        ], $this->admin->id);
        $this->canal6 = MetaPage::create(['page_id' => '600', 'name' => 'Canal 6 Alfasurt']);
        $this->otra = MetaPage::create(['page_id' => '700', 'name' => 'Otra página']);
    }

    private function datosCampana(array $extra = []): array
    {
        return $extra + [
            'name' => 'Salud 2026', 'tipo' => 'institucional', 'medios' => ['opanoticias'],
            'starts_on' => '2025-11-01', 'ends_on' => '2025-11-30',
            'inscripcion_activa' => '1', 'cert_obligatorio' => '1',
            'cert_entidad' => 'Secretaría de Salud Departamental del Huila', 'cert_orden' => 'FM-1441-963',
            'cert_contratista' => 'DIME MEDIA S.A.S.', 'cert_contratante' => 'Fondo Mixto de Cultura y Turismo del Huila – FOMCULTURA',
            'cert_texto' => CertificadosService::TEXTO_DEFECTO, 'cert_cierre' => CertificadosService::CIERRE_DEFECTO,
            'cert_fecha_expedicion' => '2025-12-01',
            'cert_piezas' => "Programa 2. Salud Infantil | Vacunación\nPrograma 6. ETV: Dengue y Fiebre Amarilla | Dengue",
            'cert_tipos_pieza' => "Video Corto\nVideo Largo\nPost",
        ];
    }

    private function abrirInscripcion(): string
    {
        $this->actingAs($this->admin)->post(route('campaigns.update', $this->c), $this->datosCampana())->assertRedirect();
        $this->c->refresh();
        $this->assertTrue($this->c->inscripcion_activa);
        $this->assertSame(40, strlen($this->c->inscripcion_token));
        $this->assertSame(['programa' => 'Programa 2. Salud Infantil', 'subprograma' => 'Vacunación'], $this->c->piezas[0]);
        $this->assertSame('', $this->c->certificado['texto'], 'el texto por defecto no se guarda');
        auth()->logout();
        return $this->c->inscripcion_token;
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

    private function medio(): User
    {
        $u = User::factory()->create(['name' => 'Alba Luz Erazo', 'role_id' => null]);
        $this->canal6->users()->attach($u->id, ['page_access_token' => 'tok-600', 'is_active' => true]);
        return $u;
    }

    public function test_el_enlace_inscribe_al_entrar_y_el_certificado_es_obligatorio(): void
    {
        $token = $this->abrirInscripcion();

        // Sin sesión: muestra la invitación y la recuerda para después del login
        $this->get(route('inscripcion.mostrar', $token))->assertOk()->assertSee('Salud 2026')->assertSessionHas('inscripcion_campana.token', $token);
        $this->get(route('login'))->assertSee('Te estás inscribiendo en la campaña', false);

        // Entra o se registra (la sesión conserva la invitación): queda inscrito y va al certificado
        $u = $this->medio();
        $this->actingAs($u)->withSession(['inscripcion_campana' => ['token' => $token, 'nombre' => 'Salud 2026']])
            ->get(route('profile.edit'))->assertRedirect(route('certificados.formulario', $this->c));
        $this->assertDatabaseHas('campaign_participantes', ['campaign_id' => $this->c->id, 'user_id' => $u->id, 'medio_nombre' => 'Canal 6 Alfasurt', 'firmado_at' => null]);
        $this->assertSame(1, CampaignParticipante::count(), 'solo en la campaña del enlace');

        // Mientras no firme, cualquier pantalla lo lleva al certificado; salir y el propio formulario sí funcionan
        $this->actingAs($u)->get(route('profile.edit'))->assertRedirect(route('certificados.formulario', $this->c));
        $this->actingAs($u)->get(route('certificados.formulario', $this->c))->assertOk()->assertSee('Términos y condiciones')->assertSee('Salud 2026');
        // El administrador nunca queda bloqueado
        $this->actingAs($this->admin)->get(route('profile.edit'))->assertOk();

        // Sin aceptar términos ni firmar no se guarda
        $this->actingAs($u)->post(route('certificados.guardar', $this->c), ['medio_nombre' => 'X'])->assertSessionHasErrors(['logo', 'representante', 'acepto_terminos', 'firma']);

        $r = $this->actingAs($u)->post(route('certificados.guardar', $this->c), [
            'medio_nombre' => 'Canal 6 de Alfasurt Pitalito', 'logo' => UploadedFile::fake()->image('logo.png', 300, 120),
            'representante' => 'Alba Luz Erazo Chavarro', 'documento_tipo' => 'C.C.', 'documento' => '36276152', 'cargo' => 'Gerente',
            'ciudad' => 'Pitalito (Huila)', 'paginas' => [$this->canal6->id], 'acepto_terminos' => '1', 'firma' => $this->firmaPng(),
        ]);
        $p = CampaignParticipante::first();
        $r->assertRedirect(route('certificados.ver', $p));
        $this->assertTrue($p->firmado());
        $this->assertNotNull($p->acepto_terminos_at);
        $this->assertSame(64, strlen($p->terminos_hash));
        Storage::disk('local')->assertExists([$p->logo_path, $p->firma_path]);

        // Ya firmado: puede usar editus y su página queda en la campaña (marcada al publicar)
        $this->actingAs($u)->get(route('profile.edit'))->assertOk();
        $this->assertDatabaseHas('campana_pagina', ['campana_id' => $this->c->perfil->id, 'meta_page_id' => $this->canal6->id, 'origen' => 'inscrito']);
        $this->assertContains($this->canal6->id, app(CampanasService::class)->mapaPaginas(collect([$this->c->fresh()]))[$this->c->id]);
        // Guardar la campaña no borra las páginas de los medios inscritos
        $this->actingAs($this->admin)->post(route('campaigns.update', $this->c), $this->datosCampana())->assertRedirect();
        $this->assertDatabaseHas('campana_pagina', ['meta_page_id' => $this->canal6->id, 'origen' => 'inscrito']);
    }

    public function test_el_certificado_se_llena_con_las_publicaciones_del_medio(): void
    {
        $token = $this->abrirInscripcion();
        $u = $this->medio();
        $this->actingAs($u)->get(route('inscripcion.mostrar', $token))->assertRedirect(route('certificados.formulario', $this->c));
        $this->actingAs($u)->post(route('certificados.guardar', $this->c), [
            'medio_nombre' => 'Canal 6 de Alfasurt Pitalito', 'logo' => UploadedFile::fake()->image('logo.jpg', 200, 100),
            'representante' => 'Alba Luz Erazo Chavarro', 'documento_tipo' => 'C.C.', 'documento' => '36276152', 'cargo' => 'Gerente',
            'ciudad' => 'Pitalito (Huila)', 'acepto_terminos' => '1', 'firma' => $this->firmaPng(),
        ])->assertRedirect();
        $p = CampaignParticipante::first();

        $post = fn(array $d) => MetaPost::create($d + ['user_id' => $this->admin->id, 'type' => 'video', 'network' => 'facebook', 'status' => 'success', 'published_at' => now()]);
        $post(['meta_page_id' => $this->canal6->id, 'campaign_id' => $this->c->id, 'programa' => 'Programa 2. Salud Infantil', 'subprograma' => 'Vacunación', 'tipo_pieza' => 'Video Corto']);
        $post(['meta_page_id' => $this->canal6->id, 'campaign_id' => $this->c->id, 'programa' => 'Programa 6. ETV: Dengue y Fiebre Amarilla', 'subprograma' => 'Dengue', 'tipo_pieza' => 'Video Largo']);
        $post(['meta_page_id' => $this->canal6->id, 'campaign_id' => $this->c->id, 'programa' => 'Programa 6. ETV: Dengue y Fiebre Amarilla', 'subprograma' => 'Dengue', 'tipo_pieza' => 'Video Largo']);
        $post(['meta_page_id' => $this->canal6->id, 'campaign_id' => $this->c->id, 'type' => 'photo']); // sin clasificar
        $post(['meta_page_id' => $this->canal6->id, 'campaign_id' => $this->c->id, 'programa' => 'Fallida', 'status' => 'fail']);
        $post(['meta_page_id' => $this->otra->id, 'campaign_id' => $this->c->id, 'programa' => 'De otra página']);
        $post(['meta_page_id' => $this->canal6->id, 'campaign_id' => null, 'programa' => 'Sin campaña']);

        $piezas = app(CertificadosService::class)->piezas($p->load('campaign'));
        $this->assertCount(3, $piezas);
        $this->assertSame(['programa' => 'Programa 6. ETV: Dengue y Fiebre Amarilla', 'subprograma' => 'Dengue', 'tipo' => 'Video Largo', 'cantidad' => 2], $piezas[1]);
        $this->assertSame('Post', $piezas[2]['tipo']);

        $this->actingAs($u)->get(route('certificados.ver', $p))->assertOk()
            ->assertSee('CERTIFICA:')
            ->assertSee('<strong>CANAL 6 DE ALFASURT PITALITO</strong>', false)
            ->assertSee('entre el 01 y 30 de noviembre de 2025')
            ->assertSee('<strong>Secretaría de Salud Departamental del Huila</strong>', false)
            ->assertSee('según orden de servicios No. FM-1441-963 entre DIME MEDIA S.A.S. y el Fondo Mixto de Cultura y Turismo del Huila – FOMCULTURA')
            ->assertSee('Programa 2. Salud Infantil')->assertSee('Video Largo (2)')
            ->assertDontSee('De otra página')->assertDontSee('Sin campaña')->assertDontSee('Fallida')
            ->assertSee('en Pitalito (Huila), al primer (01) día del mes de diciembre de 2025.')
            ->assertSee('ALBA LUZ ERAZO CHAVARRO')->assertSee('C.C. 36.276.152')->assertSee('GERENTE')
            ->assertSee('data:image/png;base64,', false);

        $pdf = $this->actingAs($u)->get(route('certificados.pdf', $p))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // El administrador lo ve y lo descarga; otro usuario no
        $this->actingAs($this->admin)->get(route('certificados.ver', $p))->assertOk();
        $this->actingAs($this->admin)->get(route('campaigns.edit', $this->c))->assertOk()->assertSee('Canal 6 de Alfasurt Pitalito')->assertSee($this->c->enlaceInscripcion());
        $intruso = User::factory()->create(['role_id' => null]);
        $this->actingAs($intruso)->get(route('certificados.ver', $p))->assertForbidden();
        $this->actingAs($intruso)->get(route('certificados.pdf', $p))->assertForbidden();
        $this->actingAs($intruso)->get(route('certificados.formulario', $this->c))->assertNotFound();
    }

    public function test_enlace_cerrado_o_cambiado_no_inscribe(): void
    {
        $token = $this->abrirInscripcion();
        $this->actingAs($this->admin)->post(route('campaigns.inscripcion.regenerar', $this->c))->assertRedirect();
        $nuevo = $this->c->fresh()->inscripcion_token;
        $this->assertNotSame($token, $nuevo);
        auth()->logout();
        $this->get(route('inscripcion.mostrar', $token))->assertNotFound();
        $this->get(route('inscripcion.mostrar', $nuevo))->assertOk();

        $this->actingAs($this->admin)->post(route('campaigns.update', $this->c), $this->datosCampana(['inscripcion_activa' => '0']))->assertRedirect();
        auth()->logout();
        $this->get(route('inscripcion.mostrar', $nuevo))->assertNotFound();
    }

    public function test_certificado_no_obligatorio_no_bloquea(): void
    {
        $token = $this->abrirInscripcion();
        $this->actingAs($this->admin)->post(route('campaigns.update', $this->c), $this->datosCampana(['cert_obligatorio' => '0']))->assertRedirect();
        auth()->logout();
        $u = $this->medio();
        $this->actingAs($u)->get(route('inscripcion.mostrar', $token))->assertRedirect(route('certificados.formulario', $this->c));
        $this->actingAs($u)->get(route('profile.edit'))->assertOk();
    }

    public function test_publicar_en_la_campana_guarda_la_pieza_y_el_admin_la_puede_corregir(): void
    {
        $this->abrirInscripcion();
        $this->canal6->users()->attach($this->admin->id, ['page_access_token' => 'tok-600', 'is_active' => true]);
        Http::fake([
            'graph.facebook.com/v23.0/600/feed' => Http::response(['id' => '600_1'], 200),
            'graph.facebook.com/*' => Http::response(['permalink_url' => 'https://fb.com/600_1'], 200),
        ]);
        $this->actingAs($this->admin)->post(route('meta.pages.publish'), [
            'campaign_id' => $this->c->id, 'type' => 'text', 'page_ids' => [$this->canal6->id], 'networks' => ['facebook'],
            'message' => 'Vacúnate', 'programa' => 'Programa 2. Salud Infantil', 'subprograma' => 'Vacunación', 'tipo_pieza' => 'Post',
        ])->assertRedirect();
        $post = MetaPost::latest('id')->first();
        $this->assertSame(['Programa 2. Salud Infantil', 'Vacunación', 'Post'], [$post->programa, $post->subprograma, $post->tipo_pieza]);

        $this->actingAs($this->admin)->get(route('campaigns.piezas', $this->c))->assertOk()->assertSee('Vacúnate');
        $this->actingAs($this->admin)->post(route('campaigns.piezas.guardar', $this->c), ['piezas' => [$post->id => ['programa' => 'Programa 9. Pólvora', 'subprograma' => '', 'tipo_pieza' => 'Video Corto']]])->assertRedirect();
        $post->refresh();
        $this->assertSame(['Programa 9. Pólvora', null, 'Video Corto'], [$post->programa, $post->subprograma, $post->tipo_pieza]);
    }

    public function test_fechas_en_letras_y_periodos(): void
    {
        $s = app(CertificadosService::class);
        $this->assertSame('al primer (01) día del mes de diciembre de 2025', $s->fechaEnLetras(Carbon::create(2025, 12, 1)));
        $this->assertSame('a los quince (15) días del mes de enero de 2027', $s->fechaEnLetras(Carbon::create(2027, 1, 15)));
        $this->assertSame('a los veintiún (21) días del mes de marzo de 2026', $s->fechaEnLetras(Carbon::create(2026, 3, 21)));
        $c = new Campaign(['starts_on' => '2026-10-26', 'ends_on' => '2026-12-31']);
        $this->assertSame('entre el 26 de octubre y el 31 de diciembre de 2026', $s->periodo($c));
        $c = new Campaign(['starts_on' => '2026-12-01', 'ends_on' => '2027-01-31']);
        $this->assertSame('entre el 01 de diciembre de 2026 y el 31 de enero de 2027', $s->periodo($c));
        $this->assertSame('36.276.152', $s->formatoDocumento('36276152'));
        $this->assertSame('AB-12', $s->formatoDocumento('AB-12'));
        $this->assertNull($s->guardarFirma(new CampaignParticipante(['campaign_id' => 1]), 'data:image/png;base64,bm8gZXMgdW5hIGltYWdlbg=='));
    }
}
