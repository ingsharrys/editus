<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\PlantillaEditor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
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
            $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps();
        });
        Schema::create('meta_page_user', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->foreignId('social_account_id')->nullable();
            $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('plantillas_editor', function (Blueprint $t) {
            $t->id(); $t->string('nombre', 120); $t->string('logo_path')->nullable(); $t->string('logo_texto', 60)->nullable(); $t->boolean('logo_tintar')->default(true);
            $t->string('etiqueta', 40)->nullable(); $t->string('pie', 80)->nullable(); $t->string('hashtag', 60)->nullable();
            $t->string('color_titulo', 7)->default('#FFFFFF'); $t->string('color_logo', 7)->default('#FFFFFF'); $t->string('color_etiqueta', 7)->default('#C8102E');
            $t->boolean('predeterminada')->default(false); $t->boolean('activa')->default(true); $t->unsignedInteger('orden')->default(0); $t->timestamps();
        });

        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        Role::create(['name' => 'User', 'slug' => 'user']);
        $this->admin = User::factory()->create(['role_id' => $admin->id]);
    }

    public function test_solo_admin_entra(): void
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', 'user')->first()->id]);
        $this->actingAs($user)->get('/admin/app-editor')->assertStatus(403);
        $this->actingAs($this->admin)->get('/admin/app-editor')->assertOk()->assertSee('App del editor');
    }

    public function test_guarda_paginas_visibles_y_medio(): void
    {
        $a = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $b = MetaPage::create(['page_id' => '222', 'name' => 'Neiva 24']);
        $a->users()->attach($this->admin->id, ['page_access_token' => 'tok', 'is_active' => true]);

        $this->actingAs($this->admin)->post('/admin/app-editor/paginas', [
            'visible' => [$a->id],
            'medio' => [$a->id => ' OpaNoticias ', $b->id => 'neiva24'],
        ])->assertRedirect('/admin/app-editor');

        $this->assertTrue($a->fresh()->visible_en_editor);
        $this->assertSame('opanoticias', $a->fresh()->medio_slug);
        $this->assertFalse($b->fresh()->visible_en_editor);
        $this->assertSame('neiva24', $b->fresh()->medio_slug);
    }

    public function test_crea_edita_y_elimina_plantilla_con_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/admin/app-editor/plantillas', [
            'nombre' => 'Judicial', 'logo' => UploadedFile::fake()->image('logo.png', 300, 100),
            'logo_texto' => 'OPA Noticias', 'logo_tintar' => 1, 'etiqueta' => 'Judicial', 'pie' => 'Opanoticias.com', 'hashtag' => '#EsNoticia',
            'color_titulo' => '#ffffff', 'color_logo' => '#ffd400', 'color_etiqueta' => '#0057b8', 'predeterminada' => 1, 'activa' => 1, 'orden' => 3,
        ])->assertRedirect('/admin/app-editor')->assertSessionHas('success');

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
        $this->actingAs($this->admin)->from('/admin/app-editor')->put("/admin/app-editor/plantillas/{$t->id}", [
            'nombre' => 'Judicial', 'color_titulo' => 'rojo', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#000000',
        ])->assertSessionHasErrors('color_titulo');

        // Quitar el logo
        $ruta = $t->logo_path;
        $this->actingAs($this->admin)->put("/admin/app-editor/plantillas/{$t->id}", [
            'nombre' => 'Judicial 2', 'quitar_logo' => 1, 'color_titulo' => '#FFFFFF', 'color_logo' => '#FFFFFF', 'color_etiqueta' => '#000000', 'activa' => 1,
        ])->assertRedirect('/admin/app-editor');
        $this->assertNull($t->fresh()->logo_path);
        Storage::disk('public')->assertMissing($ruta);

        $this->actingAs($this->admin)->delete("/admin/app-editor/plantillas/{$t->id}")->assertRedirect('/admin/app-editor');
        $this->assertNull(PlantillaEditor::find($t->id));
    }
}
