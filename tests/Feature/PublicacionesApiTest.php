<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API de integración con el backend de esnoticia (sección Redes):
 * listar páginas conectadas y publicar una foto en Facebook / Instagram.
 */
class PublicacionesApiTest extends TestCase
{
    private const TOKEN = 'token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.ingest_token' => self::TOKEN]);
        config(['services.facebook.version' => 'v23.0']);
        // Las migraciones del proyecto usan ALTER ... MODIFY (solo MySQL), así
        // que el esquema mínimo se crea a mano sobre SQLite en memoria.
        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
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
            $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->timestamps();
        });
        Schema::create('meta_page_user', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->foreignId('social_account_id')->nullable();
            $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('meta_posts', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->uuid('batch_uuid')->nullable(); $t->string('type');
            $t->text('message')->nullable(); $t->string('link')->nullable(); $t->json('local_media')->nullable(); $t->json('fb_media_ids')->nullable();
            $t->string('fb_post_id')->nullable(); $t->string('fb_permalink_url')->nullable(); $t->string('status')->default('pending');
            $t->unsignedInteger('alcance')->nullable(); $t->unsignedInteger('visualizaciones')->nullable(); $t->unsignedInteger('interacciones')->nullable();
            $t->string('evidencia_path')->nullable(); $t->timestamp('last_insights_at')->nullable(); $t->text('error')->nullable();
            $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
    }

    private function paginaConectada(string $pageId = '111', ?string $ig = '222', bool $activa = true, ?string $token = 'tok-pagina'): MetaPage
    {
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $rol->id]);
        $page = MetaPage::create(['page_id' => $pageId, 'name' => 'Página ' . $pageId, 'instagram_business_account_id' => $ig]);
        $page->users()->attach($user->id, ['page_access_token' => $token, 'is_active' => $activa]);
        return $page;
    }

    public function test_rechaza_sin_token(): void
    {
        $this->getJson('/api/paginas')->assertStatus(401);
        $this->withHeader('X-Editus-Token', 'otro')->getJson('/api/paginas')->assertStatus(401);
    }

    public function test_lista_solo_paginas_con_token_activo(): void
    {
        $this->paginaConectada('111', '222');
        $this->paginaConectada('333', null, activa: false);

        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/paginas');
        $r->assertOk()->assertJsonPath('success', true)->assertJsonCount(1, 'paginas');
        $r->assertJsonPath('paginas.0.page_id', '111')
          ->assertJsonPath('paginas.0.instagram', true)
          ->assertJsonPath('paginas.0.nombre', 'Página 111');
    }

    public function test_publica_foto_en_facebook_e_instagram(): void
    {
        $page = $this->paginaConectada('111', '222');

        $intentos = 0;
        Http::fake([
            'graph.facebook.com/v23.0/111/photos' => Http::response(['id' => '555', 'post_id' => '111_555'], 200),
            'graph.facebook.com/v23.0/111_555*' => Http::response(['permalink_url' => 'https://www.facebook.com/111/posts/555'], 200),
            'graph.facebook.com/v23.0/222/media' => Http::response(['id' => '999'], 200),
            'graph.facebook.com/v23.0/222/media_publish' => function () use (&$intentos) {
                $intentos++;
                // Primer intento: el contenedor aún no está listo (9007)
                return $intentos === 1
                    ? Http::response(['error' => ['message' => 'Media ID is not available', 'code' => 9007]], 400)
                    : Http::response(['id' => '18000'], 200);
            },
            'graph.facebook.com/v23.0/18000*' => Http::response(['permalink' => 'https://www.instagram.com/p/ABC/'], 200),
        ]);

        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/publicaciones/foto', [
            'texto' => "Atención Neiva: cierre de la carrera 5.\n\n#Neiva",
            'imagen_url' => 'https://backend.esnoticia.org/public/redes/imagenes/post-1.jpg',
            'paginas' => [
                ['id' => $page->id, 'facebook' => true, 'instagram' => true, 'enlace' => 'https://opanoticias.com/nota-77/77'],
            ],
        ]);

        $r->assertOk()
          ->assertJsonPath('success', true)
          ->assertJsonPath('publicadas', 1)
          ->assertJsonPath('resultados.0.pagina', 'Página 111')
          ->assertJsonPath('resultados.0.facebook.ok', true)
          ->assertJsonPath('resultados.0.facebook.post_id', '111_555')
          ->assertJsonPath('resultados.0.facebook.permalink', 'https://www.facebook.com/111/posts/555')
          ->assertJsonPath('resultados.0.instagram.ok', true)
          ->assertJsonPath('resultados.0.instagram.permalink', 'https://www.instagram.com/p/ABC/');

        // Lo que se envió a Meta: foto por URL + texto con el enlace al final
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/111/photos')
                && $req['url'] === 'https://backend.esnoticia.org/public/redes/imagenes/post-1.jpg'
                && str_ends_with($req['message'], "#Neiva\n\nhttps://opanoticias.com/nota-77/77")
                && $req['access_token'] === 'tok-pagina';
        });
        Http::assertSent(fn($req) => str_contains($req->url(), '/222/media') && !str_contains($req->url(), 'publish')
            && $req['image_url'] === 'https://backend.esnoticia.org/public/redes/imagenes/post-1.jpg'
            && str_contains($req['caption'], 'https://opanoticias.com/nota-77/77'));
        $this->assertSame(2, $intentos, 'media_publish se reintenta tras el 9007');

        // Queda registrado en editus como post de foto exitoso
        $post = MetaPost::first();
        $this->assertNotNull($post);
        $this->assertSame('photo', $post->type);
        $this->assertSame('success', $post->status);
        $this->assertSame('111_555', $post->fb_post_id);
        $this->assertSame('https://opanoticias.com/nota-77/77', $post->link);
        $this->assertSame($page->id, $post->meta_page_id);
    }

    public function test_reporta_error_de_meta_sin_romper(): void
    {
        $page = $this->paginaConectada('111', null);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 400),
        ]);

        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/publicaciones/foto', [
            'texto' => 'Hola',
            'imagen_url' => 'https://backend.esnoticia.org/public/redes/imagenes/post-1.jpg',
            'paginas' => [['page_id' => '111', 'facebook' => true, 'instagram' => true]],
        ]);

        $r->assertOk()
          ->assertJsonPath('success', false)
          ->assertJsonPath('resultados.0.facebook.ok', false)
          ->assertJsonPath('resultados.0.instagram.no_configurado', true);
        $this->assertStringContainsString('Invalid OAuth', $r->json('resultados.0.facebook.error'));
        $this->assertSame('fail', MetaPost::first()->status);
    }

    public function test_pagina_inexistente(): void
    {
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/publicaciones/foto', [
            'texto' => 'Hola',
            'imagen_url' => 'https://x.com/a.jpg',
            'paginas' => [['page_id' => '404']],
        ]);
        $r->assertOk()->assertJsonPath('success', false)->assertJsonPath('resultados.0.facebook.ok', false);
    }
}
