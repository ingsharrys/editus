<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Role;
use App\Models\User;
use App\Services\SocialVideoPublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** API de video (Facebook + Reel) y de métricas en vivo. */
class PublicacionesVideoMetricasTest extends TestCase
{
    private const TOKEN = 'token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.editus.ingest_token' => self::TOKEN, 'services.facebook.version' => 'v23.0']);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id'); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('meta_posts', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->uuid('batch_uuid')->nullable(); $t->string('type'); $t->text('message')->nullable(); $t->string('link')->nullable();
            $t->json('local_media')->nullable(); $t->json('fb_media_ids')->nullable(); $t->string('fb_post_id')->nullable(); $t->string('fb_permalink_url')->nullable(); $t->string('status')->default('pending');
            $t->unsignedInteger('alcance')->nullable(); $t->unsignedInteger('visualizaciones')->nullable(); $t->unsignedInteger('interacciones')->nullable(); $t->string('evidencia_path')->nullable();
            $t->timestamp('last_insights_at')->nullable(); $t->text('error')->nullable(); $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        // Sin pausas entre consultas de estado
        $this->app->resolving(SocialVideoPublisher::class, function (SocialVideoPublisher $p) { $p->pausa = 0; $p->esperaFacebook = 2; $p->esperaInstagram = 2; });
    }

    private function pagina(string $pageId = '111', ?string $ig = '222'): MetaPage
    {
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $rol->id]);
        $page = MetaPage::create(['page_id' => $pageId, 'name' => 'Página ' . $pageId, 'instagram_business_account_id' => $ig]);
        $page->users()->attach($user->id, ['page_access_token' => 'tok-pagina', 'is_active' => true]);
        return $page;
    }

    public function test_publica_video_en_facebook_y_reel_en_instagram(): void
    {
        $page = $this->pagina();
        $estadoFb = 0; $estadoIg = 0;
        Http::fake([
            'graph-video.facebook.com/v23.0/111/videos' => Http::response(['id' => '9001'], 200),
            'graph.facebook.com/v23.0/9001*' => function () use (&$estadoFb) {
                $estadoFb++;
                return Http::response($estadoFb < 2
                    ? ['status' => ['video_status' => 'processing']]
                    : ['status' => ['video_status' => 'ready'], 'post_id' => '111_9001', 'permalink_url' => '/pagina/videos/9001/'], 200);
            },
            'graph.facebook.com/v23.0/222/media' => Http::response(['id' => '5001'], 200),
            'graph.facebook.com/v23.0/5001*' => function () use (&$estadoIg) {
                $estadoIg++;
                return Http::response(['status_code' => $estadoIg < 2 ? 'IN_PROGRESS' : 'FINISHED'], 200);
            },
            'graph.facebook.com/v23.0/222/media_publish' => Http::response(['id' => '18001'], 200),
            'graph.facebook.com/v23.0/18001*' => Http::response(['permalink' => 'https://www.instagram.com/reel/XYZ/'], 200),
        ]);

        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/publicaciones/video', [
            'texto' => "Resumen.\n\nVer más: https://backend.esnoticia.org/public/r/abc",
            'texto_instagram' => 'Texto completo para el reel',
            'video_url' => 'https://backend.esnoticia.org/public/redes/videos/v1.mp4',
            'imagen_url' => 'https://backend.esnoticia.org/public/redes/imagenes/p1.jpg',
            'paginas' => [['id' => $page->id, 'facebook' => true, 'instagram' => true, 'enlace' => 'https://backend.esnoticia.org/public/r/abc']],
        ]);
        $r->assertOk()->assertJsonPath('success', true)
          ->assertJsonPath('resultados.0.facebook.ok', true)
          ->assertJsonPath('resultados.0.facebook.post_id', '111_9001')
          ->assertJsonPath('resultados.0.facebook.media_id', '9001')
          ->assertJsonPath('resultados.0.facebook.permalink', 'https://www.facebook.com/pagina/videos/9001/')
          ->assertJsonPath('resultados.0.instagram.ok', true)
          ->assertJsonPath('resultados.0.instagram.post_id', '18001')
          ->assertJsonPath('resultados.0.instagram.permalink', 'https://www.instagram.com/reel/XYZ/');

        Http::assertSent(fn($req) => str_contains($req->url(), 'graph-video.facebook.com') && $req['file_url'] === 'https://backend.esnoticia.org/public/redes/videos/v1.mp4' && str_ends_with($req['description'], 'Ver más: https://backend.esnoticia.org/public/r/abc'));
        Http::assertSent(fn($req) => str_contains($req->url(), '/222/media') && !str_contains($req->url(), 'publish') && $req['media_type'] === 'REELS' && $req['video_url'] === 'https://backend.esnoticia.org/public/redes/videos/v1.mp4' && $req['caption'] === 'Texto completo para el reel' && $req['cover_url'] === 'https://backend.esnoticia.org/public/redes/imagenes/p1.jpg');

        $post = MetaPost::first();
        $this->assertSame('video', $post->type);
        $this->assertSame('success', $post->status);
        $this->assertSame('111_9001', $post->fb_post_id);
    }

    public function test_reel_rechazado_informa_el_motivo(): void
    {
        $page = $this->pagina();
        Http::fake([
            'graph.facebook.com/v23.0/222/media' => Http::response(['id' => '5001'], 200),
            'graph.facebook.com/v23.0/5001*' => Http::response(['status_code' => 'ERROR', 'status' => 'Error: Unsupported aspect ratio'], 200),
        ]);
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/publicaciones/video', [
            'texto' => 'Hola', 'video_url' => 'https://backend.esnoticia.org/public/redes/videos/v.mp4', 'paginas' => [['page_id' => '111', 'facebook' => false, 'instagram' => true]],
        ]);
        $r->assertOk()->assertJsonPath('success', false)->assertJsonPath('resultados.0.instagram.ok', false);
        $this->assertStringContainsString('Unsupported aspect ratio', $r->json('resultados.0.instagram.error'));
    }

    public function test_metricas_de_facebook_e_instagram(): void
    {
        $this->pagina();
        Http::fake([
            'graph.facebook.com/v23.0/111_55/insights*' => Http::response(['data' => [
                ['name' => 'post_impressions', 'values' => [['value' => 1500]]],
                ['name' => 'post_impressions_unique', 'values' => [['value' => 1200]]],
            ]], 200),
            'graph.facebook.com/v23.0/111_55?*' => Http::response(['reactions' => ['summary' => ['total_count' => 40]], 'comments' => ['summary' => ['total_count' => 5]], 'shares' => ['count' => 7]], 200),
            'graph.facebook.com/v23.0/9001/video_insights*' => Http::response(['data' => [['name' => 'total_video_views', 'values' => [['value' => 830]]]]], 200),
            'graph.facebook.com/v23.0/18001/insights*' => Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 600]]], ['name' => 'views', 'values' => [['value' => 900]]],
                ['name' => 'likes', 'values' => [['value' => 30]]], ['name' => 'comments', 'values' => [['value' => 2]]],
                ['name' => 'shares', 'values' => [['value' => 4]]], ['name' => 'saved', 'values' => [['value' => 6]]],
            ]], 200),
            'graph.facebook.com/v23.0/404*' => Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400),
        ]);

        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/publicaciones/metricas', ['items' => [
            ['red' => 'facebook', 'post_id' => '111_55', 'page_id' => '111', 'media_id' => '9001', 'tipo' => 'video'],
            ['red' => 'instagram', 'post_id' => '18001', 'page_id' => '111', 'tipo' => 'video'],
            ['red' => 'instagram', 'post_id' => '404', 'page_id' => '111'],
            ['red' => 'facebook', 'post_id' => '1_2', 'page_id' => '999'],
        ]]);
        $r->assertOk()
          ->assertJsonPath('metricas.0.ok', true)->assertJsonPath('metricas.0.alcance', 1200)->assertJsonPath('metricas.0.impresiones', 1500)
          ->assertJsonPath('metricas.0.interacciones', 52)->assertJsonPath('metricas.0.reproducciones', 830)->assertJsonPath('metricas.0.compartidos', 7)
          ->assertJsonPath('metricas.1.ok', true)->assertJsonPath('metricas.1.alcance', 600)->assertJsonPath('metricas.1.reproducciones', 900)
          ->assertJsonPath('metricas.1.interacciones', 42)->assertJsonPath('metricas.1.guardados', 6)
          ->assertJsonPath('metricas.2.ok', false)
          ->assertJsonPath('metricas.3.ok', false);
        $this->assertStringContainsString('Unsupported', $r->json('metricas.2.error'));
        $this->assertStringContainsString('token', $r->json('metricas.3.error'));
    }
}
