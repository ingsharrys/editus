<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** php artisan editus:revisor-meta: rol y usuario de prueba para la revisión de Meta. */
class RevisorMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->text('page_access_token')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        (require database_path('migrations/2026_10_13_000001_create_suscripciones.php'))->up();
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        (require database_path('migrations/2026_10_21_000001_idiomas_y_pagos_internacionales.php'))->up();
    }

    public function test_crea_el_rol_y_el_usuario_revisor_y_lo_desactiva(): void
    {
        $demo = User::factory()->create(['email' => 'admin@example.com', 'password' => Hash::make('12345678')]);
        $this->artisan('editus:revisor-meta', ['--cambiar-clave-demo' => true])->expectsOutputToContain('Password:')->assertSuccessful();

        $rol = Role::where('slug', 'meta-reviewer')->firstOrFail();
        $u = User::where('email', 'meta.review@editus.online')->firstOrFail();
        $this->assertSame($rol->id, $u->role_id);
        $this->assertTrue($u->tienePermiso('paginas.publicar') && $u->tienePermiso('en_vivo') && $u->tienePermiso('estadisticas') && $u->tienePermiso('inteligencia'));
        $this->assertFalse($u->tienePermiso('usuarios') || $u->tienePermiso('roles') || $u->tienePermiso('suscripciones_admin'));
        $this->assertSame('en', $u->idioma);
        $this->assertTrue((bool) $u->exento_planes);
        $this->assertFalse(Hash::check('12345678', $demo->fresh()->password));

        // Puede entrar, ve el panel en inglés y no puede administrar usuarios
        $this->actingAs($u)->get('/profile')->assertOk()->assertSee('My profile');
        $this->actingAs($u)->get('/admin/usuarios')->assertForbidden();

        // Repetir no duplica; --desactivar le quita el acceso
        $this->artisan('editus:revisor-meta')->assertSuccessful();
        $this->assertSame(1, User::where('email', 'meta.review@editus.online')->count());
        $this->artisan('editus:revisor-meta', ['--desactivar' => true])->assertSuccessful();
        $this->assertFalse($u->fresh()->estaActivo());
    }
}
