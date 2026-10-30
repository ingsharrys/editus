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

/** Redes en la web de editus: lo mismo que la sección Redes de la app, por medio del backend de esnoticia. */
class RedesWebTest extends TestCase
{
    private User $admin;
    private const B = 'backend.prueba.test/public/api/';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->text('permisos')->nullable(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable();
            $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps();
        });
        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin']);
        Role::create(['name' => 'User', 'slug' => 'user']);
        $this->admin = User::factory()->create(['role_id' => $admin->id, 'email' => 'Karol@X', 'name' => 'Karol M']);
        config(['services.esnoticia.url' => 'https://backend.prueba.test/public', 'services.editus.ingest_token' => 'tok-int']);
        Cache::flush();
    }

    private function falsos(array $mas = []): void
    {
        Http::fake($mas + [
            self::B . 'editus/usuarios' => Http::response(['success' => true, 'usuarios' => [
                ['id' => 7, 'username' => 'willy', 'email' => 'w@x', 'role' => 'editor'],
                ['id' => 8, 'username' => 'Karol', 'email' => 'karol@x', 'role' => 'admin'],
            ]], 200),
            self::B . 'test/categories' => Http::response(['success' => true, 'sitios' => [
                ['sitio_id' => 1, 'sitio_nombre' => 'Opanoticias', 'linea_nombre' => 'Línea Profesional', 'slug' => 'opanoticias', 'tipo' => 'laravel', 'url' => 'https://opanoticias.com', 'categorias' => [['nombre' => 'Opas', 'wordpress_category_id' => 53]]],
                ['sitio_id' => 99, 'sitio_nombre' => 'Viejo WP', 'linea_nombre' => 'X', 'slug' => 'viejo', 'tipo' => 'wordpress', 'categorias' => []],
            ]], 200),
            self::B . 'redes/paginas*' => Http::response(['success' => true, 'categoria' => 'Opas', 'paginas' => [['id' => 3, 'page_id' => '111', 'nombre' => 'Opa FB', 'instagram' => true, 'medio' => 'opanoticias']], 'error' => null], 200),
            self::B . 'redes/plantillas' => Http::response(['success' => true, 'plantillas' => [['id' => 'sistema', 'nombre' => 'Opa Noticias', 'origen' => 'sistema', 'predeterminada' => true]]], 200),
            self::B . 'redes/publicar' => Http::response(['success' => true, 'titulo' => 'Hola', 'resultados' => ['web' => [['medio' => 'Opanoticias', 'ok' => true, 'url' => 'https://opanoticias.com/n']], 'paginas' => []]], 200),
            self::B . 'redes/historial' => Http::response(['success' => true, 'posts' => [['id' => 1, 'titulo' => 'Antes', 'texto' => 't', 'estado' => 'publicado', 'resultados' => [], 'creado_en' => '2026-10-30 10:00:00']]], 200),
        ]);
    }

    public function test_la_pagina_propone_el_periodista_por_correo_y_pide_permiso(): void
    {
        $this->falsos();
        $r = $this->actingAs($this->admin)->get(route('redes.index'))->assertOk()
            ->assertSee('Firma la nota (periodista)')->assertSee('willy')->assertSee('Campaña Esnoticia')->assertSee('Ya en redes');
        $this->assertStringContainsString("'8'", $r->getContent(), 'propone a Karol (mismo correo)');

        $comun = User::factory()->create(['role_id' => Role::where('slug', 'user')->value('id')]);
        $this->actingAs($comun)->get(route('redes.index'))->assertForbidden();
        $this->actingAs($comun)->get(route('redes.catalogos'))->assertForbidden();
    }

    public function test_catalogos_y_publicar_pasan_al_backend_con_el_periodista(): void
    {
        $this->falsos();
        // Sin periodista válido no se llama al backend en su nombre
        $this->actingAs($this->admin)->getJson(route('redes.catalogos'), ['X-Autor' => '55'])->assertStatus(422)->assertJsonPath('error', 'Elige el periodista que firma la nota.');

        $c = $this->actingAs($this->admin)->getJson(route('redes.catalogos'), ['X-Autor' => '7'])->assertOk()->json();
        $this->assertSame(['opanoticias'], array_column($c['medios'], 'slug'), 'solo los medios que publican por Laravel');
        $this->assertSame(53, $c['medios'][0]['categorias'][0]['id']);
        $this->assertSame('111', $c['paginas'][0]['page_id']);
        $this->assertSame('Opa Noticias', $c['plantillas'][0]['nombre']);
        Http::assertSent(fn(PeticionHttp $p) => str_contains($p->url(), 'redes/paginas') && $p->header('X-Editus-Token') === ['tok-int'] && $p->header('X-Editus-Usuario') === ['7']);

        $this->actingAs($this->admin)->postJson(route('redes.publicar'), ['titulo' => 'Hola', 'texto' => 'Texto', 'imagen' => 'data:image/jpeg;base64,AAA', 'medios' => [['slug' => 'opanoticias']], 'campaign_id' => 4], ['X-Autor' => '7'])
            ->assertOk()->assertJsonPath('resultados.web.0.url', 'https://opanoticias.com/n');
        Http::assertSent(fn(PeticionHttp $p) => str_ends_with($p->url(), 'redes/publicar') && $p['titulo'] === 'Hola' && !isset($p['campaign_id']) && $p->header('X-Editus-Usuario') === ['7']);

        $this->actingAs($this->admin)->getJson(route('redes.historial'), ['X-Autor' => '8'])->assertOk()->assertJsonPath('posts.0.titulo', 'Antes');
    }

    public function test_avisa_si_el_backend_no_acepta_la_conexion(): void
    {
        $this->falsos([self::B . 'redes/historial' => Http::response(['success' => false, 'error' => 'No autorizado'], 401)]);
        $this->actingAs($this->admin)->getJson(route('redes.historial'), ['X-Autor' => '7'])->assertStatus(401)
            ->assertJsonPath('success', false)->assertSee('EDITUS_TOKEN del backend');

        config(['services.editus.ingest_token' => '']);
        $this->actingAs($this->admin)->get(route('redes.index'))->assertOk()->assertSee('Falta conectar editus con el backend de esnoticia');
    }
}
