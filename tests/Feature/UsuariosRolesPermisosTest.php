<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Usuarios, roles con permisos por módulo y perfil propio. */
class UsuariosRolesPermisosTest extends TestCase
{
    private Role $rolAdmin;
    private Role $rolUser;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->text('page_access_token')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('sessions', function (Blueprint $t) { $t->string('id')->primary(); $t->foreignId('user_id')->nullable(); $t->string('ip_address', 45)->nullable(); $t->text('user_agent')->nullable(); $t->longText('payload'); $t->integer('last_activity'); });
        Role::query()->insert([['name' => 'Admin', 'slug' => 'admin'], ['name' => 'User', 'slug' => 'user']]);
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        $this->rolAdmin = Role::where('slug', 'admin')->first();
        $this->rolUser = Role::where('slug', 'user')->first();
        $this->admin = User::factory()->create(['name' => 'Dario Charry', 'email' => 'dario@prueba.co', 'role_id' => $this->rolAdmin->id]);
        Storage::fake('public');
    }

    private function rol(string $nombre, array $permisos): Role
    {
        return Role::create(['name' => $nombre, 'slug' => \Illuminate\Support\Str::slug($nombre), 'permisos' => $permisos]);
    }

    public function test_la_migracion_da_al_rol_user_los_permisos_de_siempre(): void
    {
        $this->assertTrue((bool) $this->rolAdmin->sistema);
        $this->assertTrue((bool) $this->rolUser->sistema);
        $this->assertSame(config('permisos.por_defecto'), $this->rolUser->listaPermisos());

        $normal = User::factory()->create(['role_id' => $this->rolUser->id]);
        $this->assertTrue($normal->tienePermiso('en_vivo'));
        $this->assertFalse($normal->tienePermiso('usuarios'));
        $this->assertFalse($normal->tienePermiso('informe_admin'));
        $this->assertTrue($this->admin->tienePermiso('usuarios'));
        $this->assertSame(Role::claves(), $this->admin->permisos());

        $sinRol = User::factory()->create(['role_id' => null]);
        $this->assertTrue($sinRol->tienePermiso('paginas'));
        $this->assertFalse($sinRol->tienePermiso('roles'));
    }

    public function test_el_middleware_de_permisos_bloquea_los_modulos_que_el_rol_no_tiene(): void
    {
        $periodista = User::factory()->create(['role_id' => $this->rol('Periodista', ['mis_publicaciones'])->id]);
        $this->actingAs($periodista)->get('/en-vivo')->assertForbidden();
        $this->actingAs($periodista)->get('/admin/usuarios')->assertForbidden();
        $this->actingAs($periodista)->get('/admin/roles')->assertForbidden();
        $this->actingAs($periodista)->get('/meta/pages')->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/usuarios')->assertOk()->assertSee('Usuarios')->assertSee('Dario Charry');
        $this->actingAs($this->admin)->get('/admin/roles')->assertOk()->assertSee('Roles y permisos')->assertSee('Mis páginas');
    }

    public function test_el_menu_solo_muestra_lo_que_el_rol_permite(): void
    {
        $periodista = User::factory()->create(['role_id' => $this->rol('Periodista', [])->id]);
        $html = $this->actingAs($periodista)->get('/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString(route('usuarios.index'), $html);
        $this->assertStringNotContainsString(route('en-vivo.web'), $html);
        $this->assertStringContainsString('Tu rol todavía no tiene permisos', $html);

        $html = $this->actingAs($this->admin)->get('/profile')->assertOk()->getContent();
        $this->assertStringContainsString(route('usuarios.index'), $html);
        $this->assertStringContainsString(route('roles.index'), $html);
    }

    public function test_crear_editar_desactivar_y_borrar_usuarios(): void
    {
        $this->actingAs($this->admin)->post('/admin/usuarios', ['name' => 'Ana Pérez', 'email' => 'Ana@Prueba.co', 'role_id' => $this->rolUser->id, 'cargo' => 'Periodista', 'activo' => 1])
            ->assertRedirect(route('usuarios.index'))->assertSessionHas('clave_temporal');
        $ana = User::where('email', 'ana@prueba.co')->firstOrFail();
        $this->assertSame('Periodista', $ana->cargo);
        $this->assertTrue(Hash::check(session('clave_temporal'), $ana->password));

        $this->actingAs($this->admin)->put("/admin/usuarios/{$ana->id}", ['name' => 'Ana María', 'email' => 'ana@prueba.co', 'role_id' => $this->rolAdmin->id, 'password' => 'clave-nueva-123'])->assertRedirect();
        $ana->refresh();
        $this->assertSame('Ana María', $ana->name);
        $this->assertTrue($ana->isAdmin());
        $this->assertTrue(Hash::check('clave-nueva-123', $ana->password));

        $this->actingAs($this->admin)->post('/admin/usuarios', ['name' => 'Otra', 'email' => 'ana@prueba.co', 'role_id' => $this->rolUser->id])->assertSessionHasErrors('email');

        $this->actingAs($this->admin)->post("/admin/usuarios/{$ana->id}/activo")->assertRedirect();
        $this->assertFalse($ana->refresh()->estaActivo());
        $this->actingAs($this->admin)->post("/admin/usuarios/{$ana->id}/activo");
        $this->assertTrue($ana->refresh()->estaActivo());

        $this->actingAs($this->admin)->delete("/admin/usuarios/{$ana->id}")->assertRedirect();
        $this->assertNull(User::find($ana->id));
    }

    public function test_siempre_queda_un_administrador_y_nadie_se_cambia_su_propio_rol(): void
    {
        $this->actingAs($this->admin)->put("/admin/usuarios/{$this->admin->id}", ['name' => 'Dario', 'email' => 'dario@prueba.co', 'role_id' => $this->rolUser->id])->assertSessionHasErrors('role_id');
        $this->actingAs($this->admin)->post("/admin/usuarios/{$this->admin->id}/activo")->assertSessionHasErrors('activo');
        $this->actingAs($this->admin)->delete("/admin/usuarios/{$this->admin->id}")->assertSessionHasErrors('borrar');
        $this->assertTrue($this->admin->refresh()->isAdmin());

        $this->actingAs($this->admin)->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('password', null, 'userDeletion');
        $this->assertNotNull(User::find($this->admin->id));
    }

    public function test_quien_no_es_admin_no_puede_escalar_privilegios(): void
    {
        $gestor = User::factory()->create(['role_id' => $this->rol('Gestor', ['usuarios', 'roles', 'paginas', 'en_vivo'])->id]);
        $amplio = $this->rol('Analista', ['estadisticas', 'informe_admin']);
        $chico = $this->rol('Camarógrafo', ['en_vivo']);

        // No asigna el rol admin ni un rol con permisos que él no tiene
        $this->actingAs($gestor)->post('/admin/usuarios', ['name' => 'X', 'email' => 'x@prueba.co', 'role_id' => $this->rolAdmin->id])->assertForbidden();
        $this->actingAs($gestor)->post('/admin/usuarios', ['name' => 'Y', 'email' => 'y@prueba.co', 'role_id' => $amplio->id])->assertForbidden();
        $this->actingAs($gestor)->post('/admin/usuarios', ['name' => 'Z', 'email' => 'z@prueba.co', 'role_id' => $chico->id])->assertRedirect();
        $this->assertTrue(User::where('email', 'z@prueba.co')->exists());

        // No toca a los administradores
        $this->actingAs($gestor)->put("/admin/usuarios/{$this->admin->id}", ['name' => 'Hack', 'email' => 'dario@prueba.co', 'role_id' => $chico->id])->assertForbidden();
        $this->actingAs($gestor)->delete("/admin/usuarios/{$this->admin->id}")->assertForbidden();
        $this->assertSame('Dario Charry', $this->admin->refresh()->name);

        // Al crear un rol solo pasan los permisos que él tiene
        $this->actingAs($gestor)->post('/admin/roles', ['name' => 'Editor', 'permisos' => ['paginas', 'suscripciones_admin', 'usuarios']])->assertRedirect();
        $this->assertSame(['paginas', 'usuarios'], Role::where('name', 'Editor')->first()->listaPermisos());

        // No edita su propio rol ni uno con más permisos que él
        $this->actingAs($gestor)->put("/admin/roles/{$gestor->role_id}", ['name' => 'Gestor', 'permisos' => ['informe_admin']])->assertForbidden();
        $this->actingAs($gestor)->put("/admin/roles/{$amplio->id}", ['name' => 'Analista', 'permisos' => []])->assertForbidden();
    }

    public function test_crear_editar_y_borrar_roles(): void
    {
        $this->actingAs($this->admin)->post('/admin/roles', ['name' => 'Periodista', 'descripcion' => 'Sala de redacción', 'permisos' => ['en_vivo', 'mis_publicaciones', 'no_existe']])
            ->assertSessionHasErrors('permisos.2');
        $this->actingAs($this->admin)->post('/admin/roles', ['name' => 'Periodista', 'descripcion' => 'Sala de redacción', 'permisos' => ['en_vivo', 'mis_publicaciones']])->assertRedirect(route('roles.index'));
        $rol = Role::where('name', 'Periodista')->firstOrFail();
        $this->assertSame('periodista', $rol->slug);
        $this->assertEqualsCanonicalizing(['en_vivo', 'mis_publicaciones'], $rol->listaPermisos());
        $this->actingAs($this->admin)->post('/admin/roles', ['name' => 'Periodista'])->assertSessionHasErrors('name');

        $this->actingAs($this->admin)->put("/admin/roles/{$rol->id}", ['name' => 'Periodista', 'permisos' => ['estadisticas']])->assertRedirect();
        $this->assertSame(['estadisticas'], $rol->refresh()->listaPermisos());

        // El rol admin y los del sistema no se cambian ni se borran
        $this->actingAs($this->admin)->put("/admin/roles/{$this->rolAdmin->id}", ['name' => 'Admin', 'permisos' => []])->assertSessionHasErrors('rol');
        $this->assertTrue($this->admin->refresh()->tienePermiso('roles'));
        $this->actingAs($this->admin)->delete("/admin/roles/{$this->rolUser->id}")->assertSessionHasErrors('rol');

        // Con usuarios no se borra; vacío sí
        $u = User::factory()->create(['role_id' => $rol->id]);
        $this->actingAs($this->admin)->delete("/admin/roles/{$rol->id}")->assertSessionHasErrors('rol');
        $u->forceFill(['role_id' => $this->rolUser->id])->save();
        $this->actingAs($this->admin)->delete("/admin/roles/{$rol->id}")->assertRedirect();
        $this->assertNull(Role::find($rol->id));

        // Los cambios de permisos aplican al instante
        $rol2 = $this->rol('Invitado', []);
        $p = User::factory()->create(['role_id' => $rol2->id]);
        $this->actingAs($p)->get('/admin/usuarios')->assertForbidden();
        $this->actingAs($this->admin)->put("/admin/roles/{$rol2->id}", ['name' => 'Invitado', 'permisos' => ['usuarios']]);
        $this->actingAs($p->fresh())->get('/admin/usuarios')->assertOk();
    }

    public function test_un_usuario_desactivado_no_entra_y_pierde_la_sesion(): void
    {
        $u = User::factory()->create(['email' => 'ana@prueba.co', 'role_id' => $this->rolUser->id, 'activo' => false]);
        $this->post('/login', ['email' => 'ana@prueba.co', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($u)->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_el_perfil_guarda_foto_cargo_y_telefono(): void
    {
        $this->actingAs($this->admin)->get('/profile')->assertOk()->assertSee('Mi perfil')->assertSee('DC');
        $this->assertNotNull($this->admin->refresh()->ultimo_acceso);

        $this->actingAs($this->admin)->patch('/profile', ['name' => 'Dario Charry', 'email' => 'dario@prueba.co', 'cargo' => 'Director', 'telefono' => '+57 300 1112233',
            'avatar' => UploadedFile::fake()->image('yo.jpg', 400, 400)])->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->admin->refresh();
        $this->assertSame('Director', $this->admin->cargo);
        $this->assertSame('+57 300 1112233', $this->admin->telefono);
        Storage::disk('public')->assertExists($primera = $this->admin->avatar);
        $this->assertStringStartsWith('avatares/', $primera);

        $this->actingAs($this->admin)->patch('/profile', ['name' => 'Dario Charry', 'email' => 'dario@prueba.co', 'avatar' => UploadedFile::fake()->image('otra.png')]);
        Storage::disk('public')->assertMissing($primera);
        Storage::disk('public')->assertExists($segunda = $this->admin->refresh()->avatar);
        $this->actingAs($this->admin)->get('/profile')->assertSee(Storage::disk('public')->url($segunda));

        $this->actingAs($this->admin)->patch('/profile', ['name' => 'Dario Charry', 'email' => 'dario@prueba.co', 'quitar_avatar' => 1]);
        $this->assertNull($this->admin->refresh()->avatar);
        Storage::disk('public')->assertMissing($segunda);

        $this->actingAs($this->admin)->patch('/profile', ['name' => 'Dario', 'email' => 'dario@prueba.co', 'avatar' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])->assertSessionHasErrors('avatar');
        // El perfil no permite cambiarse el rol
        $this->actingAs($this->admin)->patch('/profile', ['name' => 'Dario', 'email' => 'dario@prueba.co', 'role_id' => $this->rolUser->id, 'activo' => 0]);
        $this->assertTrue($this->admin->refresh()->isAdmin());
    }
}
