<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\PlantillaEditor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Módulo admin "App del editor": páginas visibles en la app y plantillas. */
class EditorAppAdminTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable();
            $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps();
        });
        Schema::create('social_accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id'); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token');
            $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps();
        });
        Schema::create('meta_pages', function (Blueprint $t) {
            $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable();
            $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable();
            $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->text('app_usuarios')->nullable(); $t->timestamps();
        });
        Schema::create('meta_page_user', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable();
            $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('plantillas_editor', function (Blueprint $t) {
            $t->id(); $t->string('nombre', 120); $t->string('tipo', 10)->default('diseno'); $t->string('marco_path')->nullable(); $t->string('medio_slug', 100)->nullable(); $t->text('zonas')->nullable(); $t->string('logo_path')->nullable(); $t->string('logo_texto', 60)->nullable(); $t->boolean('logo_tintar')->default(true);
            $t->string('etiqueta', 40)->nullable(); $t->string('pie', 80)->nullable(); $t->string('hashtag', 60)->nullable();
            $t->string('color_titulo', 7)->default('#FFFFFF'); $t->string('color_logo', 7)->default('#FFFFFF'); $t->string('color_etiqueta', 7)->default('#C8102E');
            $t->boolean('predeterminada')->default(false); $t->boolean('activa')->default(true); $t->unsignedInteger('orden')->default(0); $t->timestamps();
        });

        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        Role::create(['name' => 'User', 'slug' => 'user']);
        $this->admin = User::factory()->create(['role_id' => $admin->id]);
        config(['services.esnoticia.url' => 'https://backend.prueba.test/public', 'services.editus.ingest_token' => 'tok-int', 'services.editus.medios' => ['opanoticias' => 'Opanoticias', 'neiva24' => 'Neiva 24']]);
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake(['backend.prueba.test/public/api/editus/usuarios' => Http::response(['success' => true, 'usuarios' => [
            ['id' => 7, 'username' => 'willy', 'email' => 'w@x', 'role' => 'editor'],
            ['id' => 8, 'username' => 'Karol', 'email' => 'k@x', 'role' => 'admin'],
        ]], 200)]);
    }

    public function test_solo_admin_entra(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', 'user')->first()->id]);
        $this->actingAs($user)->get('/admin/app-editor')->assertRedirect(route('meta.pages.index'));
        $this->actingAs($user)->getJson('/admin/app-editor')->assertStatus(403);
        MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $this->actingAs($this->admin)->get('/admin/app-editor')->assertOk()->assertSee('App del editor')->assertSee('Todos los periodistas')->assertSee('willy')->assertSee('Karol');
        $this->actingAs($this->admin)->get('/admin/app-editor?tab=youtube')->assertOk()->assertSee('Canales de YouTube');
        $this->actingAs($this->admin)->get('/admin/app-editor/plantillas')->assertOk()->assertSee('Plantillas de imagen');
        // La lista de periodistas va con el token de integración
        Http::assertSent(fn($r) => str_contains($r->url(), '/api/editus/usuarios') && $r->header('X-Editus-Token')[0] === 'tok-int');
    }

    public function test_guarda_paginas_visibles_y_medio(): void
    {
        $a = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $b = MetaPage::create(['page_id' => '222', 'name' => 'Neiva 24']);
        $a->users()->attach($this->admin->id, ['page_access_token' => 'tok', 'is_active' => true]);

        $this->actingAs($this->admin)->post('/admin/app-editor/paginas', [
            'visible' => [$a->id],
            'medio' => [$a->id => ' OpaNoticias ', $b->id => 'neiva24'],
            'usuarios' => [$a->id => ['', 'Willy', 'karol'], $b->id => ['']],
        ])->assertRedirect('/admin/app-editor?tab=paginas');

        $this->assertTrue($a->fresh()->visible_en_editor);
        $this->assertSame('opanoticias', $a->fresh()->medio_slug);
        $this->assertSame(['willy', 'karol'], $a->fresh()->app_usuarios);
        $this->assertFalse($b->fresh()->visible_en_editor);
        $this->assertSame('neiva24', $b->fresh()->medio_slug);
        $this->assertSame([], $b->fresh()->app_usuarios, 'sin selección = ningún periodista');
        $this->assertFalse($b->fresh()->visibleParaUsuarioApp('willy'));
        $this->assertTrue($a->fresh()->visibleParaUsuarioApp('KAROL'));
        $this->assertFalse($a->fresh()->visibleParaUsuarioApp('bryan'));

        // "Todos" y texto libre (cuando no hay lista del backend)
        $this->actingAs($this->admin)->post('/admin/app-editor/paginas', ['visible' => [$a->id], 'usuarios' => [$a->id => ['', '*', 'willy'], $b->id => 'bryan, Karol']]);
        $this->assertSame(['*'], $a->fresh()->app_usuarios);
        $this->assertTrue($a->fresh()->visibleParaUsuarioApp('cualquiera'));
        $this->assertSame(['bryan', 'karol'], $b->fresh()->app_usuarios);

        // Las páginas conectadas desde la app se listan con su origen
        $c = MetaPage::create(['page_id' => '333', 'name' => 'Página de Willy']);
        \App\Models\MetaPageUser::create(['meta_page_id' => $c->id, 'user_id' => null, 'usuario_app' => '7', 'page_access_token' => 'tok-w', 'is_active' => 1]);
        $this->actingAs($this->admin)->get('/admin/app-editor')->assertOk()->assertSee('Página de Willy')->assertSee('@willy');
    }

    public function test_crea_edita_y_elimina_plantilla_con_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', [
            'nombre' => 'Judicial', 'logo' => UploadedFile::fake()->image('logo.png', 300, 100),
            'logo_texto' => 'OPA Noticias', 'logo_tintar' => 1, 'etiqueta' => 'Judicial', 'pie' => 'Opanoticias.com', 'hashtag' => '#EsNoticia',
            'color_titulo' => '#ffffff', 'color_logo' => '#ffd400', 'color_etiqueta' => '#0057b8', 'predeterminada' => 1, 'activa' => 1, 'orden' => 3,
        ])->assertRedirect('/admin/app-editor/plantillas')->assertSessionHas('success');

        $t = PlantillaEditor::first();
        $this->assertNotNull($t);
        $this->assertSame('#FFD400', $t->color_logo);
        $this->assertTrue($t->predeterminada);
        $this->assertNotNull($t->logo_path);
        Storage::disk('public')->assertExists($t->logo_path);

        // Otra predeterminada desmarca la anterior
        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', [
            'nombre' => 'Deportes', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#16A34A', 'predeterminada' => 1, 'activa' => 1,
        ]);
        $this->assertFalse($t->fresh()->predeterminada);
        $this->assertSame(1, PlantillaEditor::where('predeterminada', 1)->count());

        // Color inválido → error de validación
        $this->actingAs($this->admin)->from('/admin/app-editor/plantillas')->put("/admin/app-editor/plantillas/{$t->id}", [
            'nombre' => 'Judicial', 'color_titulo' => 'rojo', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#000000',
        ])->assertSessionHasErrors('color_titulo');

        // Quitar el logo
        $ruta = $t->logo_path;
        $this->actingAs($this->admin)->put("/admin/app-editor/plantillas/{$t->id}", [
            'nombre' => 'Judicial 2', 'quitar_logo' => 1, 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#000000', 'activa' => 1,
        ])->assertRedirect('/admin/app-editor/plantillas');
        $this->assertNull($t->fresh()->logo_path);
        Storage::disk('public')->assertMissing($ruta);

        $this->actingAs($this->admin)->delete("/admin/app-editor/plantillas/{$t->id}")->assertRedirect('/admin/app-editor/plantillas');
        $this->assertNull(PlantillaEditor::find($t->id));
    }

    /** PNG de prueba 108×135: fondo del color dado y una franja roja opaca abajo (el "marco"). */
    private function pngMarco(array $fondo, int $W = 108, int $H = 135): UploadedFile
    {
        $im = imagecreatetruecolor($W, $H);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$fondo));
        imagefilledrectangle($im, 0, (int) round($H * 0.815), $W - 1, $H - 1, imagecolorallocate($im, 171, 16, 16));
        ob_start(); imagepng($im); $b = ob_get_clean();
        $ruta = tempnam(sys_get_temp_dir(), 'marco') . '.png';
        file_put_contents($ruta, $b);
        return new UploadedFile($ruta, 'marco.png', 'image/png', null, true);
    }

    public function test_marco_de_canva_con_fondo_blanco_y_negro(): void
    {
        Storage::fake('public');
        $base = ['nombre' => 'Opa con título', 'tipo' => 'marco', 'medio_slug' => 'opanoticias', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E', 'activa' => '1', 'orden' => 1,
            'zonas' => json_encode(\App\Services\MarcosService::presets()['opa-titulo']['zonas'])];
        // Un PNG sin transparencia (blanco donde va la foto): se vuelve transparente el blanco desde la zona de la foto
        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', ['nombre' => 'Sin alfa'] + $base + ['marco' => $this->pngMarco([255, 255, 255])])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn($m) => str_contains($m, 'no tenía transparencia'));
        $sinAlfa = PlantillaEditor::where('nombre', 'Sin alfa')->first();
        $img = imagecreatefromstring(Storage::disk('public')->get($sinAlfa->marco_path));
        $this->assertSame([127, 0], [(imagecolorat($img, 500, 400) >> 24) & 0x7F, (imagecolorat($img, 500, 1250) >> 24) & 0x7F], 'el blanco queda transparente y la franja roja opaca');
        $sinAlfa->delete();
        // Solo una de las dos versiones: pide la otra
        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', $base + ['marco_blanco' => $this->pngMarco([255, 255, 255])])->assertSessionHasErrors('marco_negro');

        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', $base + ['marco_blanco' => $this->pngMarco([255, 255, 255]), 'marco_negro' => $this->pngMarco([0, 0, 0])])->assertSessionHasNoErrors()->assertRedirect();
        $t = PlantillaEditor::firstWhere('nombre', 'Opa con título');
        $this->assertTrue($t->esMarco());
        $png = imagecreatefromstring(Storage::disk('public')->get($t->marco_path));
        $this->assertSame([1080, 1350], [imagesx($png), imagesy($png)], 'el marco se lleva a 1080 × 1350');
        $this->assertSame(127, (imagecolorat($png, 500, 500) >> 24) & 0x7F, 'donde va la foto queda transparente');
        $this->assertSame(0, (imagecolorat($png, 500, 1250) >> 24) & 0x7F, 'la franja del marco queda opaca');
        $this->assertSame([171, 16, 16], [(imagecolorat($png, 500, 1250) >> 16) & 255, (imagecolorat($png, 500, 1250) >> 8) & 255, imagecolorat($png, 500, 1250) & 255]);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350], $t->zonas['foto']);

        $api = $this->withHeaders(['X-Editus-Token' => 'tok-int'])->getJson('/api/plantillas')->assertOk()->json('plantillas.0');
        $this->assertSame(['marco', 'opanoticias'], [$api['tipo'], $api['medio']]);
        $this->assertStringContainsString('plantillas-editor/marcos/', $api['marco_url']);
        $this->assertSame('bree', $api['zonas']['titulo']['fuente']);
        $this->actingAs($this->admin)->get('/admin/app-editor/plantillas?editar=' . $t->id)->assertOk()->assertSee('Marco de Canva')->assertSee('Opa Noticias · solo categoría');

        // Un diseño viejo con el PNG completo subido como logo se convierte en marco sin volver a subirlo
        $viejo = PlantillaEditor::create(['nombre' => 'Neiva 24', 'logo_path' => 'plantillas-editor/neiva.png', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E', 'activa' => true]);
        Storage::disk('public')->put('plantillas-editor/neiva.png', file_get_contents($this->pngMarco([255, 255, 255])->getRealPath()));
        $this->actingAs($this->admin)->put('/admin/app-editor/plantillas/' . $viejo->id, ['nombre' => 'Neiva 24', 'tipo' => 'marco', 'medio_slug' => 'neiva24', 'marco_desde_logo' => '1', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E', 'activa' => '1',
            'zonas' => json_encode(\App\Services\MarcosService::presets()['neiva24']['zonas'])])->assertSessionHasNoErrors();
        $this->assertTrue($viejo->fresh()->esMarco());
        $this->assertSame('neiva24', $viejo->fresh()->medio_slug);
    }

    public function test_un_marco_pequeno_de_canva_se_lleva_a_1080_y_conserva_las_medidas(): void
    {
        Storage::fake('public');
        $zonas = \App\Services\MarcosService::presets()['opa-titulo']['zonas'];
        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', ['nombre' => 'Opa con título', 'tipo' => 'marco', 'medio_slug' => 'opanoticias', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E', 'activa' => '1',
            'zonas' => json_encode($zonas), 'marco_blanco' => $this->pngMarco([255, 255, 255], 675, 844), 'marco_negro' => $this->pngMarco([0, 0, 0], 675, 844)])->assertSessionHasNoErrors();
        $t = PlantillaEditor::firstWhere('nombre', 'Opa con título');
        $this->assertSame([1080, 1350], array_slice(getimagesizefromstring(Storage::disk('public')->get($t->marco_path)), 0, 2));
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350], $t->zonas['foto']);
        $this->assertSame([81, 876, 416], [$t->zonas['etiqueta']['x'], $t->zonas['etiqueta']['y'], $t->zonas['etiqueta']['w']]);
        $this->assertSame(940, $t->zonas['titulo']['y']);
    }

    public function test_la_migracion_arregla_los_marcos_que_quedaron_pequenos(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plantillas-editor/marcos/viejo.png', file_get_contents($this->pngMarco([0, 0, 0], 675, 844)->getRealPath()));
        $t = PlantillaEditor::create(['nombre' => 'Opa solo categoría', 'tipo' => 'marco', 'marco_path' => 'plantillas-editor/marcos/viejo.png', 'medio_slug' => 'opanoticias',
            'zonas' => ['foto' => ['x' => 0, 'y' => 0, 'w' => 675, 'h' => 844], 'etiqueta' => null, 'titulo' => null], 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E']);
        (require database_path('migrations/2026_10_31_000002_marcos_a_1080.php'))->up();
        $t->refresh();
        $this->assertSame(1080, getimagesizefromstring(Storage::disk('public')->get($t->marco_path))[0]);
        $this->assertSame(['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1083], $t->zonas['foto'], 'medidas de «Opa solo categoría»');
        $this->assertSame(994, $t->zonas['etiqueta']['y']);
    }

    /** Marco transparente de 1080×1350 con banda oscura abajo y la caja roja fija de la categoría. */
    private function marcoConCaja(): string
    {
        $im = imagecreatetruecolor(1080, 1350);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, 0x7F000000);
        imagefilledrectangle($im, 0, 800, 1079, 1349, imagecolorallocatealpha($im, 10, 10, 10, 30));
        imagefilledrectangle($im, 81, 876, 497, 932, imagecolorallocate($im, 171, 16, 16));
        ob_start(); imagepng($im); return ob_get_clean();
    }

    public function test_la_caja_de_la_categoria_se_quita_del_marco_para_ajustarse_al_texto(): void
    {
        Storage::fake('public');
        $ruta = tempnam(sys_get_temp_dir(), 'marco') . '.png';
        file_put_contents($ruta, $this->marcoConCaja());
        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', ['nombre' => 'Opa con título', 'tipo' => 'marco', 'medio_slug' => 'opanoticias', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E', 'activa' => '1',
            'zonas' => json_encode(\App\Services\MarcosService::presets()['opa-titulo']['zonas']), 'marco' => new UploadedFile($ruta, 'marco.png', 'image/png', null, true)])->assertSessionHasNoErrors();
        $t = PlantillaEditor::firstWhere('nombre', 'Opa con título');
        $e = $t->zonas['etiqueta'];
        $this->assertTrue($e['ajustar']);
        $this->assertSame('izquierda', $e['alinear']);
        $this->assertMatchesRegularExpression('/^#A[89AB]1[0-3]1[0-3]$/', $e['fondo'], 'toma el color de la caja del marco');
        // La copia sin caja: donde estaba la caja queda la banda oscura; el original se conserva
        $limpio = imagecreatefromstring(Storage::disk('public')->get($t->marcoAjustadoPath()));
        $c = imagecolorat($limpio, 300, 900);
        $this->assertLessThan(40, ($c >> 16) & 255, 'ya no hay rojo en la zona de la categoría');
        $original = imagecreatefromstring(Storage::disk('public')->get($t->marco_path));
        $this->assertSame(171, (imagecolorat($original, 300, 900) >> 16) & 255);
        // La API entrega el marco sin caja y la caja con su color
        $api = $this->withHeaders(['X-Editus-Token' => 'tok-int'])->getJson('/api/plantillas')->json('plantillas.0');
        $this->assertStringContainsString('/ajustado-', $api['marco_url']);
        $this->assertSame($e['fondo'], $api['zonas']['etiqueta']['fondo']);

        // Si se desactiva, vuelve el marco tal cual
        $z = $t->zonas; $z['etiqueta']['ajustar'] = false; $z['etiqueta']['fondo'] = null;
        $this->actingAs($this->admin)->put('/admin/app-editor/plantillas/' . $t->id, ['nombre' => 'Opa con título', 'tipo' => 'marco', 'medio_slug' => 'opanoticias', 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E', 'activa' => '1', 'zonas' => json_encode($z)])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('/ajustado-', $t->fresh()->marcoUrl());
    }

    public function test_la_migracion_activa_la_caja_ajustable_en_los_marcos_ya_subidos(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('plantillas-editor/marcos/n24.png', $this->marcoConCaja());
        $zonas = \App\Services\MarcosService::presets()['neiva24']['zonas'];
        unset($zonas['etiqueta']['ajustar'], $zonas['etiqueta']['radio']);
        $zonas['etiqueta'] = ['x' => 81, 'y' => 876, 'w' => 416, 'h' => 56] + $zonas['etiqueta'];
        $t = PlantillaEditor::create(['nombre' => 'Neiva 24', 'tipo' => 'marco', 'marco_path' => 'plantillas-editor/marcos/n24.png', 'medio_slug' => 'neiva24', 'zonas' => $zonas, 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#C8102E']);
        (require database_path('migrations/2026_10_31_000003_marcos_caja_ajustable.php'))->up();
        $e = $t->fresh()->zonas['etiqueta'];
        $this->assertSame([true, 'izquierda', 31], [$e['ajustar'], $e['alinear'], $e['radio']]);
        $this->assertNotEmpty($e['fondo']);
        $this->assertTrue(Storage::disk('public')->exists($t->fresh()->marcoAjustadoPath()));
    }
}
