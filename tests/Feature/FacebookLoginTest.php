<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UsuarioSocialite;
use Tests\TestCase;

/** Ingreso con Facebook: token de larga duración, cuenta social actualizada y páginas sincronizadas. */
class FacebookLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.version' => 'v23.0', 'services.facebook.client_id' => 'app', 'services.facebook.client_secret' => 'secreto']);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token')->nullable(); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        (require database_path('migrations/2026_10_21_000001_idiomas_y_pagos_internacionales.php'))->up();
    }

    private function facebook(string $email = 'ana@diario.co'): void
    {
        $u = (new UsuarioSocialite())->setRaw(['id' => 'FB9'])->map(['id' => 'FB9', 'name' => 'Ana Pérez', 'email' => $email]);
        $u->token = 'corto';
        $driver = \Mockery::mock();
        $driver->shouldReceive('usingGraphVersion')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('facebook')->andReturn($driver);
    }

    public function test_basta_con_ser_editor_de_la_pagina_y_el_portafolio_es_aparte(): void
    {
        // Facebook niega «business_management» a quien no está en el portafolio comercial (y entonces niega todo):
        // por eso solo se pide en la opción del portafolio
        config(['services.facebook.redirect' => 'https://app.editus.online/auth/facebook/login/callback', 'services.facebook.link_config_id' => null,
            'services.facebook.login_config_id' => null, 'services.facebook.portafolio_config_id' => null,
            'services.facebook.link_scopes' => ['email', 'public_profile', 'pages_show_list', 'pages_manage_posts', 'business_management', 'instagram_basic']]);
        $this->assertSame(['email', 'public_profile', 'pages_show_list', 'pages_manage_posts', 'instagram_basic'], \App\Support\FacebookGraph::permisosPaginas());
        $this->assertContains('business_management', \App\Support\FacebookGraph::permisosPaginas(true));

        $normal = urldecode((string) $this->get(route('facebook.login'))->assertRedirect()->headers->get('Location'));
        $this->assertStringContainsString('pages_manage_posts', $normal);
        $this->assertStringNotContainsString('business_management', $normal);
        $conPortafolio = urldecode((string) $this->get(route('facebook.login', ['portafolio' => 1]))->headers->get('Location'));
        $this->assertStringContainsString('business_management', $conPortafolio);

        // «Conectar mis páginas» en la web: igual
        $admin = User::factory()->create(['role_id' => \App\Models\Role::where('slug', 'admin')->value('id')]);
        $conectar = urldecode((string) $this->actingAs($admin)->get(route('facebook.connect'))->assertRedirect()->headers->get('Location'));
        $this->assertStringContainsString('pages_show_list', $conectar);
        $this->assertStringNotContainsString('business_management', $conectar);
        $this->assertStringContainsString('business_management', urldecode((string) $this->actingAs($admin)->get(route('facebook.connect', ['portafolio' => 1]))->headers->get('Location')));

        // Con «Facebook Login for Business» el portafolio puede tener su propia configuración
        config(['services.facebook.login_config_id' => '111', 'services.facebook.portafolio_config_id' => '222']);
        $this->assertSame(['111', '222', '111'], [\App\Support\FacebookGraph::configId('login'), \App\Support\FacebookGraph::configId('login', true), \App\Support\FacebookGraph::configId('login', false)]);
    }

    public function test_ingresa_cambia_el_token_y_sincroniza_las_paginas(): void
    {
        $this->facebook();
        Http::fake([
            'graph.facebook.com/v23.0/oauth/access_token*' => Http::response(['access_token' => 'largo', 'expires_in' => 5184000]),
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Diario Uno', 'category' => 'Medio', 'access_token' => 'tok-111', 'tasks' => ['ANALYZE', 'CREATE_CONTENT'], 'instagram_business_account' => ['id' => 'IG1']],
                ['id' => '222', 'name' => 'Diario Dos', 'access_token' => 'tok-222', 'tasks' => ['CREATE_CONTENT']],
            ]]),
        ]);
        $this->get('/auth/facebook/login/callback?code=abc')->assertRedirect(route('meta.pages.index'))->assertSessionHas('success', '¡Conexión exitosa! Páginas sincronizadas: 2');
        $u = User::where('email', 'ana@diario.co')->firstOrFail();
        $this->assertAuthenticatedAs($u);
        $this->assertSame('largo', SocialAccount::where('user_id', $u->id)->value('access_token'));
        $this->assertSame('IG1', MetaPage::where('page_id', '111')->value('instagram_business_account_id'));
        $this->assertSame(2, $u->metaPages()->count());

        // Segundo ingreso: no duplica la cuenta social y renueva el token
        Http::fake(['*oauth/access_token*' => Http::response(['access_token' => 'largo-2']), '*me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111b']]])]);
        $this->post('/logout');
        $this->get('/auth/facebook/login/callback?code=def')->assertRedirect(route('meta.pages.index'));
        $this->assertSame(1, SocialAccount::where('user_id', $u->id)->count());
    }

    public function test_errores_de_facebook_y_usuario_desactivado(): void
    {
        // Vuelve a la pantalla de ingreso con qué hacer (no otra vez a la ventana de Facebook)
        $this->get('/auth/facebook/login/callback?error=access_denied&error_reason=user_denied')->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => \App\Support\FacebookGraph::ayudaPermisos('user_denied')]);
        $this->get(route('login'))->assertOk()->assertSee('No se concedieron los permisos solicitados a los siguientes activos comerciales');
        $this->get('/auth/facebook/login/callback')->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Facebook no devolvió el parámetro "code". Vuelve a intentarlo.']);

        // Error de la Graph API al pedir las páginas: entra, pero avisa con el mensaje de Facebook
        $this->facebook();
        Http::fake(['*oauth/access_token*' => Http::response(['access_token' => 'largo']), '*me/accounts*' => Http::response(['error' => ['message' => 'Permisos insuficientes', 'code' => 200]], 400)]);
        $this->get('/auth/facebook/login/callback?code=abc')->assertSessionHas('error', 'Usuario conectado, pero falló la sincronización: No se pudieron obtener las páginas: Permisos insuficientes (#200)');

        // Desactivado: no entra
        $this->post('/logout');
        User::where('email', 'ana@diario.co')->update(['activo' => false]);
        $this->get('/auth/facebook/login/callback?code=abc')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
