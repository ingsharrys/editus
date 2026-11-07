<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\MetaPageUser;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CuentasAppService;
use App\Support\FacebookGraph;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * Conectar páginas siendo solo editor o administrador de la página, sin pertenecer al portafolio comercial que la
 * tiene. Con la configuración de producción (FACEBOOK_LINK_CONFIG_ID de «Login for Business» puesta): «Conectar mis
 * páginas» abre la ventana clásica de Facebook sin el portafolio; «desde el portafolio» sigue igual que siempre.
 */
class FacebookConexionPaginasTest extends TestCase
{
    private const CONFIG_PORTAFOLIO = '1651606865840453';
    private const TOKEN = 'token-de-prueba';
    /** Lo que tiene producción por defecto (con business_management y pages_manage_metadata) */
    private const PERMISOS_PRODUCCION = ['email', 'public_profile', 'pages_show_list', 'pages_manage_posts', 'pages_manage_metadata', 'pages_read_engagement', 'pages_read_user_content', 'read_insights', 'business_management', 'instagram_basic', 'instagram_manage_comments', 'instagram_content_publish'];
    private const PERMISOS_NORMALES = ['email', 'public_profile', 'pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'pages_read_user_content', 'read_insights', 'instagram_basic', 'instagram_manage_comments', 'instagram_content_publish'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.facebook.version' => 'v23.0',
            'services.facebook.client_id' => 'app',
            'services.facebook.client_secret' => 'secreto',
            'services.facebook.redirect' => 'https://app.editus.online/auth/facebook/login/callback',
            'services.facebook.link_redirect' => 'https://app.editus.online/auth/facebook/callback',
            'services.facebook.link_config_id' => self::CONFIG_PORTAFOLIO,
            'services.facebook.login_config_id' => null,
            'services.facebook.portafolio_config_id' => null,
            'services.facebook.paginas_config_id' => null,
            'services.facebook.link_scopes' => self::PERMISOS_PRODUCCION,
            'services.editus.ingest_token' => self::TOKEN,
            'services.editor_app.scheme' => 'editor',
        ]);
        Http::preventStrayRequests();
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token')->nullable(); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->text('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        (require database_path('migrations/2026_10_21_000001_idiomas_y_pagos_internacionales.php'))->up();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    }

    /** Parámetros de la ventana de Facebook a la que redirige la respuesta. */
    private function ventana(TestResponse $r): array
    {
        $url = (string) $r->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    /** El «state» de la ventana de Facebook a la que redirige la respuesta. */
    private function estado(TestResponse $r): string
    {
        parse_str((string) parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY), $q);
        return (string) ($q['state'] ?? '');
    }

    /** La marca que esa ventana dejó en la sesión (va por su «state»). */
    private function marca(TestResponse $r): array
    {
        return (array) session('fb_conexion.' . ($this->estado($r) ?: '_'));
    }

    /** Sesión con la marca de una ventana sin «state» (para callbacks escritos a mano). */
    private function conMarca(array $marca, array $sesion = []): array
    {
        return $sesion + ['fb_conexion' => ['_' => $marca + ['desde' => time()]]];
    }

    private function firma(string $u, ?int $exp = null): array
    {
        $exp ??= time() + 600;
        return ['u' => $u, 'exp' => $exp, 'sig' => hash_hmac('sha256', "{$u}|{$exp}", self::TOKEN)];
    }

    /** Socialite simulado: abre «Facebook» y devuelve este usuario en el callback. */
    private function facebookDevuelve(string $id = 'FB9'): void
    {
        $u = (new \Laravel\Socialite\Two\User())->setRaw(['id' => $id])->map(['id' => $id, 'name' => 'Ana Editora', 'email' => 'ana@medio.co']);
        $u->token = 'corto';
        $p = Mockery::mock(\Laravel\Socialite\Two\AbstractProvider::class);
        foreach (['redirectUrl', 'stateless', 'setScopes', 'with', 'usingGraphVersion'] as $m) $p->shouldReceive($m)->andReturnSelf();
        // Como Socialite: guarda un «state» nuevo en la sesión y lo manda en la ventana
        $p->shouldReceive('redirect')->andReturnUsing(function () {
            session()->put('state', $estado = \Illuminate\Support\Str::random(40));
            return redirect('https://www.facebook.com/v23.0/dialog/oauth?state=' . $estado);
        });
        $p->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('facebook')->andReturn($p);
    }

    /** Respuestas de Facebook al conectar: token largo, páginas y lo que dice debug_token del token. */
    private function graph(array $paginas, array $infoToken = ['scopes' => self::PERMISOS_NORMALES], array $mas = []): void
    {
        Http::fake($mas + [
            'graph.facebook.com/v23.0/oauth/access_token*' => Http::response(['access_token' => 'largo', 'expires_in' => 5184000]),
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => $paginas]),
            'graph.facebook.com/v23.0/debug_token*' => Http::response(['data' => $infoToken]),
        ]);
    }

    public function test_conectar_mis_paginas_abre_la_ventana_clasica_sin_el_portafolio(): void
    {
        $r = $this->actingAs($this->admin)->get(route('facebook.connect'));
        $q = $this->ventana($r);

        // Sin la configuración del portafolio (con ella Facebook ignora la lista y exige el portafolio)
        $this->assertArrayNotHasKey('config_id', $q);
        $this->assertArrayNotHasKey('override_default_response_type', $q);
        $this->assertSame(self::PERMISOS_NORMALES, explode(',', $q['scope']), 'sin business_management ni pages_manage_metadata');
        $this->assertSame('rerequest', $q['auth_type']);
        $this->assertSame('https://app.editus.online/auth/facebook/callback', $q['redirect_uri']);
        $this->assertSame(40, strlen($this->estado($r)));
        $this->assertSame(['origen' => 'web', 'portafolio' => false, 'config_id' => null], array_intersect_key($this->marca($r), array_flip(['origen', 'portafolio', 'config_id'])));
    }

    public function test_desde_el_portafolio_se_pide_lo_de_siempre(): void
    {
        $r = $this->actingAs($this->admin)->get(route('facebook.connect', ['portafolio' => 1]));
        $q = $this->ventana($r);
        $this->assertSame(self::CONFIG_PORTAFOLIO, $q['config_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('true', $q['override_default_response_type']);
        $this->assertSame('rerequest', $q['auth_type']);
        $this->assertSame(array_merge(array_values(array_diff(self::PERMISOS_PRODUCCION, ['business_management'])), ['business_management']), explode(',', $q['scope']));
        $this->assertTrue($this->marca($r)['portafolio']);

        // Con una configuración aparte para el portafolio: esa; «Conectar mis páginas» sigue sin configuración
        config(['services.facebook.portafolio_config_id' => '222']);
        $this->assertSame('222', $this->ventana($this->actingAs($this->admin)->get(route('facebook.connect', ['portafolio' => 1])))['config_id']);
        $this->assertArrayNotHasKey('config_id', $this->ventana($this->actingAs($this->admin)->get(route('facebook.connect'))));
    }

    public function test_conectar_mis_paginas_puede_tener_su_propia_configuracion(): void
    {
        // Por si Meta deja de aceptar la ventana clásica: una configuración de token de usuario, solo páginas
        config(['services.facebook.paginas_config_id' => '999']);
        $q = $this->ventana($this->actingAs($this->admin)->get(route('facebook.connect')));
        $this->assertSame('999', $q['config_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertArrayNotHasKey('scope', $q, 'con configuración, la lista no se manda');
        $this->assertSame(self::CONFIG_PORTAFOLIO, $this->ventana($this->actingAs($this->admin)->get(route('facebook.connect', ['portafolio' => 1])))['config_id']);

        // Si alguien pone ahí la del portafolio, no se usa (volvería el error)
        config(['services.facebook.paginas_config_id' => self::CONFIG_PORTAFOLIO]);
        $q = $this->ventana($this->actingAs($this->admin)->get(route('facebook.connect')));
        $this->assertArrayNotHasKey('config_id', $q);
        $this->assertStringNotContainsString('business_management', $q['scope']);
    }

    public function test_que_abre_cada_boton(): void
    {
        $this->assertSame([null, self::CONFIG_PORTAFOLIO, null, self::CONFIG_PORTAFOLIO, null, null], [
            FacebookGraph::configId('link'), FacebookGraph::configId('link', true),
            FacebookGraph::configId('app'), FacebookGraph::configId('app', true),
            FacebookGraph::configId('login'), FacebookGraph::configId('login', true),
        ]);
        config(['services.facebook.paginas_config_id' => '999', 'services.facebook.portafolio_config_id' => '222', 'services.facebook.login_config_id' => '111']);
        $this->assertSame(['999', '222', '999', '222', '111', '222'], [
            FacebookGraph::configId('link'), FacebookGraph::configId('link', true),
            FacebookGraph::configId('app'), FacebookGraph::configId('app', true),
            FacebookGraph::configId('login'), FacebookGraph::configId('login', true),
        ]);

        // Ni siquiera si pasan la de siempre (FACEBOOK_LINK_CONFIG_ID) a FACEBOOK_PAGINAS_CONFIG_ID
        config(['services.facebook.paginas_config_id' => self::CONFIG_PORTAFOLIO]);
        $this->assertNull(FacebookGraph::configId('link'));
        $this->assertSame(['222', self::CONFIG_PORTAFOLIO], FacebookGraph::configsDePortafolio());

        // editus:doctor lo muestra así
        config(['services.facebook.paginas_config_id' => null, 'services.facebook.portafolio_config_id' => null, 'services.facebook.login_config_id' => null]);
        $flujos = FacebookGraph::flujos();
        $this->assertNull($flujos['Conectar mis páginas (web)']['config_id']);
        $this->assertSame(self::PERMISOS_NORMALES, $flujos['Conectar desde la app del editor']['permisos']);
        $this->assertSame(self::CONFIG_PORTAFOLIO, $flujos['Desde el portafolio comercial (web y app)']['config_id']);
        $this->assertNull($flujos['Desde el portafolio comercial (web y app)']['permisos'], 'los define la configuración');
        $this->assertNotContains('business_management', $flujos['Continuar con Facebook (ingreso)']['permisos']);

        // Solo se piden los permisos que editus usa; lo demás del .env queda para el portafolio
        config(['services.facebook.link_scopes' => ['email', 'public_profile', 'pages_show_list', 'pages_manage_metadata', 'ads_management', 'pages_manage_posts', 'business_management', 'publish_video']]);
        $this->assertSame(['email', 'public_profile', 'pages_show_list', 'pages_manage_posts', 'publish_video'], FacebookGraph::permisosPaginas());
        $this->assertSame(['email', 'public_profile', 'pages_show_list', 'pages_manage_metadata', 'ads_management', 'pages_manage_posts', 'publish_video', 'business_management'], FacebookGraph::permisosPaginas(true));
    }

    public function test_la_app_abre_la_ventana_clasica_y_el_portafolio_es_aparte(): void
    {
        $firma = $this->firma('7') + ['volver' => 'en-vivo'];
        $r = $this->get('/auth/app/facebook?' . http_build_query($firma));
        $q = $this->ventana($r);
        $this->assertArrayNotHasKey('config_id', $q);
        $this->assertNotContains('business_management', explode(',', $q['scope']));
        $this->assertSame('app', $this->marca($r)['origen']);
        $this->assertSame('7', $this->marca($r)['vinculo']['u'], 'la marca lleva el enlace firmado');
        $this->assertSame(['u' => '7', 'volver' => 'en-vivo', 'exp' => $firma['exp'], 'sig' => $firma['sig']], array_intersect_key((array) session('vinculo_app'), array_flip(['u', 'volver', 'exp', 'sig'])));

        $q = $this->ventana($this->get('/auth/app/facebook?' . http_build_query($firma + ['portafolio' => 1])));
        $this->assertSame(self::CONFIG_PORTAFOLIO, $q['config_id']);
        $this->assertContains('business_management', explode(',', $q['scope']));
    }

    public function test_si_facebook_falla_en_la_app_se_puede_reintentar(): void
    {
        Log::spy();
        $firma = $this->firma('7');
        $vinculo = ['u' => '7', 'red' => 'facebook', 'volver' => 'cuentas', 'exp' => $firma['exp'], 'sig' => $firma['sig']];
        $r = $this->withSession($this->conMarca(['origen' => 'app', 'portafolio' => false], ['vinculo_app' => $vinculo]))->get('/auth/facebook/callback?error=access_denied&error_description=Permisos');
        $r->assertOk()->assertSee('marca solo tus páginas')->assertSee('No se concedieron los permisos solicitados a los siguientes activos comerciales')
            ->assertSee('Volver a conectar mis páginas')
            ->assertSee(route('app.cuentas.facebook', $firma + ['volver' => 'cuentas']))
            ->assertSee(route('app.cuentas.facebook', $firma + ['volver' => 'cuentas', 'portafolio' => 1]))
            ->assertDontSee('setTimeout', false);
        Log::shouldHaveReceived('warning')->withArgs(fn($m) => str_contains($m, 'conexión desde la app'))->once();

        // Si vino de «desde el portafolio», se le explica que eso es solo para quien administra el portafolio
        $this->withSession($this->conMarca(['origen' => 'app', 'portafolio' => true], ['vinculo_app' => $vinculo]))
            ->get('/auth/facebook/callback?error=access_denied')->assertSee('solo funciona para quien administra');

        // Con el enlace vencido no hay «reintentar»: se vuelve a la app
        $vencido = ['exp' => time() - 5] + $vinculo;
        $this->withSession($this->conMarca(['origen' => 'app'], ['vinculo_app' => $vencido]))->get('/auth/facebook/callback?error=access_denied')
            ->assertOk()->assertDontSee('Volver a conectar mis páginas')->assertSee('Volver a la app');
    }

    public function test_las_ventanas_de_la_app_y_de_la_web_no_se_cruzan(): void
    {
        // En el teléfono: abre la ventana desde la app y, en el mismo navegador, otra desde editus web; la de la app termina primero
        $this->facebookDevuelve();
        $this->graph([['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => ['CREATE_CONTENT', 'ANALYZE']]]);
        $firma = $this->firma('7');
        $deLaApp = $this->get('/auth/app/facebook?' . http_build_query($firma));
        $deLaWeb = $this->actingAs($this->admin)->get(route('facebook.connect'));
        $this->assertNotSame($this->estado($deLaApp), $this->estado($deLaWeb));

        $this->get('/auth/facebook/callback?code=A&state=' . $this->estado($deLaApp))->assertOk()->assertSee('Se conectó 1 página de Facebook.')
            // Si le falta una página que maneja solo desde el portafolio, ahí mismo puede conectarla (vuelve sola a la app, más despacio)
            ->assertSee('Conéctala desde el portafolio')->assertSee(route('app.cuentas.facebook', $firma + ['volver' => 'cuentas', 'portafolio' => 1]))
            ->assertSee('}, 5000)', false);
        $this->assertSame(1, SocialAccount::where('usuario_app', '7')->count());
        $this->assertSame(0, SocialAccount::where('user_id', $this->admin->id)->count());

        $this->actingAs($this->admin)->get('/auth/facebook/callback?code=B&state=' . $this->estado($deLaWeb))
            ->assertRedirect(route('meta.pages.index'))->assertSessionHas('success', 'Páginas sincronizadas: 1');
        $this->assertSame(1, SocialAccount::where('user_id', $this->admin->id)->count());
        $this->assertSame([], (array) session('fb_conexion'), 'cada marca se usa una sola vez');

        // Una conexión de la app que quedó a medias no se apodera de una de la web (ni una de YouTube de la app)
        $r = $this->actingAs($this->admin)->withSession(['vinculo_app' => ['u' => '8', 'red' => 'facebook']])->get(route('facebook.connect'));
        $this->actingAs($this->admin)->get('/auth/facebook/callback?code=C&state=' . $this->estado($r))->assertRedirect(route('meta.pages.index'));
        $this->assertSame(0, SocialAccount::where('usuario_app', '8')->count());
        $this->actingAs($this->admin)->withSession(['vinculo_app' => ['u' => '9', 'red' => 'youtube']])->get('/auth/facebook/callback?code=D')->assertRedirect(route('meta.pages.index'));
        $this->assertSame('youtube', session('vinculo_app.red'), 'la de YouTube sigue esperando su callback');
    }

    public function test_el_aviso_depende_del_boton(): void
    {
        $normal = $this->actingAs($this->admin)->withSession($this->conMarca(['origen' => 'web', 'portafolio' => false]))->get('/auth/facebook/callback?error=access_denied');
        $normal->assertRedirect(route('meta.pages.index'))->assertSessionHas('error', FacebookGraph::ayudaPermisos('access_denied'));
        $portafolio = $this->actingAs($this->admin)->withSession($this->conMarca(['origen' => 'web', 'portafolio' => true]))->get('/auth/facebook/callback?error=access_denied');
        $portafolio->assertSessionHas('error', FacebookGraph::ayudaPermisos('access_denied', true));

        foreach ([false, true] as $p) {
            $this->assertStringContainsString('No se concedieron los permisos solicitados a los siguientes activos comerciales', FacebookGraph::ayudaPermisos(null, $p));
            $this->assertStringContainsString('«Conectar mis páginas»', FacebookGraph::ayudaPermisos(null, $p));
        }
        $this->assertStringContainsString('Integraciones comerciales', FacebookGraph::ayudaPermisos());
        // En la pantalla de ingreso no hay «Conectar mis páginas»: se le dice que vuelva a ingresar con Facebook
        $this->assertStringContainsString('Vuelve a ingresar con Facebook', FacebookGraph::ayudaPermisos(null, false, true));
        $this->assertStringContainsString('No se concedieron los permisos solicitados a los siguientes activos comerciales', FacebookGraph::ayudaPermisos(null, false, true));
    }

    public function test_sincronizar_no_borra_el_instagram_guardado(): void
    {
        // Otra persona conectó la página con Instagram; esta conexión no trae Instagram
        MetaPage::create(['page_id' => '111', 'name' => 'Diario Uno', 'instagram_business_account_id' => 'IG1', 'tasks' => ['MANAGE']]);
        $social = SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        $conInstagram = false;
        Http::fake(function ($request) use (&$conInstagram) {
            $url = urldecode($request->url());
            if (str_contains($url, '/debug_token')) return Http::response(['data' => ['scopes' => self::PERMISOS_NORMALES]]);
            if (!str_contains($url, '/me/accounts')) return Http::response([], 404);
            if ($conInstagram) return Http::response(['data' => [['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => [], 'instagram_business_account' => ['id' => 'IG2']]]]);
            if (str_contains($url, 'instagram_business_account')) {
                return Http::response(['error' => ['message' => '(#100) Tried accessing nonexisting field (instagram_business_account)', 'code' => 100]], 400);
            }
            return Http::response(['data' => [['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => ['CREATE_CONTENT']]]]);
        });

        $this->assertSame(1, app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id));
        $pagina = MetaPage::where('page_id', '111')->first();
        $this->assertSame('IG1', $pagina->instagram_business_account_id);
        $this->assertSame(['CREATE_CONTENT'], $pagina->tasks);
        $this->assertSame('tok-111', MetaPageUser::where('user_id', $this->admin->id)->value('page_access_token'));

        // Si Facebook sí manda el Instagram, se actualiza
        $conInstagram = true;
        app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id);
        $this->assertSame('IG2', MetaPage::where('page_id', '111')->value('instagram_business_account_id'));
    }

    public function test_encuentra_las_paginas_elegidas_que_facebook_no_lista(): void
    {
        // La 333 es de un portafolio comercial al que la persona no pertenece: /me/accounts no la trae, pero la eligió
        $social = SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        $this->graph([['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => ['CREATE_CONTENT']]], [
            'scopes' => self::PERMISOS_NORMALES,
            'granular_scopes' => [
                ['scope' => 'pages_show_list', 'target_ids' => ['111', '333']],
                ['scope' => 'pages_manage_posts', 'target_ids' => ['111', '333']],
                ['scope' => 'business_management', 'target_ids' => ['999']],
                ['scope' => 'instagram_basic', 'target_ids' => ['17841400000000000']],
            ],
        ], ['graph.facebook.com/v23.0/333*' => Http::response(['id' => '333', 'name' => 'Medio del Portafolio Perdido', 'category' => 'Medio', 'access_token' => 'tok-333'])]);

        $this->assertSame(2, app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id));
        $pagina = MetaPage::where('page_id', '333')->first();
        $this->assertSame('Medio del Portafolio Perdido', $pagina->name);
        $this->assertSame([], $pagina->tasks);
        $this->assertSame('tok-333', MetaPageUser::where('meta_page_id', $pagina->id)->value('page_access_token'));
        $this->assertTrue((bool) MetaPageUser::where('meta_page_id', $pagina->id)->value('is_active'));
        Http::assertSentCount(3); // me/accounts, debug_token y la 333; nada más (ni el portafolio 999 ni Instagram)
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/999') || str_contains($r->url(), '/17841400000000000'));
    }

    public function test_sin_lista_de_paginas_elegidas_o_sin_debug_token_sincroniza_como_antes(): void
    {
        $social = SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        Http::fake([
            // Primero eligió «todas las páginas» (Facebook no lista cuáles); después debug_token falla
            'graph.facebook.com/v23.0/debug_token*' => Http::sequence()->push(['data' => ['scopes' => self::PERMISOS_NORMALES]])->push(['error' => ['message' => 'x']], 500),
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => []]]]),
        ]);
        foreach ([2, 4] as $enviadas) {
            $this->assertSame(1, app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id));
            Http::assertSentCount($enviadas); // me/accounts y debug_token; ninguna página leída aparte
        }
    }

    public function test_sin_paginas_el_aviso_no_pide_el_portafolio(): void
    {
        $social = SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        $this->graph([], ['scopes' => self::PERMISOS_NORMALES], ['graph.facebook.com/v23.0/me/assigned_pages*' => Http::response(['error' => ['message' => 'Requires business_management', 'code' => 200]], 400)]);
        try {
            app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id);
            $this->fail('Sin páginas debía avisar');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('No se encontraron páginas.', $e->getMessage());
            $this->assertStringContainsString('no hace falta pertenecer al portafolio comercial', $e->getMessage());
            $this->assertStringNotContainsString('business_management', $e->getMessage());
        }
    }

    public function test_avisa_si_quito_permisos_esenciales_en_la_ventana(): void
    {
        $this->facebookDevuelve();
        $sinMetricas = ['data' => ['scopes' => array_values(array_diff(self::PERMISOS_NORMALES, ['read_insights']))]];
        // debug_token: en la web y en la app quitó read_insights; la tercera vez dejó todo
        $this->graph([['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => ['CREATE_CONTENT']]], [], [
            'graph.facebook.com/v23.0/debug_token*' => Http::sequence()->push($sinMetricas)->push($sinMetricas)->push(['data' => ['scopes' => self::PERMISOS_NORMALES]]),
        ]);

        $r = $this->actingAs($this->admin)->withSession($this->conMarca(['origen' => 'web', 'portafolio' => false]))->get('/auth/facebook/callback?code=abc');
        $r->assertRedirect(route('meta.pages.index'))->assertSessionHas('success', 'Páginas sincronizadas: 1');
        $this->assertStringContainsString('estadísticas y demografía (read_insights)', (string) session('error'));
        Http::assertSentCount(3); // token largo, me/accounts y un solo debug_token (sirve para las dos cosas)

        // Desde la app: se conecta, se avisa y no vuelve sola a la app (que alcance a leerlo)
        $firma = $this->firma('7');
        $this->withSession($this->conMarca(['origen' => 'app'], ['vinculo_app' => ['u' => '7', 'red' => 'facebook', 'exp' => $firma['exp'], 'sig' => $firma['sig']]]))
            ->get('/auth/facebook/callback?code=abc')->assertOk()->assertSee('Se conectó 1 página de Facebook.')->assertSee('read_insights')
            ->assertDontSee('setTimeout', false)
            // el botón para hacer lo que pide el aviso (y no la nota del portafolio, que es para otro caso)
            ->assertSee('Volver a conectar mis páginas')->assertSee(route('app.cuentas.facebook', $firma + ['volver' => 'cuentas']))
            ->assertDontSee('Conéctala desde el portafolio');

        // Con todos los permisos no hay aviso y vuelve sola
        $this->withSession($this->conMarca(['origen' => 'app', 'portafolio' => true], ['vinculo_app' => ['u' => '7', 'red' => 'facebook']]))
            ->get('/auth/facebook/callback?code=abc')->assertOk()->assertDontSee('read_insights')->assertSee('setTimeout', false);
    }

    public function test_una_pagina_que_no_esta_en_me_accounts_se_lee_directo(): void
    {
        // «Vincular» una página: si /me/accounts no la trae, se lee directo (trae token si la persona tiene un rol en ella)
        Http::fake([
            'graph.facebook.com/v23.0/me/accounts*' => Http::response(['data' => [['id' => '111', 'access_token' => 'tok-111']]]),
            'graph.facebook.com/v23.0/333*' => Http::sequence()->push(['id' => '333', 'name' => 'Medio del Portafolio Perdido', 'access_token' => 'tok-333'])->push(['id' => '333']),
        ]);
        $this->assertSame('tok-333', (new FacebookGraph())->getPageDataFromMeAccounts('largo', '333')['access_token']);
        $this->assertNull((new FacebookGraph())->getPageDataFromMeAccounts('largo', '333'), 'sin rol en la página no hay token');
        $this->assertSame('tok-111', (new FacebookGraph())->getPageDataFromMeAccounts('largo', '111')['access_token']);
    }

    public function test_los_permisos_del_portafolio_y_de_instagram_no_cuentan_como_faltantes(): void
    {
        SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'completo']);
        $esenciales = array_map(fn($p) => ['permission' => $p, 'status' => 'granted'], ['pages_show_list', 'pages_read_engagement', 'read_insights', 'pages_read_user_content', 'pages_manage_posts']);
        Http::fake(['graph.facebook.com/v23.0/me/permissions*' => Http::sequence()
            ->push(['data' => array_merge($esenciales, [['permission' => 'business_management', 'status' => 'declined']])])
            ->push(['data' => [['permission' => 'pages_show_list', 'status' => 'granted'], ['permission' => 'read_insights', 'status' => 'declined']]])]);

        $this->artisan('editus:permisos-facebook')->expectsOutputToContain('solo al conectar desde el portafolio comercial')->doesntExpectOutputToContain('Faltan permisos')->assertExitCode(0);
        $this->artisan('editus:permisos-facebook')->expectsOutputToContain('Faltan permisos')->assertExitCode(0);
    }

    public function test_si_facebook_no_responde_al_leer_una_pagina_elegida_se_guardan_las_demas(): void
    {
        $social = SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        $this->graph([['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-111', 'tasks' => ['CREATE_CONTENT']]], [
            'scopes' => self::PERMISOS_NORMALES,
            'granular_scopes' => [['scope' => 'pages_show_list', 'target_ids' => ['111', '333', '444']]],
        ], [
            'graph.facebook.com/v23.0/333*' => Http::failedConnection('cURL error 28: Operation timed out'),
            'graph.facebook.com/v23.0/444*' => Http::response(['id' => '444', 'name' => 'Otra', 'access_token' => 'tok-444']),
        ]);

        $this->assertSame(1, app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id));
        $this->assertSame('tok-111', MetaPageUser::where('user_id', $this->admin->id)->value('page_access_token'));
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/v23.0/444'), 'si Facebook no responde, no se sigue esperando página por página');
    }

    /** debug_token según el token: el de B sin estadísticas; tok-A completo; tok-A-vencido ya no sirve. */
    private function infoTokens(array $mas = []): void
    {
        $sinMetricas = array_values(array_diff(self::PERMISOS_NORMALES, ['read_insights']));
        Http::fake(function ($request) use ($sinMetricas, $mas) {
            $url = urldecode($request->url());
            if (str_contains($url, '/debug_token')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                return Http::response(['data' => match ($q['input_token'] ?? '') {
                    'tok-A' => ['is_valid' => true, 'scopes' => self::PERMISOS_NORMALES],
                    'tok-A-vencido' => ['is_valid' => false, 'scopes' => []],
                    default => ['is_valid' => true, 'scopes' => $sinMetricas],
                }]);
            }
            foreach ($mas as $patron => $respuesta) if (str_contains($url, $patron)) return $respuesta;
            return Http::response([], 404);
        });
    }

    public function test_una_conexion_sin_permisos_esenciales_no_desplaza_a_la_que_funciona(): void
    {
        // A conectó la 111 con todo; B (editor) la conecta quitando las estadísticas y conecta además la 222, que nadie más tiene
        $a = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $uno = MetaPage::create(['page_id' => '111', 'name' => 'Diario Uno']);
        MetaPageUser::create(['meta_page_id' => $uno->id, 'user_id' => $a->id, 'page_access_token' => 'tok-A', 'is_active' => 1]);
        $deA = \Illuminate\Support\Facades\DB::table('meta_page_user')->where('user_id', $a->id); // (el pivote no trae su id al crearse)
        (clone $deA)->update(['updated_at' => now()->subHour()]);
        $social = SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        $this->infoTokens(['/me/accounts' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-B', 'tasks' => ['CREATE_CONTENT']],
            ['id' => '222', 'name' => 'Diario Dos', 'access_token' => 'tok-B2', 'tasks' => ['CREATE_CONTENT']],
        ]])]);

        $this->assertSame(2, app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id));
        $deB = MetaPageUser::where('user_id', $this->admin->id)->where('meta_page_id', $uno->id)->first();
        $this->assertSame('tok-B', $deB->page_access_token);
        $this->assertTrue($deB->is_active, 'B no pierde su página');
        $this->assertSame('tok-A', (new \App\Services\PageStatsService())->resolvePageToken($uno->id), 'publicar y medir siguen con la de A');
        $dos = MetaPage::where('page_id', '222')->value('id');
        $this->assertSame('tok-B2', (new \App\Services\PageStatsService())->resolvePageToken($dos), 'si es la única conexión de la página, se usa');

        // «Vincular» la página tampoco la pone adelante (y avisa qué falta)
        $this->actingAs($this->admin)->post(route('meta.pages.link', $uno), ['owner_id' => $this->admin->id])->assertSessionHas('success');
        $this->assertStringContainsString('read_insights', (string) session('error'));
        $this->assertSame('tok-A', (new \App\Services\PageStatsService())->resolvePageToken($uno->id));

        // Si la de A ya no sirve, no se le cede el turno: queda la de B
        (clone $deA)->update(['page_access_token' => 'tok-A-vencido', 'updated_at' => now()->subHour()]);
        app(CuentasAppService::class)->sincronizarPaginas($social, $this->admin->id);
        $this->assertSame('tok-B', (new \App\Services\PageStatsService())->resolvePageToken($uno->id));
    }

    public function test_los_errores_de_la_ventana_tambien_llegan_como_error_code(): void
    {
        // Facebook devuelve los errores de su ventana como ?error_code=…&error_message=… (sin ?error=)
        $error = ['error_code' => '1349246', 'error_message' => 'No se concedieron los permisos solicitados a los siguientes activos comerciales. 103783897950958'];
        $detalle = $error['error_message'] . ' (#1349246)';

        $this->actingAs($this->admin)->withSession($this->conMarca(['origen' => 'web', 'portafolio' => true]))->get('/auth/facebook/callback?' . http_build_query($error))
            ->assertRedirect(route('meta.pages.index'))->assertSessionHas('error', FacebookGraph::ayudaPermisos($detalle, true));
        // Sin código ni error también se explica qué hacer (y no se intenta leer un código que no llegó)
        $this->actingAs($this->admin)->withSession($this->conMarca(['origen' => 'web', 'portafolio' => false]))->get('/auth/facebook/callback')
            ->assertSessionHas('error', FacebookGraph::ayudaPermisos(null));

        $firma = $this->firma('7');
        $this->withSession($this->conMarca(['origen' => 'app'], ['vinculo_app' => ['u' => '7', 'red' => 'facebook', 'exp' => $firma['exp'], 'sig' => $firma['sig']]]))
            ->get('/auth/facebook/callback?' . http_build_query($error))->assertOk()->assertSee('(#1349246)')->assertSee('Volver a conectar mis páginas');

        $this->get('/auth/facebook/login/callback?' . http_build_query($error))->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => FacebookGraph::ayudaPermisos($detalle, false, true)]);
    }

    public function test_con_la_sesion_de_facebook_vencida_sincronizar_manda_a_conectar_otra_vez(): void
    {
        // Aunque Facebook mande su propio mensaje para la persona (error_user_msg), el código 190 dice que venció
        SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'vencido']);
        Http::fake(['graph.facebook.com/v23.0/me/accounts*' => Http::response(['error' => [
            'message' => 'Error validating access token: Session has expired', 'code' => 190, 'error_subcode' => 463, 'error_user_msg' => 'Tu sesión venció. Vuelve a iniciar sesión.',
        ]], 400)]);
        $this->actingAs($this->admin)->post(route('meta.pages.sync'))->assertRedirect(route('facebook.connect'));
    }

    public function test_parametros_raros_en_el_callback_no_tumban_la_pagina(): void
    {
        $this->actingAs($this->admin)->get('/auth/facebook/callback?error_code[]=1&state[]=a')->assertRedirect(route('meta.pages.index'));
        $this->actingAs($this->admin)->get('/auth/facebook/callback?error=x&error_message[]=a')->assertRedirect(route('meta.pages.index'));
        $this->post('/logout');
        $this->get('/auth/facebook/login/callback?error_code[]=1')->assertRedirect(route('login'));
    }

    public function test_un_enlace_de_facebook_a_medias_no_se_queda_con_la_conexion_de_youtube_del_administrador(): void
    {
        $this->actingAs($this->admin)->withSession(['vinculo_app' => ['u' => '7', 'red' => 'facebook']])
            ->get('/auth/youtube/callback?error=access_denied')->assertRedirect(route('editor-app.index', ['tab' => 'youtube']));
    }

    public function test_sincronizar_y_resincronizar_desde_la_app_avisan_si_faltan_permisos(): void
    {
        SocialAccount::create(['user_id' => $this->admin->id, 'provider' => 'facebook', 'provider_user_id' => 'FB9', 'access_token' => 'largo']);
        SocialAccount::create(['user_id' => null, 'usuario_app' => '7', 'provider' => 'facebook', 'provider_user_id' => 'FB7', 'access_token' => 'largo-7']);
        $this->infoTokens(['/me/accounts' => Http::response(['data' => [['id' => '111', 'name' => 'Diario Uno', 'access_token' => 'tok-B', 'tasks' => []]]])]);

        $this->actingAs($this->admin)->from(route('meta.pages.index'))->post(route('meta.pages.sync'))
            ->assertRedirect(route('meta.pages.index'))->assertSessionHas('success', 'Páginas sincronizadas: 1');
        $this->assertStringContainsString('estadísticas y demografía (read_insights)', (string) session('error'));

        $r = $this->withHeaders(['X-Editus-Token' => self::TOKEN, 'Accept' => 'application/json'])->postJson('/api/cuentas/facebook/sincronizar', ['usuario' => '7']);
        $r->assertOk()->assertJsonPath('paginas', 1);
        $this->assertStringContainsString('read_insights', (string) $r->json('aviso'));
    }
}
