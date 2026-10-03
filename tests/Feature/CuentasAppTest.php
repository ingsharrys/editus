<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\MetaPageUser;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\YoutubeCanal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Cuentas conectadas desde la app del editor: cada usuario de la app vincula
 * sus páginas de Facebook y canales de YouTube con un enlace firmado y solo
 * ve (y transmite a) lo suyo más lo de la organización.
 */
class CuentasAppTest extends TestCase
{
    private const TOKEN = 'token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.editus.ingest_token' => self::TOKEN,
            'services.facebook.version' => 'v23.0',
            'services.facebook.client_id' => 'fb-app',
            'services.facebook.client_secret' => 'fb-secreto',
            'services.facebook.link_redirect' => 'https://editus.test/auth/facebook/callback',
            'services.google.client_id' => 'cid',
            'services.google.client_secret' => 'csec',
            'services.google.redirect' => 'https://editus.test/auth/youtube/callback',
            'services.livekit.url' => 'wss://live.prueba.test',
            'services.livekit.api_key' => 'APIkey',
            'services.livekit.api_secret' => 'secreto-muy-largo-de-prueba-1234567890',
            'services.editor_app.scheme' => 'editor',
        ]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->text('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('youtube_canales', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('channel_id', 64); $t->string('titulo', 150); $t->text('foto')->nullable(); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expira_en')->nullable(); $t->string('stream_id', 64)->nullable(); $t->boolean('visible_en_editor')->default(true); $t->text('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('transmisiones_en_vivo', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->string('usuario_app', 60)->nullable(); $t->string('titulo', 200); $t->text('descripcion')->nullable(); $t->string('room', 80)->unique();
            $t->string('fb_live_id', 60)->nullable(); $t->string('fb_video_id', 60)->nullable(); $t->string('fb_permalink', 500)->nullable(); $t->text('stream_url')->nullable(); $t->string('egress_id', 80)->nullable(); $t->json('destinos')->nullable();
            $t->string('estado', 20)->default('creada'); $t->json('plantilla')->nullable(); $t->json('escena')->nullable(); $t->json('invitaciones')->nullable(); $t->text('error')->nullable(); $t->timestamp('iniciada_en')->nullable(); $t->timestamp('terminada_en')->nullable(); $t->timestamps();
        });

        // Página de la organización (conectada desde la web de editus por un admin) y una oculta en la app
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $admin = User::factory()->create(['role_id' => $rol->id]);
        $org = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias', 'visible_en_editor' => true]);
        $org->users()->attach($admin->id, ['page_access_token' => 'tok-org', 'is_active' => true]);
        $oculta = MetaPage::create(['page_id' => '333', 'name' => 'Interna', 'visible_en_editor' => false]);
        $oculta->users()->attach($admin->id, ['page_access_token' => 'tok-oculta', 'is_active' => true]);
        YoutubeCanal::create(['channel_id' => 'UCORG', 'titulo' => 'Canal Opa', 'access_token' => 't', 'visible_en_editor' => true]);
    }

    private function firma(string $u, ?int $exp = null): array
    {
        $exp = $exp ?? time() + 600;
        return ['u' => $u, 'exp' => $exp, 'sig' => hash_hmac('sha256', "{$u}|{$exp}", self::TOKEN)];
    }

    private function api(): static
    {
        return $this->withHeaders(['X-Editus-Token' => self::TOKEN, 'Accept' => 'application/json']);
    }

    /** Simula el usuario que devuelve Socialite para un proveedor. */
    private function socialite(string $driver, \Laravel\Socialite\Two\User $user): void
    {
        $provider = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('scopes')->andReturnSelf();
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect("https://{$driver}.test/oauth"));
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
    }

    public function test_el_enlace_firmado_abre_facebook_y_sin_firma_no(): void
    {
        $this->get('/auth/app/facebook?u=7&exp=' . (time() + 100) . '&sig=mala')->assertOk()->assertSee('no es válido');
        $this->get('/auth/app/facebook?' . http_build_query($this->firma('7', time() - 5)))->assertOk()->assertSee('venció');

        $this->socialite('facebook', new \Laravel\Socialite\Two\User());
        $r = $this->get('/auth/app/facebook?' . http_build_query($this->firma('7')));
        $r->assertRedirect('https://facebook.test/oauth');
        $this->assertSame('7', session('vinculo_app.u'));
    }

    public function test_conecta_facebook_desde_la_app_y_solo_ese_usuario_ve_sus_paginas(): void
    {
        $fb = (new \Laravel\Socialite\Two\User())->setRaw(['id' => 'FB77', 'name' => 'Dario'])->map(['id' => 'FB77', 'name' => 'Dario', 'token' => 'corto', 'expiresIn' => 3600]);
        $this->socialite('facebook', $fb);
        Http::fake([
            'graph.facebook.com/v23.0/oauth/access_token*' => Http::response(['access_token' => 'largo', 'expires_in' => 5000000], 200),
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [
                ['id' => '555', 'name' => 'Mi Página', 'category' => 'Medio', 'access_token' => 'tok-555', 'tasks' => ['ANALYZE', 'CREATE_CONTENT']],
                ['id' => '111', 'name' => 'Opa Noticias', 'category' => 'Medio', 'access_token' => 'tok-111-de-7', 'tasks' => ['CREATE_CONTENT']],
            ]], 200),
        ]);

        $r = $this->withSession(['vinculo_app' => ['u' => '7', 'red' => 'facebook']])->get('/auth/facebook/callback?code=abc');
        $r->assertOk()->assertSee('Se conectaron 2 páginas')->assertSee('editor://cuentas?red=facebook&amp;ok=1&amp;paginas=2', false);
        $this->assertNull(session('vinculo_app'));

        $social = SocialAccount::where('usuario_app', '7')->first();
        $this->assertNotNull($social);
        $this->assertNull($social->user_id);
        $this->assertSame('largo', $social->access_token);
        $this->assertSame('tok-555', MetaPageUser::where('usuario_app', '7')->whereHas('page', fn($q) => $q->where('page_id', '555'))->value('page_access_token'));
        // La página de la organización sigue con su token de la web y además con el del usuario 7
        $this->assertSame(2, MetaPageUser::whereIn('meta_page_id', MetaPage::where('page_id', '111')->select('id'))->count());

        // El usuario 7 ve su página y la de la organización (que también administra; no la oculta); otro usuario solo la de la organización
        $this->api()->get('/api/paginas?usuario=7')->assertOk()
            ->assertJsonPath('paginas.0.page_id', '555')->assertJsonPath('paginas.0.propia', true)
            ->assertJsonPath('paginas.1.page_id', '111')->assertJsonPath('paginas.1.propia', true)
            ->assertJsonCount(2, 'paginas');
        $this->api()->get('/api/paginas?usuario=8')->assertOk()->assertJsonCount(1, 'paginas')->assertJsonPath('paginas.0.page_id', '111')->assertJsonPath('paginas.0.propia', false);
        $this->api()->get('/api/paginas')->assertOk()->assertJsonCount(1, 'paginas');

        // Resumen de "Mis cuentas"
        $this->api()->get('/api/cuentas?usuario=7')->assertOk()
            ->assertJsonPath('facebook.conectada', true)->assertJsonPath('facebook.paginas.0.page_id', '555')
            ->assertJsonPath('youtube.canales.0.nombre', 'Canal Opa')->assertJsonPath('youtube.canales.0.propio', false);
        $this->api()->get('/api/cuentas?usuario=8')->assertOk()->assertJsonPath('facebook.conectada', false)->assertJsonCount(1, 'facebook.paginas');
    }

    public function test_al_transmitir_usa_el_token_del_usuario_y_no_deja_usar_paginas_ajenas(): void
    {
        $mia = MetaPage::create(['page_id' => '555', 'name' => 'Mi Página']);
        MetaPageUser::create(['meta_page_id' => $mia->id, 'user_id' => null, 'usuario_app' => '7', 'page_access_token' => 'tok-555', 'is_active' => 1]);
        MetaPageUser::create(['meta_page_id' => MetaPage::where('page_id', '111')->value('id'), 'user_id' => null, 'usuario_app' => '7', 'page_access_token' => 'tok-111-de-7', 'is_active' => 1]);

        Http::fake([
            'graph.facebook.com/v23.0/555/live_videos' => Http::response(['id' => '9001', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE'], 200),
            'graph.facebook.com/v23.0/111/live_videos' => Http::response(['id' => '9002', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE2'], 200),
            'graph.facebook.com/v23.0/*' => Http::response(['permalink_url' => '/x/', 'status' => 'LIVE', 'live_views' => 1, 'video' => ['id' => 'v']], 200),
            'live.prueba.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['name' => 'x'], 200),
            'live.prueba.test/twirp/livekit.RoomService/UpdateRoomMetadata' => Http::response(['name' => 'x'], 200),
            'live.prueba.test/twirp/livekit.Egress/StartRoomCompositeEgress' => Http::response(['egress_id' => 'EG_1', 'status' => 'EGRESS_STARTING'], 200),
            'live.prueba.test/*' => Http::response([], 200),
        ]);

        // Otro usuario no puede usar la página 555 (ni la oculta 333)
        $r = $this->api()->postJson('/api/en-vivo/preparar', ['page_ids' => ['555'], 'titulo' => 'Ajena', 'usuario' => '8'])->assertStatus(422);
        $this->assertStringContainsString('no está conectada a tu cuenta', $r->json('error'));
        $this->api()->postJson('/api/en-vivo/preparar', ['page_ids' => ['333'], 'titulo' => 'Oculta', 'usuario' => '8'])->assertStatus(422);

        $r = $this->api()->postJson('/api/en-vivo/preparar', ['page_ids' => ['555', '111'], 'titulo' => 'Mía', 'usuario' => '7'])->assertOk();
        $id = $r->json('transmision.id');
        $this->api()->postJson("/api/en-vivo/{$id}/iniciar")->assertOk()->assertJsonPath('transmision.estado', 'en_vivo');

        // Los Lives se crearon con los tokens del usuario 7 (también en la página de la organización)
        Http::assertSent(fn($req) => str_contains($req->url(), '/555/live_videos') && $req['access_token'] === 'tok-555');
        Http::assertSent(fn($req) => str_contains($req->url(), '/111/live_videos') && $req['access_token'] === 'tok-111-de-7');
    }

    public function test_sin_usuario_de_la_app_la_organizacion_sigue_usando_su_token(): void
    {
        $mia = MetaPage::create(['page_id' => '555', 'name' => 'Solo app']);
        MetaPageUser::create(['meta_page_id' => $mia->id, 'user_id' => null, 'usuario_app' => '7', 'page_access_token' => 'tok-555', 'is_active' => 1]);
        MetaPageUser::create(['meta_page_id' => MetaPage::where('page_id', '111')->value('id'), 'user_id' => null, 'usuario_app' => '7', 'page_access_token' => 'tok-111-de-7', 'is_active' => 1]);
        $tokens = app(\App\Services\MetaPageTokenResolver::class);
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp(null);
        $this->assertSame('tok-org', $tokens->forPage('111'));
        $this->assertSame('tok-555', $tokens->forPage('555'), 'una página conectada solo desde la app también publica');
        $this->assertSame('tok-111-de-7', $tokens->forPage('111', null, '7'));
    }

    public function test_sincronizar_y_desconectar_facebook(): void
    {
        $social = SocialAccount::create(['user_id' => null, 'usuario_app' => '7', 'provider' => 'facebook', 'provider_user_id' => 'FB77', 'access_token' => 'largo']);
        Http::fake(['graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [['id' => '555', 'name' => 'Mi Página', 'access_token' => 'tok-555', 'tasks' => []]]], 200)]);

        $this->assertStringContainsString('Primero conecta', $this->api()->postJson('/api/cuentas/facebook/sincronizar', ['usuario' => '8'])->assertStatus(422)->json('error'));
        $this->api()->postJson('/api/cuentas/facebook/sincronizar', ['usuario' => '7'])->assertOk()->assertJsonPath('paginas', 1)->assertJsonPath('facebook.paginas.0.page_id', '555');
        $this->assertSame($social->id, (int) MetaPageUser::where('usuario_app', '7')->value('social_account_id'));

        $this->api()->postJson('/api/cuentas/facebook/desconectar', ['usuario' => '7'])->assertOk()->assertJsonPath('facebook.conectada', false)->assertJsonCount(1, 'facebook.paginas');
        $this->assertSame(0, MetaPageUser::where('usuario_app', '7')->count());
        $this->assertSame(0, SocialAccount::where('usuario_app', '7')->count());
        // La página de la organización no se tocó
        $this->assertSame('tok-org', MetaPageUser::whereNull('usuario_app')->whereIn('meta_page_id', MetaPage::where('page_id', '111')->select('id'))->value('page_access_token'));
    }

    public function test_conecta_youtube_desde_la_app_y_lo_desconecta(): void
    {
        $g = (new \Laravel\Socialite\Two\User())->map(['token' => 'acc', 'refreshToken' => 'ref', 'expiresIn' => 3600]);
        $this->socialite('google', $g);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC7', 'snippet' => ['title' => 'Canal de Dario', 'thumbnails' => ['default' => ['url' => 'https://yt/f.jpg']]]]]], 200)]);

        $this->get('/auth/app/youtube?' . http_build_query($this->firma('7')))->assertRedirect('https://google.test/oauth');
        $r = $this->withSession(['vinculo_app' => ['u' => '7', 'red' => 'youtube']])->get('/auth/youtube/callback?code=abc');
        $r->assertOk()->assertSee('Canal de Dario')->assertSee('editor://cuentas?red=youtube&amp;ok=1', false);

        $canal = YoutubeCanal::where('usuario_app', '7')->first();
        $this->assertNotNull($canal);
        $this->assertSame('ref', $canal->refresh_token);

        // El usuario 7 ve su canal y el de la organización; el 8 solo el de la organización
        $lista = collect($this->api()->get('/api/en-vivo/youtube?usuario=7')->assertOk()->assertJsonCount(2, 'canales')->json('canales'))->keyBy('nombre');
        $this->assertTrue($lista['Canal de Dario']['propio']);
        $this->assertFalse($lista['Canal Opa']['propio']);
        $this->api()->get('/api/en-vivo/youtube?usuario=8')->assertOk()->assertJsonCount(1, 'canales');
        $this->api()->postJson('/api/en-vivo/preparar', ['youtube_canal_ids' => [$canal->id], 'titulo' => 'Ajeno', 'usuario' => '8'])->assertStatus(422);

        // Solo el dueño lo desconecta; el de la organización no se puede desde la app
        $this->api()->postJson("/api/cuentas/youtube/{$canal->id}/desconectar", ['usuario' => '8'])->assertStatus(422);
        $org = YoutubeCanal::where('channel_id', 'UCORG')->first();
        $this->assertStringContainsString('organización', $this->api()->postJson("/api/cuentas/youtube/{$org->id}/desconectar", ['usuario' => '7'])->assertStatus(422)->json('error'));
        $this->api()->postJson("/api/cuentas/youtube/{$canal->id}/desconectar", ['usuario' => '7'])->assertOk()->assertJsonCount(1, 'youtube.canales');
        $this->assertSame(0, YoutubeCanal::where('usuario_app', '7')->count());
    }

    public function test_las_paginas_de_la_organizacion_se_limitan_a_ciertos_usuarios(): void
    {
        $this->assertSame(['willy', 'karol'], MetaPage::usuariosApp(' Willy, KAROL;  '));
        $this->assertNull(MetaPage::usuariosApp('  '));
        MetaPage::where('page_id', '111')->update(['app_usuarios' => json_encode(['willy'])]);
        YoutubeCanal::where('channel_id', 'UCORG')->update(['app_usuarios' => json_encode(['willy'])]);

        // willy la ve; karol no (ni la página ni el canal); sin usuario (web / llamadas viejas) se ve todo
        $this->api()->get('/api/paginas?usuario=7&usuario_nombre=Willy')->assertOk()->assertJsonCount(1, 'paginas');
        $this->api()->get('/api/paginas?usuario=8&usuario_nombre=karol')->assertOk()->assertJsonCount(0, 'paginas');
        $this->api()->get('/api/paginas?usuario=8')->assertOk()->assertJsonCount(0, 'paginas');
        $this->api()->get('/api/paginas')->assertOk()->assertJsonCount(1, 'paginas');
        $this->api()->get('/api/en-vivo/youtube?usuario=8&usuario_nombre=karol')->assertOk()->assertJsonCount(0, 'canales');
        $this->api()->get('/api/en-vivo/youtube?usuario=7&usuario_nombre=willy')->assertOk()->assertJsonCount(1, 'canales');
        $this->api()->get('/api/cuentas?usuario=8&usuario_nombre=karol')->assertOk()->assertJsonCount(0, 'facebook.paginas')->assertJsonCount(0, 'youtube.canales');

        // karol no puede transmitir en la página 111; willy sí
        $this->api()->postJson('/api/en-vivo/preparar', ['page_ids' => ['111'], 'titulo' => 'x', 'usuario' => '8', 'usuario_nombre' => 'karol'])->assertStatus(422);
        Http::fake(['live.prueba.test/*' => Http::response(['name' => 'x'], 200)]);
        $this->api()->postJson('/api/en-vivo/preparar', ['page_ids' => ['111'], 'titulo' => 'x', 'usuario' => '7', 'usuario_nombre' => 'willy'])->assertOk();

        // Karol la conecta como propia desde la app: entonces sí la ve
        MetaPageUser::create(['meta_page_id' => MetaPage::where('page_id', '111')->value('id'), 'user_id' => null, 'usuario_app' => '8', 'page_access_token' => 'tok-karol', 'is_active' => 1]);
        $this->api()->get('/api/paginas?usuario=8&usuario_nombre=karol')->assertOk()->assertJsonCount(1, 'paginas')->assertJsonPath('paginas.0.propia', true);
    }

    public function test_los_callbacks_web_sin_sesion_mandan_a_iniciar_sesion(): void
    {
        $this->get('/auth/facebook/callback?code=abc')->assertRedirect(route('login'));
        $this->get('/auth/youtube/callback?code=abc')->assertRedirect(route('login'));
    }
}
