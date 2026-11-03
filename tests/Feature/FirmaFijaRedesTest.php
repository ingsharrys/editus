<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Usuario de editus asociado a un periodista de esnoticia: en Redes publica siempre con su firma. */
class FirmaFijaRedesTest extends TestCase
{
    private User $admin;
    private const B = 'backend.prueba.test/public/api/';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable();
            $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps();
        });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->text('picture_url')->nullable(); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->text('page_access_token')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Role::query()->insert([['name' => 'Admin', 'slug' => 'admin'], ['name' => 'User', 'slug' => 'user']]);
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        (require database_path('migrations/2026_11_03_000001_firma_esnoticia_en_usuarios.php'))->up();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'name' => 'Admin']);
        config(['services.esnoticia.url' => 'https://backend.prueba.test/public', 'services.editus.ingest_token' => 'tok-int']);
        Cache::flush();
        Http::fake([
            self::B . 'editus/usuarios' => Http::response(['success' => true, 'usuarios' => [
                ['id' => 6, 'username' => 'jcpuentes', 'email' => 'jc@x', 'role' => 'periodista', 'nombre' => 'Jorge Camilo Puentes'],
                ['id' => 11, 'username' => 'ulpiano', 'email' => 'u@x', 'role' => 'periodista', 'nombre' => 'Ulpiano'],
            ]], 200),
            self::B . 'test/categories' => Http::response(['success' => true, 'sitios' => []], 200),
            self::B . 'redes/paginas*' => Http::response(['success' => true, 'paginas' => []], 200),
            self::B . 'redes/plantillas' => Http::response(['success' => true, 'plantillas' => []], 200),
            self::B . 'redes/historial' => Http::response(['success' => true, 'posts' => []], 200),
        ]);
    }

    public function test_crear_usuario_de_redes_asociado_al_periodista_y_publicar_solo_con_su_firma(): void
    {
        $rol = Role::where('slug', 'periodista-redes')->firstOrFail();
        $this->assertSame(['redes'], $rol->listaPermisos());

        // El formulario ofrece los periodistas de esnoticia
        $this->actingAs($this->admin)->get(route('usuarios.index'))->assertOk()->assertSee('Periodista de esnoticia (firma en Redes)')->assertSee('Jorge Camilo Puentes');
        // Un periodista que no existe en esnoticia no se acepta
        $this->actingAs($this->admin)->post(route('usuarios.store'), ['name' => 'X', 'email' => 'x@x.co', 'role_id' => $rol->id, 'password' => 'clave-segura-1', 'esnoticia_usuario_id' => 99])
            ->assertSessionHasErrors('esnoticia_usuario_id');
        $this->actingAs($this->admin)->post(route('usuarios.store'), ['name' => 'Jorge Redes', 'email' => 'jorge.redes@x.co', 'role_id' => $rol->id, 'password' => 'clave-segura-1', 'activo' => 1, 'esnoticia_usuario_id' => 6])
            ->assertRedirect(route('usuarios.index'));
        $u = User::where('email', 'jorge.redes@x.co')->firstOrFail();
        $this->assertSame(6, $u->firmaEsnoticia());
        $this->actingAs($this->admin)->get(route('usuarios.index'))->assertSee('Firma en Redes: Jorge Camilo Puentes');

        // Al entrar va directo a Redes y la firma es fija (no hay selector)
        $this->assertSame('redes.index', $u->fresh()->rutaInicio());
        $this->actingAs($u)->get(route('redes.index'))->assertOk()->assertSee('Jorge Camilo Puentes')->assertDontSee('— Elige el periodista —');
        $this->actingAs($u)->get(route('meta.pages.index'))->assertRedirect(route('redes.index')); // no entra a «Publicar»

        // Aunque el navegador pida otra firma, el backend recibe la del periodista asociado
        $this->actingAs($u)->getJson(route('redes.catalogos'), ['X-Autor' => '11'])->assertOk();
        Http::assertSent(fn(PeticionHttp $p) => str_contains($p->url(), 'redes/paginas') && $p->header('X-Editus-Usuario') === ['6']);
        Http::assertNotSent(fn(PeticionHttp $p) => $p->header('X-Editus-Usuario') === ['11']);

        // Al quitar la asociación vuelve a elegir el periodista al publicar
        $this->actingAs($this->admin)->put(route('usuarios.update', $u), ['name' => 'Jorge Redes', 'email' => 'jorge.redes@x.co', 'role_id' => $rol->id, 'esnoticia_usuario_id' => ''])->assertRedirect();
        $this->assertNull($u->fresh()->firmaEsnoticia());
        $this->actingAs($u->fresh())->get(route('redes.index'))->assertSee('— Elige el periodista —');
    }
}
