<?php

namespace Tests\Feature;

use App\Models\AudienciaDiaria;
use App\Models\Campana;
use App\Models\ComentarioAnalisis;
use App\Models\DiagnosticoIa;
use App\Models\MetaPage;
use App\Models\PublicacionRed;
use App\Models\Role;
use App\Models\Tema;
use App\Models\User;
use App\Services\Inteligencia\AnalisisService;
use App\Services\Inteligencia\ClaudeService;
use App\Services\Inteligencia\DiagnosticoService;
use App\Services\Inteligencia\InteligenciaAvanzadaService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Inteligencia 2.0: indicadores por enfoque, comportamiento, impulsores, pronósticos, simulador y diagnóstico IA. */
class InteligenciaAvanzadaTest extends TestCase
{
    private User $admin;
    private Campana $campana;
    private MetaPage $a;
    private MetaPage $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00'));
        config(['services.anthropic.key' => 'clave-de-prueba', 'app.timezone' => 'America/Bogota']);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        foreach (['2025_10_03_100000_create_inteligencia_audiencia', '2025_10_09_100000_consultor_ia_y_emociones', '2026_10_14_000001_ampliar_lectura_comentarios', '2026_10_14_000002_create_diagnosticos_ia_table'] as $m) {
            (require database_path("migrations/{$m}.php"))->up();
        }

        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $rol->id]);
        $this->a = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $this->b = MetaPage::create(['page_id' => '222', 'name' => 'Neiva 24']);
        foreach ([$this->a, $this->b] as $p) $p->users()->attach($this->admin->id, ['page_access_token' => 'tok', 'is_active' => true]);
        $this->campana = Campana::create(['nombre' => 'Alcaldía 2027', 'territorio' => 'Neiva']);
        $this->campana->paginas()->sync([$this->a->id, $this->b->id]);
        $seg = Tema::create(['campana_id' => $this->campana->id, 'nombre' => 'Seguridad', 'color' => '#dc2626', 'orden' => 0]);
        $emp = Tema::create(['campana_id' => $this->campana->id, 'nombre' => 'Empleo', 'color' => '#16a34a', 'orden' => 1]);
        $this->sembrar($seg->id, $emp->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 12 semanas de datos con patrones conocidos: el alcance crece semana a semana, los reels
     * de la noche rinden el doble, las publicaciones con enojo alcanzan más y los seguidores suben.
     */
    private function sembrar(int $seg, int $emp): void
    {
        $hoy = Carbon::today();
        $n = 0;
        for ($d = 83; $d >= 0; $d--) {
            $dia = $hoy->copy()->subDays($d);
            $semana = intdiv(83 - $d, 7);
            foreach ([$this->a, $this->b] as $k => $p) {
                AudienciaDiaria::create(['meta_page_id' => $p->id, 'red' => 'facebook', 'fecha' => $dia->toDateString(), 'alcance' => 2000 + 50 * $semana, 'seguidores' => 10000 + 30 * (83 - $d) + 5000 * $k, 'nuevos_seguidores' => 30]);
                if ($d % 2 === 1) continue; // publica día de por medio
                $reel = ($n % 3) === 0;
                $hora = $reel ? 20 : 9;
                $alcance = (int) ((1000 + 120 * $semana) * ($reel ? 2 : 1) * ($k ? 1.1 : 1));
                $pub = PublicacionRed::create([
                    'meta_page_id' => $p->id, 'red' => 'facebook', 'post_id' => "{$p->page_id}_{$n}", 'tipo' => $reel ? 'reel' : 'foto',
                    'texto' => $reel ? '¿Qué opinan del nuevo plan de seguridad? 🚨 #Neiva' : 'Feria de empleo este sábado en el centro de convenciones con más de 300 vacantes para jóvenes y madres cabeza de hogar.',
                    'permalink' => "https://fb.com/{$n}", 'publicado_en' => $dia->copy()->setTime($hora, 0),
                    'alcance' => $alcance, 'interacciones' => (int) ($alcance * ($reel ? 0.08 : 0.04)), 'reacciones' => (int) ($alcance * 0.03), 'comentarios' => $reel ? 30 : 6,
                    'compartidos' => (int) ($alcance * ($reel ? 0.02 : 0.004)), 'guardados' => 5, 'reproducciones' => $reel ? $alcance * 3 : null, 'tema_id' => $reel ? $seg : $emp,
                ]);
                if ($n % 2 === 0) ComentarioAnalisis::create([
                    'publicacion_id' => $pub->id, 'total' => 20, 'a_favor' => $reel ? 8 : 14, 'en_contra' => $reel ? 9 : 2, 'neutro' => $reel ? 3 : 4,
                    'emociones' => $reel ? ['enojo' => 9, 'miedo' => 4, 'esperanza' => 2] : ['esperanza' => 10, 'alegria' => 5],
                    'preocupaciones' => $reel ? ['Robos en el centro'] : ['Falta de empleo'], 'palabras' => ['seguridad', 'empleo'],
                    'preguntas' => $reel ? ['¿Cuándo llega la policía?'] : ['¿Dónde me inscribo?'], 'quejas' => $reel ? ['Inseguridad en el barrio'] : [],
                    'pedidos' => ['Más cámaras'], 'menciones' => ['Policía Metropolitana'], 'intencion' => $reel ? 2 : 6, 'resumen' => 'Lectura de prueba.', 'analizado_en' => now(),
                ]);
                $n++;
            }
        }
    }

    private function analisis(string $enfoque, ?Carbon $desde = null): array
    {
        $hasta = Carbon::today(); $desde ??= $hasta->copy()->subDays(27);
        $paginas = $this->campana->paginas()->get(); $temas = $this->campana->temas()->get();
        $t = app(AnalisisService::class)->tableroPaginas($paginas, $temas, $desde, $hasta);
        return [app(InteligenciaAvanzadaService::class)->analizar($paginas, $temas, $desde, $hasta, $enfoque, $t), $t];
    }

    public function test_indicadores_por_enfoque_comparan_con_el_periodo_anterior(): void
    {
        [$medio] = $this->analisis('medio');
        $this->assertSame(['alcance', 'alcance_prom', 'tasa', 'viralidad', 'reproducciones', 'nuevos_seguidores'], array_column($medio['kpis'], 'clave'));
        $alcance = $medio['kpis'][0];
        $this->assertGreaterThan(0, $alcance['variacion'], 'el alcance crece semana a semana');
        $this->assertSame('bien', $alcance['estado']);
        $this->assertSame('mil', $medio['kpis'][3]['formato']);
        $this->assertSame('media', $medio['calidad']['nivel'], '28 publicaciones: menos de 30');
        $this->assertSame('alta', $this->analisis('medio', Carbon::today()->subDays(59))[0]['calidad']['nivel']);

        [$pol, $t] = $this->analisis('politica');
        $this->assertSame('favorabilidad', $pol['kpis'][0]['clave']);
        $this->assertSame($t['comentarios']['favorabilidad_neta'], $pol['kpis'][0]['valor']);
        $this->assertStringContainsString('Esperanza', $pol['kpis'][1]['valor']);
        $this->assertArrayHasKey('favorabilidad', $pol['pronosticos']);

        [$com, $t] = $this->analisis('comercio');
        $this->assertSame('intencion', $com['kpis'][2]['clave']);
        $this->assertGreaterThan(0, $com['kpis'][2]['valor']);
        $this->assertSame('Intención de compra', $com['etiqueta_intencion']);
        $this->assertSame(1, $t['comentarios']['preguntas']['¿dónde me inscribo?'] > 0 ? 1 : 0);
    }

    public function test_impulsores_comportamiento_emociones_y_hallazgos(): void
    {
        [$av] = $this->analisis('medio');
        $top = $av['impulsores']['positivos'][0];
        $this->assertContains($top['valor'], ['Reel', 'Noche (18–23 h)', 'Con pregunta', 'Con hashtags', 'Con emojis', 'Seguridad', 'Texto corto (menos de 80 caracteres)']);
        $this->assertGreaterThan(30, $top['efecto_alcance']);
        $this->assertNotContains('Reel', array_column($av['impulsores']['negativos'], 'valor'));
        $this->assertContains('Reel', array_column($av['impulsores']['positivos'], 'valor'));
        // La noche rinde más que la mañana
        $fr = collect($av['comportamiento']['franjas'])->keyBy('clave');
        $this->assertGreaterThan($fr['manana']['mediana_alcance'], $fr['noche']['mediana_alcance']);
        $this->assertEqualsWithDelta(100, array_sum(array_column($av['comportamiento']['mezcla'], 'pct')), 0.5);
        $this->assertEquals('1', $av['comportamiento']['frecuencia_recomendada']['clave']);
        // Las publicaciones con enojo dominante (reels) alcanzan más que las de esperanza
        $emo = collect($av['emociones']['lista'])->keyBy('clave');
        $this->assertTrue($av['emociones']['suficiente']);
        $this->assertGreaterThan($emo['esperanza']['veces'], $emo['enojo']['veces']);
        $textos = implode(' | ', array_column($av['hallazgos'], 'texto'));
        $this->assertStringContainsString('Lo que más impulsa el alcance', $textos);
        $this->assertStringContainsString('Pronóstico', $textos);
    }

    public function test_pronostico_lineal_con_rango_y_confianza(): void
    {
        $r = InteligenciaAvanzadaService::regresion([10, 20, 30, 40, 50, 60], 2);
        $this->assertEqualsWithDelta(10, $r['pendiente'], 1e-9);
        $this->assertEqualsWithDelta(70, $r['futuro'][0]['esperado'], 1e-9);
        $this->assertEqualsWithDelta(80, $r['futuro'][1]['esperado'], 1e-9);
        $this->assertSame(1.0, (float) $r['r2']);
        $this->assertNull(InteligenciaAvanzadaService::regresion([1, null, 2]));
        $acotado = InteligenciaAvanzadaService::regresion([90, 95, 98, 99, 100], 3, 0, 100);
        $this->assertLessThanOrEqual(100, $acotado['futuro'][2]['alto']);

        [$av] = $this->analisis('medio');
        $p = $av['pronosticos']['alcance'];
        $this->assertTrue($p['disponible']);
        $this->assertSame('sube', $p['direccion']);
        $this->assertSame('alta', $p['confianza']);
        $this->assertCount(4, $p['futuro']);
        foreach ($p['futuro'] as $f) $this->assertTrue($f['bajo'] <= $f['esperado'] && $f['esperado'] <= $f['alto']);
        $this->assertCount(12, $av['semanal']);
        $this->assertSame('sube', $av['pronosticos']['seguidores']['direccion']);
    }

    public function test_simulador_ajusta_por_formato_y_franja(): void
    {
        $base = ['campana_id' => $this->campana->id];
        $reel = $this->actingAs($this->admin)->getJson('/admin/inteligencia/simular?' . http_build_query($base + ['formato' => 'reel', 'franja' => 'noche']))->assertOk()->json();
        $foto = $this->actingAs($this->admin)->getJson('/admin/inteligencia/simular?' . http_build_query($base + ['formato' => 'foto', 'franja' => 'manana']))->assertOk()->json();
        $this->assertTrue($reel['suficiente']);
        $this->assertGreaterThan($foto['alcance']['esperado'] * 1.5, $reel['alcance']['esperado']);
        $this->assertTrue($reel['alcance']['bajo'] <= $reel['alcance']['esperado'] && $reel['alcance']['esperado'] <= $reel['alcance']['alto']);
        $this->assertGreaterThan(0, $reel['interacciones']);
        $this->assertContains('Tendencia reciente', array_column($reel['factores'], 'nombre'));
        // Una página fuera del alcance se ignora (no filtra datos de otras páginas)
        $otra = MetaPage::create(['page_id' => '333', 'name' => 'Ajena']);
        $this->actingAs($this->admin)->getJson('/admin/inteligencia/simular?' . http_build_query($base + ['meta_page_id' => $otra->id]))->assertOk()->assertJsonPath('suficiente', true);
        $this->actingAs($this->admin)->getJson('/admin/inteligencia/simular?dia=9')->assertStatus(422);
    }

    public function test_diagnostico_ia_por_enfoque_y_su_historial(): void
    {
        $resultado = [
            'resumen_ejecutivo' => 'El alcance crece 40 % y los reels nocturnos lideran.',
            'estado' => ['nivel' => 'bueno', 'titulo' => 'Crecimiento sostenido', 'motivo' => 'Alcance y seguidores en alza.'],
            'emociones' => ['lectura' => 'Esperanza por el empleo, enojo por la seguridad.', 'emocion_dominante' => 'Esperanza', 'riesgo_reputacional' => 'media', 'que_la_provoca' => ['Robos'], 'como_responder' => ['Mostrar resultados']],
            'comportamientos' => [['titulo' => 'Comparten más los reels', 'detalle' => 'Viralidad doble', 'evidencia' => '20 por mil']],
            'tendencias' => [['titulo' => 'Seguridad en ascenso', 'direccion' => 'sube', 'detalle' => '+15 %']],
            'pronostico' => ['lectura' => 'Seguirá subiendo', 'confianza' => 'alta', 'escenario_optimista' => '+20 %', 'escenario_base' => '+10 %', 'escenario_riesgo' => '-5 %'],
            'oportunidades' => [['titulo' => 'Reels de empleo', 'detalle' => 'Combinar lo que más crece', 'impacto' => 'alta']],
            'riesgos' => [['titulo' => 'Enojo por inseguridad', 'detalle' => 'Comentarios negativos', 'mitigacion' => 'Responder con datos']],
            'acciones' => [['accion' => 'Publicar 3 reels nocturnos', 'por_que' => 'Doblan el alcance', 'prioridad' => 'alta', 'plazo' => 'esta semana', 'kpi' => 'Alcance por publicación', 'meta' => '+15 %']],
            'plan_semana' => [['dia' => 'Martes', 'hora' => '20:00', 'red' => 'Facebook', 'formato' => 'Reel', 'tema' => 'Seguridad', 'titulo' => 'Así avanza el plan', 'texto' => 'Texto listo…', 'objetivo' => 'Alcance']],
            'datos_faltantes' => [],
        ];
        $ia = \Mockery::mock(ClaudeService::class);
        $ia->shouldReceive('configurado')->andReturn(true);
        $ia->shouldReceive('modelo')->andReturn('modelo-prueba');
        $ia->shouldReceive('json')->twice()->withArgs(function ($sis, $usr, $esq) {
            return str_contains($sis, 'estratega senior') && str_contains($usr, 'INDICADORES')
                && str_contains($usr, 'impulsores') && str_contains($usr, 'pronosticos') && in_array('plan_semana', $esq['required'], true);
        })->andReturnUsing(function ($sis) use ($resultado) {
            $this->assertTrue(str_contains($sis, 'campaña política') || str_contains($sis, 'medio de comunicación'));
            return $resultado;
        });
        $this->app->instance(ClaudeService::class, $ia);

        $this->actingAs($this->admin)->postJson('/admin/inteligencia/diagnostico', ['campana_id' => $this->campana->id, 'enfoque' => 'politica'])
            ->assertOk()->assertJsonPath('diagnostico.resultado.estado.nivel', 'bueno')->assertJsonPath('diagnostico.enfoque', 'politica')->assertJsonPath('diagnostico.ambito.tipo', 'campana');
        $this->actingAs($this->admin)->postJson('/admin/inteligencia/diagnostico', ['enfoque' => 'medio', 'paginas' => [$this->a->id]])
            ->assertOk()->assertJsonPath('diagnostico.ambito.tipo', 'general')->assertJsonPath('diagnostico.ambito.paginas_n', 1);
        $this->actingAs($this->admin)->postJson('/admin/inteligencia/diagnostico', ['enfoque' => 'otro'])->assertStatus(422);
        $this->assertSame(2, DiagnosticoIa::count());

        // El esquema es compatible con las salidas estructuradas (sin límites numéricos ni de largo)
        $json = json_encode(ClaudeService::esquemaCompatible(DiagnosticoService::esquema()));
        $this->assertStringNotContainsString('maxItems', $json);

        $this->actingAs($this->admin)->get("/admin/inteligencia/{$this->campana->id}?tab=diagnostico&enfoque=politica")->assertOk()
            ->assertSee('Diagnóstico estratégico — Campaña política')->assertSee('Crecimiento sostenido');
        $this->actingAs($this->admin)->get('/admin/inteligencia/general?tab=diagnostico')->assertOk()->assertSee('Generar diagnóstico');
    }

    public function test_todas_las_pestanas_cargan_en_cada_enfoque(): void
    {
        $id = $this->campana->id;
        foreach (['politica', 'medio', 'comercio'] as $e) {
            foreach (['panorama', 'emociones', 'comportamiento', 'tendencias', 'contenido', 'audiencia', 'diagnostico', 'consultor', 'radar', 'publicaciones', 'informes', 'config'] as $tab) {
                $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?tab={$tab}&enfoque={$e}")->assertOk();
            }
            foreach (['panorama', 'emociones', 'comportamiento', 'tendencias', 'paginas', 'audiencia', 'diagnostico', 'consultor', 'publicaciones'] as $tab) {
                $this->actingAs($this->admin)->get("/admin/inteligencia/general?tab={$tab}&enfoque={$e}")->assertOk();
            }
        }
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?enfoque=comercio")->assertOk()
            ->assertSee('Calidad de los datos')->assertSee('Intención de compra')->assertSee('Lo que dicen los datos');
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?tab=emociones&enfoque=politica")->assertOk()
            ->assertSee('Favorabilidad neta')->assertSee('La voz del público')->assertSee('¿Cuándo llega la policía?', false)->assertSee('¿Qué emociones generan más alcance?', false);
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?tab=comportamiento")->assertOk()->assertSee('¿Qué hace que una publicación llegue a más gente?', false)->assertSee('Lo que impulsa');
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?tab=tendencias")->assertOk()->assertSee('Simulador')->assertSee('Semana a semana');
        // Los nombres de pestaña anteriores siguen funcionando
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?tab=comentarios")->assertOk()->assertSee('La voz del público');
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$id}?tab=pronostico")->assertOk()->assertSee('Simulador');
        // Sin datos: no se rompe
        $vacia = Campana::create(['nombre' => 'Vacía']);
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$vacia->id}")->assertOk()->assertSee('Insuficiente');
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$vacia->id}?tab=tendencias")->assertOk();
        $this->actingAs($this->admin)->get("/admin/inteligencia/{$vacia->id}?tab=comportamiento")->assertOk();
    }
}
