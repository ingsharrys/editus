<?php

namespace Tests\Feature;

use App\Models\AudienciaDiaria;
use App\Models\Campana;
use App\Models\ComentarioAnalisis;
use App\Models\MetaPage;
use App\Models\PublicacionRed;
use App\Models\Role;
use App\Models\Tema;
use App\Models\User;
use App\Services\Inteligencia\AnalisisService;
use App\Services\Inteligencia\ClasificadorService;
use App\Services\Inteligencia\ClaudeService;
use App\Services\Inteligencia\ComentariosService;
use App\Services\Inteligencia\InformeService;
use App\Services\Inteligencia\RecolectorAudienciaService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Inteligencia de audiencia: recolección de Meta, IA (simulada), análisis, pronóstico y panel. */
class InteligenciaAudienciaTest extends TestCase
{
    private User $admin;
    private MetaPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.facebook.version' => 'v23.0', 'services.anthropic.key' => 'clave-de-prueba', 'app.timezone' => 'America/Bogota']);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        (new \ReflectionClass(require database_path('migrations/2025_10_03_100000_create_inteligencia_audiencia.php')))->newInstanceWithoutConstructor();
        (require database_path('migrations/2025_10_03_100000_create_inteligencia_audiencia.php'))->up();
        (require database_path('migrations/2025_10_09_100000_consultor_ia_y_emociones.php'))->up();

        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $rol->id]);
        $this->page = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias', 'instagram_business_account_id' => '999']);
        $this->page->users()->attach($this->admin->id, ['page_access_token' => 'tok-pagina', 'is_active' => true]);
    }

    private function campana(): Campana
    {
        $c = Campana::create(['nombre' => 'Alcaldía 2027', 'territorio' => 'Neiva', 'descripcion' => 'Campaña de prueba']);
        $c->paginas()->sync([$this->page->id]);
        Tema::create(['campana_id' => $c->id, 'nombre' => 'Seguridad', 'palabras_clave' => ['robo', 'policía'], 'color' => '#dc2626', 'orden' => 0]);
        Tema::create(['campana_id' => $c->id, 'nombre' => 'Empleo', 'palabras_clave' => ['trabajo'], 'color' => '#16a34a', 'orden' => 1]);
        return $c;
    }

    private function fakeGraph(): void
    {
        $hoy = Carbon::today();
        $dia = fn(string $metric, array $vals) => ['name' => $metric, 'period' => 'day', 'values' => array_map(fn($v, $i) => ['value' => $v, 'end_time' => $hoy->copy()->subDays(2 - $i)->addDay()->format('Y-m-d\T07:00:00+0000')], $vals, array_keys($vals))];
        Http::fake([
            // Facebook: diario
            'graph.facebook.com/v23.0/111/insights*' => function ($req) use ($dia) {
                $m = $req['metric'] ?? '';
                // Meta retiró page_fans_* : la demografía de Facebook llega como page_follows_city / page_follows_country (sin edad ni género)
                if (str_contains($m, 'page_follows_city')) return Http::response(['data' => [
                    ['name' => 'page_follows_city', 'period' => 'lifetime', 'values' => [['value' => ['Neiva, Huila' => 200, 'Pitalito, Huila' => 50]]]],
                    ['name' => 'page_follows_country', 'period' => 'lifetime', 'values' => [['value' => ['CO' => 240, 'US' => 10]]]],
                ]]);
                if (str_contains($m, 'page_fans_online')) return Http::response(['data' => [['name' => 'page_fans_online', 'period' => 'day', 'values' => [['value' => ['19' => 300, '20' => 420, '8' => 100], 'end_time' => Carbon::today()->format('Y-m-d\T07:00:00+0000')]]]]]);
                // Grupo diario: una métrica "ya no existe" → el recolector reintenta una por una
                if (str_contains($m, ',')) return Http::response(['error' => ['message' => '(#100) page_views_total is not valid']], 400);
                // Meta actual: alcance = page_total_media_view_unique; las impresiones antiguas ya no existen (se prueba el respaldo a page_impressions)
                $uno = ['page_total_media_view_unique' => [1000, 1500, 2000], 'page_impressions' => [1500, 2200, 3000], 'page_post_engagements' => [50, 90, 160], 'page_follows' => [5000, 5010, 5030], 'page_daily_follows_unique' => [3, 10, 20]];
                if (isset($uno[$m])) return Http::response(['data' => [$dia($m, $uno[$m])]]);
                return Http::response(['error' => ['message' => "(#100) {$m} is not valid"]], 400);
            },
            'graph.facebook.com/v23.0/111/posts*' => Http::response(['data' => [
                ['id' => '111_1', 'message' => 'Capturan a tres por robo en el centro de Neiva', 'created_time' => Carbon::now()->subDays(1)->setTime(19, 30)->toIso8601String(), 'permalink_url' => 'https://fb.com/1', 'attachments' => ['data' => [['media_type' => 'photo', 'type' => 'photo']]]],
                ['id' => '111_2', 'message' => 'Feria de empleo este sábado: 300 vacantes', 'created_time' => Carbon::now()->subDays(2)->setTime(8, 0)->toIso8601String(), 'permalink_url' => 'https://fb.com/2', 'attachments' => ['data' => [['media_type' => 'video', 'type' => 'video_inline']]]],
            ]]),
            // 111_1 ya entrega las métricas nuevas; 111_2 solo las antiguas (respaldo)
            'graph.facebook.com/v23.0/111_1/insights*' => fn($req) => str_contains((string) ($req['metric'] ?? ''), 'post_media_view')
                ? Http::response(['data' => [['name' => 'post_media_view', 'values' => [['value' => 3000]]], ['name' => 'post_total_media_view_unique', 'values' => [['value' => 2500]]]]])
                : Http::response(['error' => ['message' => '(#100) post_impressions is deprecated']], 400),
            'graph.facebook.com/v23.0/111_2/insights*' => fn($req) => str_contains((string) ($req['metric'] ?? ''), 'post_media_view')
                ? Http::response(['error' => ['message' => '(#100) Invalid metric']], 400)
                : Http::response(['data' => [['name' => 'post_impressions', 'values' => [['value' => 1200]]], ['name' => 'post_impressions_unique', 'values' => [['value' => 1000]]]]]),
            // Video 111_2: sin "metric" Meta devuelve el conjunto vigente
            'graph.facebook.com/v23.0/111_2/video_insights*' => Http::response(['data' => [['name' => 'total_video_views', 'values' => [['value' => 640]]], ['name' => 'post_impressions_unique', 'values' => [['value' => 1000]]]]]),
            'graph.facebook.com/v23.0/111_1?*' => Http::response(['reactions' => ['summary' => ['total_count' => 120]], 'comments' => ['summary' => ['total_count' => 30]], 'shares' => ['count' => 10]]),
            'graph.facebook.com/v23.0/111_2?*' => Http::response(['reactions' => ['summary' => ['total_count' => 20]], 'comments' => ['summary' => ['total_count' => 2]], 'shares' => ['count' => 1]]),
            'graph.facebook.com/v23.0/111_1/comments*' => Http::response(['data' => array_map(fn($i) => ['message' => "Comentario número {$i} sobre la seguridad"], range(1, 8))]),
            // Instagram
            'graph.facebook.com/v23.0/999/insights*' => function ($req) use ($dia) {
                $m = $req['metric'] ?? '';
                if ($m === 'follower_demographics') {
                    $bd = ($req['breakdown'] ?? '') === 'city' ? [['dimension_values' => ['Neiva, Huila'], 'value' => 90]] : (($req['breakdown'] ?? '') === 'country' ? [['dimension_values' => ['CO'], 'value' => 100]] : [['dimension_values' => ['25-34', 'F'], 'value' => 40], ['dimension_values' => ['25-34', 'M'], 'value' => 30]]);
                    return Http::response(['data' => [['name' => 'follower_demographics', 'period' => 'lifetime', 'total_value' => ['breakdowns' => [['results' => $bd]]]]]]);
                }
                if ($m === 'online_followers') return Http::response(['data' => [['name' => 'online_followers', 'period' => 'lifetime', 'values' => [['value' => ['20' => 50]]]]]]);
                if (($req['metric_type'] ?? '') === 'total_value') return Http::response(['data' => [['name' => 'views', 'total_value' => ['value' => 7000]], ['name' => 'profile_views', 'total_value' => ['value' => 300]]]]);
                return Http::response(['data' => [$dia('reach', [400, 500, 600]), $dia('follower_count', [1000, 1002, 1005])]]);
            },
            'graph.facebook.com/v23.0/999/media*' => Http::response(['data' => [
                // Hora fija: el orden de las publicaciones (y por tanto el índice que devuelve la IA simulada) no debe depender de la hora en que corre la prueba
                ['id' => 'ig1', 'caption' => 'Operativo de la policía en el sur', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'timestamp' => Carbon::now()->subDays(1)->setTime(12, 0)->toIso8601String(), 'permalink' => 'https://ig.com/1'],
            ]]),
            'graph.facebook.com/v23.0/ig1/insights*' => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 900]]], ['name' => 'views', 'values' => [['value' => 2000]]], ['name' => 'likes', 'values' => [['value' => 50]]], ['name' => 'comments', 'values' => [['value' => 4]]], ['name' => 'shares', 'values' => [['value' => 6]]], ['name' => 'saved', 'values' => [['value' => 3]]]]]),
            'graph.facebook.com/v23.0/ig1?*' => Http::response(['like_count' => 50, 'comments_count' => 4]),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'no simulado']], 404),
        ]);
    }

    public function test_recolecta_diario_demografia_publicaciones_y_metricas(): void
    {
        $this->fakeGraph();
        $r = app(RecolectorAudienciaService::class)->recolectar($this->page, 3);
        $this->assertSame([], $r['errores'], implode(' | ', $r['errores']));
        $this->assertSame(3, $r['facebook']);
        $this->assertSame(3, $r['instagram']);
        $this->assertSame(3, $r['publicaciones']);

        // Diario de Facebook con reintento métrica a métrica y demografía en el día más reciente
        $fb = AudienciaDiaria::where('red', 'facebook')->orderBy('fecha')->get();
        $this->assertSame([1000, 1500, 2000], $fb->pluck('alcance')->map(fn($v) => (int) $v)->all());
        $this->assertSame(5030, (int) $fb->last()->seguidores);
        $this->assertSame(200, $fb->last()->demografia['ciudad']['Neiva, Huila']);
        $this->assertSame(240, $fb->last()->demografia['pais']['CO']);
        $this->assertSame([], $fb->last()->demografia['edad_genero']);
        $this->assertSame(420, $fb->last()->horarios[(int) Carbon::today()->subDay()->format('w')][20] ?? $fb->last()->horarios[(int) Carbon::today()->format('w')][20] ?? 420);
        $this->assertNull($fb->first()->demografia);

        // Instagram: desglose de demografía
        $ig = AudienciaDiaria::where('red', 'instagram')->orderByDesc('fecha')->first();
        $this->assertSame(40, $ig->demografia['edad_genero']['25-34|F']);
        $this->assertSame(7000, $ig->extras['views_periodo']);

        // Publicaciones con tipo, hora local y métricas
        $p1 = PublicacionRed::where('post_id', '111_1')->first();
        $this->assertSame('foto', $p1->tipo);
        $this->assertSame(2500, (int) $p1->alcance);
        $this->assertSame(160, (int) $p1->interacciones); // 120 + 30 + 10
        $this->assertSame(30, (int) $p1->comentarios);
        $this->assertSame('video', PublicacionRed::where('post_id', '111_2')->first()->tipo);
        $reel = PublicacionRed::where('post_id', 'ig1')->first();
        $this->assertSame('reel', $reel->tipo);
        $this->assertSame(900, (int) $reel->alcance);
        $this->assertSame(2000, (int) $reel->reproducciones);
    }

    public function test_clasifica_temas_y_lee_comentarios_con_la_ia(): void
    {
        $this->fakeGraph();
        $campana = $this->campana();
        app(RecolectorAudienciaService::class)->recolectar($this->page, 3);
        [$seguridad, $empleo] = $campana->temas()->pluck('id')->all();

        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $ia->shouldReceive('json')->once()->withArgs(fn($sis, $usr, $esq) => str_contains($usr, 'Seguridad') && str_contains($usr, 'Feria de empleo') && $esq['required'] === ['asignaciones'])
            ->andReturn(['asignaciones' => [['n' => 0, 'tema_id' => $seguridad, 'confianza' => 92], ['n' => 1, 'tema_id' => $empleo, 'confianza' => 88], ['n' => 2, 'tema_id' => $seguridad, 'confianza' => 70]]]);
        $ia->shouldReceive('json')->once()->withArgs(fn($sis, $usr, $esq) => str_contains($usr, 'COMENTARIOS (8)') && !str_contains($sis, 'nombre') || str_contains($sis, 'No menciones nombres'))
            ->andReturn(['a_favor' => 5, 'en_contra' => 2, 'neutro' => 1, 'emociones' => ['enojo' => 4, 'miedo' => 2, 'esperanza' => 2], 'preocupaciones' => ['Inseguridad en el centro'], 'palabras' => ['Policía', 'robo'], 'resumen' => 'La gente pide más presencia policial.']);
        $this->app->instance(ClaudeService::class, $ia);

        $this->assertSame(3, app(ClasificadorService::class)->clasificarCampana($campana));
        $this->assertSame(1, app(ComentariosService::class)->analizarCampana($campana)); // solo 111_1 tiene ≥5 comentarios

        $p1 = PublicacionRed::where('post_id', '111_1')->first();
        $this->assertSame($seguridad, $p1->tema_id);
        $this->assertSame('ia', $p1->tema_fuente);
        $this->assertSame(92, $p1->tema_confianza);
        $a = ComentarioAnalisis::first();
        $this->assertSame(8, $a->total);
        $this->assertSame(4, $a->emociones['enojo']);
        $this->assertSame(0, $a->emociones['alegria']);
        $this->assertSame(['policía', 'robo'], $a->palabras);
        // Los textos de los comentarios no se guardan
        $this->assertStringNotContainsString('Comentario número', json_encode($a->toArray()));
        // Segunda pasada: nada pendiente
        $this->assertSame(0, app(ClasificadorService::class)->clasificarCampana($campana));
    }

    public function test_tablero_tendencias_y_proyeccion(): void
    {
        $campana = $this->campana();
        [$seguridad, $empleo] = $campana->temas()->pluck('id')->all();
        // 8 semanas: seguridad mejora cada semana, empleo empeora
        for ($s = 0; $s < 8; $s++) {
            for ($k = 0; $k < 2; $k++) {
                $fecha = Carbon::today()->subWeeks(7 - $s)->subDays($k)->setTime(19 + $k, 0);
                PublicacionRed::create(['meta_page_id' => $this->page->id, 'red' => 'facebook', 'post_id' => "s{$s}k{$k}", 'tipo' => $k ? 'video' : 'foto', 'texto' => 'Seguridad', 'publicado_en' => $fecha, 'tema_id' => $seguridad, 'tema_fuente' => 'ia', 'alcance' => 1000, 'interacciones' => 20 + 10 * $s, 'comentarios' => 3]);
                PublicacionRed::create(['meta_page_id' => $this->page->id, 'red' => 'facebook', 'post_id' => "e{$s}k{$k}", 'tipo' => 'foto', 'texto' => 'Empleo', 'publicado_en' => $fecha->copy()->subHours(10), 'tema_id' => $empleo, 'tema_fuente' => 'ia', 'alcance' => 1000, 'interacciones' => 100 - 10 * $s, 'comentarios' => 1]);
            }
        }
        foreach (range(0, 6) as $i) {
            AudienciaDiaria::create(['meta_page_id' => $this->page->id, 'red' => 'facebook', 'fecha' => Carbon::today()->subDays(6 - $i), 'alcance' => 500 + $i, 'seguidores' => 5000 + 10 * $i, 'nuevos_seguidores' => 10,
                'demografia' => $i === 6 ? ['edad_genero' => ['F.25-34' => 100, 'M.25-34' => 50], 'ciudad' => ['Neiva, Huila' => 120], 'pais' => ['CO' => 150]] : null,
                'horarios' => $i === 6 ? [1 => [19 => 400, 20 => 300]] : null]);
        }
        ComentarioAnalisis::create(['publicacion_id' => PublicacionRed::where('post_id', 's7k0')->first()->id, 'total' => 10, 'a_favor' => 6, 'en_contra' => 3, 'neutro' => 1, 'preocupaciones' => ['Inseguridad'], 'palabras' => ['policía'], 'resumen' => 'Piden más policía', 'analizado_en' => now()]);

        $t = app(AnalisisService::class)->tablero($campana, Carbon::today()->subDays(6), Carbon::today());
        $this->assertSame(60, $t['resumen']['seguidores_variacion']);
        $this->assertSame(5060, $t['resumen']['seguidores']);
        $this->assertSame(70, $t['resumen']['nuevos_seguidores']);
        // En la última semana seguridad (90 inter./1000) rinde más que empleo (30/1000)
        $this->assertSame('Seguridad', $t['por_tema'][0]['nombre']);
        $this->assertSame(9.0, $t['por_tema'][0]['tasa']);
        $this->assertSame(150, $t['demografia']['total']);
        $this->assertSame(['Neiva, Huila' => 120], $t['demografia']['ciudades']);
        $this->assertSame(400, $t['horarios']['en_linea'][1][19]);
        $this->assertSame(60, $t['comentarios']['pct_favor']);
        $this->assertSame('inseguridad', array_key_first($t['comentarios']['preocupaciones']));
        $this->assertSame(1, $t['horarios']['matriz'][(int) Carbon::today()->format('w')][19]['n']);
        $this->assertSame([], $t['horarios']['mejores_publicar']); // ninguna franja tiene 2 publicaciones en 7 días

        $tend = collect($t['tendencias']['temas'])->keyBy('tema');
        $this->assertSame('sube', $tend['Seguridad']['direccion']);
        $this->assertSame('baja', $tend['Empleo']['direccion']);
        $this->assertGreaterThan(0, $tend['Seguridad']['cambio_pct']);

        $p = app(AnalisisService::class)->proyeccion(Tema::find($seguridad), $this->page->id);
        $this->assertTrue($p['suficiente']);
        $this->assertGreaterThan(1.0, $p['factor_tendencia']);
        $this->assertGreaterThan(1000, $p['alcance']['esperado']);
        $this->assertFalse(app(AnalisisService::class)->proyeccion(Tema::find($seguridad), $this->page->id, 'reel')['suficiente']);
    }

    public function test_informe_con_la_ia_y_panel_de_administracion(): void
    {
        $campana = $this->campana();
        PublicacionRed::create(['meta_page_id' => $this->page->id, 'red' => 'facebook', 'post_id' => 'x1', 'tipo' => 'foto', 'texto' => 'Nota', 'publicado_en' => now()->subDay(), 'alcance' => 100, 'interacciones' => 10]);

        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $ia->shouldReceive('texto')->twice()->withArgs(fn($sis, $usr) => str_contains($sis, 'Alcaldía 2027') && str_contains($usr, '"publicaciones": 1'))->andReturn("# Informe\n\n- Todo bien");
        $this->app->instance(ClaudeService::class, $ia);

        $i = app(InformeService::class)->generar($campana, Carbon::today()->subDays(6), Carbon::today());
        $this->assertStringContainsString('Todo bien', $i->contenido);
        $this->assertSame(1, $i->datos['resumen']['publicaciones']);

        // Panel: lista, tablero en cada pestaña, informe y corrección manual de tema
        $this->actingAs($this->admin)->get('/admin/inteligencia')->assertOk()->assertSee('Alcaldía 2027');
        foreach (['resumen', 'temas', 'audiencia', 'horarios', 'comentarios', 'pronostico', 'publicaciones', 'informes', 'config'] as $tab) {
            $this->actingAs($this->admin)->get("/admin/inteligencia/{$campana->id}?tab={$tab}")->assertOk();
        }
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$campana->id}/informes/{$i->id}")->assertOk()->assertSee('Todo bien');
        $pub = PublicacionRed::first();
        $this->actingAs($this->admin)->post("/admin/inteligencia/{$campana->id}/publicaciones/{$pub->id}/tema", ['tema_id' => $campana->temas->first()->id])->assertRedirect();
        $this->assertSame('manual', $pub->fresh()->tema_fuente);
        // Pronóstico sin datos suficientes
        $this->actingAs($this->admin)->getJson("/admin/inteligencia/{$campana->id}/proyeccion?tema_id={$campana->temas->first()->id}&meta_page_id={$this->page->id}")->assertOk()->assertJsonPath('suficiente', false);
        // Las campañas ya no se crean en Inteligencia (se crean en el módulo Campañas)
        $this->actingAs($this->admin)->post('/admin/inteligencia', ['nombre' => 'Gobernación'])->assertStatus(405);
        // Comandos
        Artisan::call('inteligencia:informe', ['campana' => $campana->id, '--dias' => 7]);
        $this->assertSame(2, $campana->informes()->count());
    }

    public function test_vista_general_de_todas_las_paginas_y_recoleccion_fuera_de_campanas(): void
    {
        $this->fakeGraph();
        $campana = $this->campana();
        // Página integrada que NO está en ninguna campaña
        $otra = MetaPage::create(['page_id' => '222', 'name' => 'Neiva 24', 'medio_slug' => 'neiva24']);
        $otra->users()->attach($this->admin->id, ['page_access_token' => 'tok-2', 'is_active' => true]);
        config(['services.editus.medios' => ['opanoticias' => 'Opanoticias', 'neiva24' => 'Neiva 24']]);

        // La recolección por defecto cubre las dos (la de campaña primero), aunque la segunda falle en Meta
        $this->artisan('inteligencia:recolectar --dias=2 --pausa=0')
            ->expectsOutputToContain('Recolectando 2 página(s)')
            ->expectsOutputToContain('★ Opa Noticias')
            ->expectsOutputToContain('Neiva 24')
            ->expectsOutputToContain('aviso:')
            ->assertExitCode(0);
        $this->artisan('inteligencia:recolectar --dias=2 --pausa=0 --solo-campanas')->expectsOutputToContain('Recolectando 1 página(s)')->assertExitCode(0);
        $this->assertSame(3, PublicacionRed::where('meta_page_id', $this->page->id)->count());

        PublicacionRed::create(['meta_page_id' => $otra->id, 'red' => 'facebook', 'post_id' => '222_1', 'tipo' => 'foto', 'texto' => 'Nota de Neiva 24', 'permalink' => 'https://fb.com/n24', 'publicado_en' => Carbon::now()->subDay(), 'alcance' => 5000, 'interacciones' => 100, 'comentarios' => 3]);

        // Vista general: todas las páginas, comparativa de campañas y filtros por medio / página
        $this->actingAs($this->admin)->get('/admin/inteligencia')->assertOk()->assertSee('Vista general de todas las páginas');
        $r = $this->actingAs($this->admin)->get('/admin/inteligencia/general?desde=' . Carbon::today()->subDays(6)->toDateString() . '&hasta=' . Carbon::today()->toDateString());
        $r->assertOk()->assertSee('Toda la organización')->assertSee('2 de 2 página(s) integradas')->assertSee('Nota de Neiva 24');
        $r = $this->actingAs($this->admin)->get('/admin/inteligencia/general?tab=paginas');
        $r->assertOk()->assertSee('Neiva 24')->assertSee('Opa Noticias')->assertSee('Alcaldía 2027')->assertSee('Campañas frente al total');
        $this->actingAs($this->admin)->get('/admin/inteligencia/general?medio=neiva24')->assertOk()->assertSee('1 de 2 página(s) integradas')->assertSee('Nota de Neiva 24')->assertDontSee('Capturan a tres');
        $this->actingAs($this->admin)->get('/admin/inteligencia/general?paginas[]=' . $this->page->id . '&tab=publicaciones')->assertOk()->assertSee('Capturan a tres')->assertDontSee('Nota de Neiva 24');
        foreach (['audiencia', 'horarios'] as $tab) $this->actingAs($this->admin)->get('/admin/inteligencia/general?tab=' . $tab)->assertOk();

        // Tablero por páginas directo: la página fuera de campaña cuenta
        $t = app(AnalisisService::class)->tableroPaginas(MetaPage::all(), Tema::all(), Carbon::today()->subDays(6), Carbon::today());
        $this->assertSame(4, $t['resumen']['publicaciones']);
        $this->assertCount(2, $t['por_pagina']);

        // Recolectar desde la web, página por página (filtrado por medio): iniciar + pasos hasta terminar
        $this->actingAs($this->admin)->postJson('/admin/inteligencia/general/recolectar/iniciar', ['dias' => 2, 'medio' => 'neiva24'])->assertOk()->assertJsonPath('total', 1);
        $paso = $this->actingAs($this->admin)->postJson('/admin/inteligencia/general/recolectar/paso')->assertOk();
        $paso->assertJsonPath('terminado', true)->assertJsonPath('hecho', 1)->assertJsonPath('linea.pagina', 'Neiva 24');
        $this->assertNotEmpty($paso->json('linea.avisos'), 'Meta rechazó las métricas de esa página: el aviso debe llegar al administrador');
        $this->actingAs($this->admin)->postJson('/admin/inteligencia/general/recolectar/paso')->assertStatus(422);
    }

    public function test_consultor_ia_responde_con_diagnostico_recomendaciones_y_publicaciones(): void
    {
        $this->fakeGraph();
        $campana = $this->campana();
        app(RecolectorAudienciaService::class)->recolectar($this->page, 3);
        $otra = MetaPage::create(['page_id' => '222', 'name' => 'Neiva 24']);
        $otra->users()->attach($this->admin->id, ['page_access_token' => 'tok-2', 'is_active' => true]);
        PublicacionRed::create(['meta_page_id' => $otra->id, 'red' => 'facebook', 'post_id' => '222_1', 'tipo' => 'foto', 'texto' => 'Nota de Neiva 24', 'permalink' => 'https://fb.com/n24', 'publicado_en' => Carbon::now()->subDay(), 'alcance' => 5000, 'interacciones' => 100, 'comentarios' => 3]);

        $respuestaIa = [
            'respuesta' => 'El público está molesto con la inseguridad pero receptivo a propuestas concretas.',
            'diagnostico_emocional' => ['resumen' => 'Predomina el enojo con una franja de esperanza.', 'tono' => 'dividido', 'emociones' => [['emocion' => 'Enojo', 'peso' => 55, 'evidencia' => 'Preocupación por robos en el centro'], ['emocion' => 'Esperanza', 'peso' => 25, 'evidencia' => 'Feria de empleo bien recibida']]],
            'hallazgos' => ['La seguridad concentra la mayor interacción', 'Los videos superan a las fotos'],
            'recomendaciones' => [['accion' => 'Publicar un video corto con la respuesta de la policía', 'por_que' => 'La seguridad es el tema con más tasa', 'prioridad' => 'alta', 'plazo' => 'esta semana']],
            'publicaciones_sugeridas' => [['titulo' => 'Operativo en el centro', 'texto' => 'Así avanza el plan de seguridad en el centro de Neiva…', 'formato' => 'video', 'red' => 'facebook', 'mejor_momento' => 'martes 19:00', 'tema' => 'Seguridad', 'resultado_esperado' => 'alcance alto por el interés del tema']],
            'riesgos' => ['No responder a los comentarios enojados'],
            'datos_faltantes' => [],
        ];
        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $ia->shouldReceive('modelo')->andReturn('modelo-prueba');
        $ia->shouldReceive('json')->twice()->withArgs(function ($sis, $usr, $esq) {
            return str_contains($sis, 'consultor senior') && str_contains($usr, 'PREGUNTA: ¿Qué emociones') && str_contains($usr, 'DATOS AGREGADOS') && in_array('publicaciones_sugeridas', $esq['required'], true);
        })->andReturn($respuestaIa);
        $this->app->instance(ClaudeService::class, $ia);

        // Sobre toda la organización (2 páginas)
        $r = $this->actingAs($this->admin)->postJson('/admin/inteligencia/consultar', ['pregunta' => '¿Qué emociones predominan y qué publico esta semana?'])->assertOk();
        $r->assertJsonPath('consulta.respuesta.diagnostico_emocional.tono', 'dividido')->assertJsonPath('consulta.respuesta.publicaciones_sugeridas.0.titulo', 'Operativo en el centro')->assertJsonPath('consulta.ambito.tipo', 'general')->assertJsonPath('consulta.ambito.paginas_n', 2);
        // Sobre la campaña (1 página) con su contexto
        $r = $this->actingAs($this->admin)->postJson('/admin/inteligencia/consultar', ['pregunta' => '¿Qué emociones predominan frente a la seguridad?', 'campana_id' => $campana->id])->assertOk();
        $r->assertJsonPath('consulta.ambito.tipo', 'campana')->assertJsonPath('consulta.ambito.paginas_n', 1)->assertJsonPath('consulta.campana', 'Alcaldía 2027');
        $this->assertSame(2, \App\Models\ConsultaIa::count());

        // Validación y pestañas
        $this->actingAs($this->admin)->postJson('/admin/inteligencia/consultar', ['pregunta' => 'hola'])->assertStatus(422);
        $this->actingAs($this->admin)->get('/admin/inteligencia/general?tab=consultor')->assertOk()->assertSee('Consultor de IA')->assertSee('¿Qué emociones predominan y qué publico esta semana?');
        $this->actingAs($this->admin)->get('/admin/inteligencia/general?tab=comentarios')->assertOk()->assertSee('Emociones del público');
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$campana->id}?tab=consultor")->assertOk()->assertSee('Consultor de IA')->assertSee('frente a la seguridad');
        $this->actingAs($this->admin)->get('/admin/inteligencia')->assertOk()->assertSee('Consultas a la IA')->assertSee('Alcaldía 2027');
    }

    public function test_clasificar_y_leer_comentarios_por_pasos_explica_lo_que_no_pudo(): void
    {
        $this->fakeGraph();
        $campana = $this->campana();
        app(RecolectorAudienciaService::class)->recolectar($this->page, 3);
        [$seguridad] = $campana->temas()->pluck('id')->all();

        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $ia->shouldReceive('modelo')->andReturn('modelo');
        // Solo devuelve una de las tres: las otras quedan revisadas sin tema (no se piden en bucle)
        $ia->shouldReceive('json')->once()->withArgs(fn($sis, $usr, $esq, $max = 0, $effort = '') => $esq['required'] === ['asignaciones'] && $effort === 'low')
            ->andReturn(['asignaciones' => [['n' => 0, 'tema_id' => $seguridad, 'confianza' => 90]]]);
        $ia->shouldReceive('json')->once()->withArgs(fn($sis, $usr, $esq) => str_contains($usr, 'COMENTARIOS (8)'))
            ->andReturn(['a_favor' => 5, 'en_contra' => 2, 'neutro' => 1, 'emociones' => ['enojo' => 4], 'preocupaciones' => ['Robos'], 'palabras' => ['robo'], 'resumen' => 'Piden más policía.']);
        $this->app->instance(ClaudeService::class, $ia);

        $url = route('inteligencia.analizar.paso', $campana);
        $d = $this->actingAs($this->admin)->postJson($url, ['inicio' => true])->assertOk()->json();
        $this->assertSame('clasificar', $d['fase']);
        $this->assertSame(3, $d['clasificadas']);
        $this->assertSame(0, $d['pendientes']);
        $this->assertSame(2, PublicacionRed::where('tema_fuente', 'ia')->whereNull('tema_id')->count());

        $d = $this->actingAs($this->admin)->postJson($url)->assertOk()->json();
        $this->assertSame('comentarios', $d['fase']);
        $this->assertSame(1, $d['lecturas']);
        $this->assertStringContainsString('8 comentarios leídos', $d['lineas'][0]['texto']);

        $d = $this->actingAs($this->admin)->postJson($url)->assertOk()->json();
        $this->assertTrue($d['terminado']);
        $this->assertSame(1, ComentarioAnalisis::count());

        // La campaña muestra los botones por pasos (sin formularios que el hosting corte)
        $this->actingAs($this->admin)->get(route('inteligencia.show', $campana))->assertOk()
            ->assertSee('id="btn-analizar"', false)->assertSee('data-recolectar="7"', false)->assertSee('🌐 Radar web');
    }

    public function test_radar_web_investiga_por_pasos_lista_fuentes_y_alimenta_al_consultor(): void
    {
        (require database_path('migrations/2026_10_11_000001_create_radar_web_table.php'))->up();
        $campana = $this->campana();

        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $ia->shouldReceive('modelo')->andReturn('modelo');
        $json = json_encode(['resumen' => 'Se habla de robos en el centro.', 'hallazgos' => [
            ['url' => 'https://diariodelhuila.com/robos-neiva', 'titulo' => 'Aumentan los robos en Neiva', 'medio' => 'Diario del Huila', 'fecha' => '2026-09-20', 'tono' => 'desfavorable', 'relevancia' => 'riesgo', 'resumen' => 'Comerciantes denuncian robos.', 'actores' => ['Policía Metropolitana']],
            ['url' => 'https://lanacion.com.co/empleo', 'titulo' => 'Feria de empleo', 'medio' => 'La Nación', 'fecha' => null, 'tono' => 'favorable', 'relevancia' => 'oportunidad', 'resumen' => 'Mil vacantes.', 'actores' => []],
            ['url' => 'no-es-url', 'titulo' => 'Inventado'],
        ]], JSON_UNESCAPED_UNICODE);
        $ia->shouldReceive('investigar')->times(3)->withArgs(fn($sis, $usr, $ubic) => str_contains($usr, 'Neiva') && $ubic['country'] === 'CO')
            ->andReturn(['texto' => "Busqué en la web.\n```json\n{$json}\n```", 'fuentes' => [['url' => 'https://diariodelhuila.com/robos-neiva', 'titulo' => 'Aumentan los robos', 'fecha' => '2026-09-20'], ['url' => 'https://otro.co/x', 'titulo' => 'Otra fuente', 'fecha' => null]], 'busquedas' => 2, 'pausado' => false]);
        $ia->shouldReceive('json')->once()->withArgs(fn($sis, $usr, $esq) => in_array('actores', $esq['required'], true) && str_contains($usr, 'Aumentan los robos en Neiva'))
            ->andReturn(['resumen' => 'La seguridad domina la conversación en Neiva.', 'temas' => [['tema' => 'Seguridad', 'intensidad' => 'alta', 'tono' => 'desfavorable', 'que_se_dice' => 'Robos en el centro']],
                'actores' => [['nombre' => 'Policía Metropolitana', 'tipo' => 'institución', 'postura' => 'responde a denuncias']], 'oportunidades' => ['Proponer plan de seguridad'], 'alertas' => ['Críticas por robos'],
                'recomendaciones' => [['accion' => 'Publicar propuesta de seguridad', 'por_que' => 'Es el tema más comentado', 'prioridad' => 'alta']]]);
        $this->app->instance(ClaudeService::class, $ia);

        $ini = $this->actingAs($this->admin)->postJson(route('inteligencia.radar.iniciar', $campana), ['enfoque' => 'seguridad'])->assertOk()->json();
        $this->assertSame(4, $ini['total']); // 2 temas + conversación general + síntesis
        $paso = fn() => $this->actingAs($this->admin)->postJson(url("/admin/inteligencia/{$campana->id}/radar/{$ini['id']}/paso"))->assertOk()->json();
        for ($i = 0; $i < 3; $i++) $this->assertFalse($paso()['terminado']);
        $fin = $paso();
        $this->assertTrue($fin['terminado']);
        $this->assertSame('listo', $fin['estado']);

        $radar = \App\Models\RadarWeb::first();
        $this->assertCount(2, $radar->hallazgos); // la URL inválida se descarta y no se duplica entre frentes
        $porUrl = collect($radar->hallazgos)->keyBy('url');
        $this->assertTrue($porUrl['https://diariodelhuila.com/robos-neiva']['verificada']);
        $this->assertFalse($porUrl['https://lanacion.com.co/empleo']['verificada']);
        $this->assertSame(6, $radar->busquedas);

        $this->actingAs($this->admin)->get(route('inteligencia.show', [$campana, 'tab' => 'radar']))->assertOk()
            ->assertSee('Aumentan los robos en Neiva')->assertSee('La seguridad domina la conversación en Neiva.')->assertSee('Fuentes analizadas (2)')
            ->assertSee('Otras páginas que la búsqueda consultó (1)')->assertSee('Publicar propuesta de seguridad');
        $this->assertStringContainsString('INVESTIGACIÓN WEB', \App\Services\Inteligencia\RadarWebService::contextoParaConsultor($campana));
    }

    public function test_sin_clave_de_ia_el_modulo_sigue_funcionando(): void
    {
        config(['services.anthropic.key' => '']);
        $campana = $this->campana();
        $this->assertSame(0, app(ClasificadorService::class)->clasificarCampana($campana));
        $this->assertSame(0, app(ComentariosService::class)->analizarCampana($campana));
        $this->actingAs($this->admin)->get('/admin/inteligencia')->assertOk()->assertSee('ANTHROPIC_API_KEY');
        $this->actingAs($this->admin)->post("/admin/inteligencia/{$campana->id}/analizar")->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, Artisan::call('inteligencia:analizar'));
    }
}
