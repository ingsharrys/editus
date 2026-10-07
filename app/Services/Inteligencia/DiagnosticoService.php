<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;
use App\Models\DiagnosticoIa;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Diagnóstico estratégico: la IA recibe los indicadores ya calculados (KPIs con variación,
 * emociones, comportamiento, impulsores del alcance, tendencias y pronósticos con su rango)
 * y los convierte en un informe accionable según el enfoque (política, medio, comercio):
 * estado, lectura emocional, comportamientos, tendencias, escenarios, oportunidades,
 * riesgos, acciones con KPI y meta, y un plan de publicaciones para la semana.
 */
class DiagnosticoService
{
    public function __construct(private AnalisisService $analisis, private InteligenciaAvanzadaService $avanzada, private ClaudeService $ia)
    {
    }

    public function generar(Collection $paginas, Collection $temas, Carbon $desde, Carbon $hasta, string $enfoque, array $ambito, ?Campana $campana = null, ?int $userId = null): DiagnosticoIa
    {
        if (!$this->ia->configurado()) throw new \RuntimeException('La IA no está configurada (ANTHROPIC_API_KEY).');
        if ($paginas->isEmpty()) throw new \RuntimeException('No hay páginas con datos en ese alcance.');

        $tablero = $this->analisis->tableroPaginas($paginas, $temas, $desde, $hasta);
        $av = $this->avanzada->analizar($paginas, $temas, $desde, $hasta, $enfoque, $tablero);
        if ($av['calidad']['publicaciones'] === 0) throw new \RuntimeException('No hay publicaciones en el periodo: recolecta datos antes de pedir el diagnóstico.');

        $quien = $campana ? "la campaña \"{$campana->nombre}\"" . ($campana->territorio ? " ({$campana->territorio})" : '') : ($ambito['titulo'] ?? 'la organización');
        $sistema = "Eres un estratega senior de comunicación digital y analista de datos de audiencia. Asesoras a {$quien}, con páginas de Facebook e Instagram en el Huila (Colombia).\n"
            . Enfoque::guia($enfoque) . "\n"
            . "Recibes INDICADORES YA CALCULADOS por el sistema (no los recalcules ni los inventes): KPIs con variación frente al periodo anterior, calidad de los datos, "
            . "comportamiento de la audiencia (mezcla de interacciones, intensidad por cada 1.000 alcanzados, franjas, días, frecuencia), impulsores del alcance "
            . "(rasgos de las publicaciones con su efecto sobre la mediana), emociones de los comentarios y su relación con el alcance, tendencias de 12 semanas "
            . "y pronósticos lineales a 4 semanas con rango del 80 %.\n"
            . "Reglas: escribe en español claro, con cifras redondeadas tomadas de los datos; cuando la calidad de los datos sea baja o falte algo, dilo y baja la confianza. "
            . "Habla de segmentos y temas, nunca de personas individuales. Los escenarios del pronóstico deben apoyarse en el rango calculado (bajo/esperado/alto). "
            . "Cada acción debe tener un KPI medible y una meta concreta para el próximo periodo. El plan de la semana son publicaciones listas para usar: "
            . "día y hora según las franjas y días que mejor rinden, formato según los impulsores, tema y texto completo (hasta 500 caracteres).";
        $usuario = "Periodo analizado: {$desde->format('d/m/Y')} a {$hasta->format('d/m/Y')} (se compara con {$av['periodo_anterior']['desde']} a {$av['periodo_anterior']['hasta']}).\n"
            . "Páginas: " . $paginas->pluck('name')->take(15)->implode(', ') . ($paginas->count() > 15 ? ' y ' . ($paginas->count() - 15) . ' más' : '') . ".\n"
            . ($campana?->descripcion ? "Contexto: {$campana->descripcion}\n" : '')
            . "\nINDICADORES:\n" . json_encode($this->datos($av, $tablero), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($campana && ($radar = RadarWebService::contextoParaConsultor($campana))) {
            $usuario .= "\n\n" . $radar . "\n(Contexto del territorio; las cifras salen solo de los INDICADORES.)";
        }

        $r = $this->ia->json($sistema, $usuario, self::esquema(), 10000, 'medium');

        return DiagnosticoIa::create([
            'campana_id' => $campana?->id, 'user_id' => $userId, 'enfoque' => $enfoque, 'modelo' => $this->ia->modelo(),
            'ambito' => $ambito + ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'paginas_n' => $paginas->count(), 'calidad' => $av['calidad']['nivel']],
            'resultado' => $r,
        ]);
    }

    /** Solo lo necesario para la IA (sin listas largas ni campos de interfaz). */
    private function datos(array $av, array $t): array
    {
        $c = $t['comentarios'];
        return [
            'enfoque' => $av['enfoque_nombre'],
            'calidad_datos' => $av['calidad'],
            'kpis' => array_map(fn($k) => array_intersect_key($k, array_flip(['nombre', 'valor', 'previo', 'variacion', 'variacion_unidad', 'estado'])), $av['kpis']),
            'hallazgos_calculados' => array_column($av['hallazgos'], 'texto'),
            'comentarios' => [
                'leidos' => $c['comentarios'], 'publicaciones_leidas' => $c['publicaciones'],
                'tono_pct' => ['a_favor' => $c['pct_favor'], 'neutro' => $c['pct_neutro'], 'en_contra' => $c['pct_contra']],
                'favorabilidad_neta' => $c['favorabilidad_neta'], $av['etiqueta_intencion'] . ' (%)' => $c['pct_intencion'],
                'emociones_pct' => collect($c['emociones'])->filter(fn($e) => $e['n'] > 0)->mapWithKeys(fn($e) => [$e['nombre'] => $e['pct']])->all(),
                'preocupaciones' => $c['preocupaciones'], 'preguntas' => $c['preguntas'] ?? [], 'quejas' => $c['quejas'] ?? [], 'pedidos' => $c['pedidos'] ?? [],
                'menciones' => $c['menciones'] ?? [], 'palabras' => array_slice($c['palabras'], 0, 15, true),
                'por_tema' => $c['por_tema'], 'lecturas' => array_map(fn($x) => $x['resumen'], array_slice($c['resumenes'], 0, 5)),
            ],
            'emocion_vs_alcance' => $av['emociones'],
            'comportamiento' => array_diff_key($av['comportamiento'], ['publicaciones' => 1]),
            'impulsores' => ['base' => $av['impulsores']['base'], 'positivos' => $av['impulsores']['positivos'], 'negativos' => $av['impulsores']['negativos']],
            'temas' => array_map(fn($x) => array_intersect_key($x, array_flip(['nombre', 'n', 'alcance_prom', 'tasa', 'mejor_formato'])), $t['por_tema']),
            'formatos' => array_map(fn($x) => array_intersect_key($x, array_flip(['nombre', 'n', 'alcance_prom', 'tasa'])), $t['por_formato']),
            'tendencia_por_tema' => array_map(fn($x) => array_intersect_key($x, array_flip(['tema', 'direccion', 'cambio_pct', 'publicaciones'])), $t['tendencias']['temas']),
            'semanas' => array_map(fn($s) => array_diff_key($s, ['inicio' => 1, 'fin' => 1]), $av['semanal']),
            'pronosticos' => array_map(fn($p) => array_intersect_key($p, array_flip(['nombre', 'disponible', 'futuro', 'r2', 'cambio', 'cambio_unidad', 'direccion', 'confianza'])), $av['pronosticos']),
            'mejores_publicaciones' => array_slice($t['mejores'], 0, 6),
            'peores_publicaciones' => $av['peores'],
            'mejores_momentos' => $t['horarios']['mejores_publicar'], 'momentos_mas_conectados' => $t['horarios']['mejores_en_linea'],
            'audiencia' => ['genero' => $t['demografia']['genero'], 'edad_genero' => $t['demografia']['edad_genero'], 'ciudades' => $t['demografia']['ciudades']],
            'por_pagina' => array_map(fn($x) => array_intersect_key($x, array_flip(['nombre', 'n', 'alcance', 'tasa', 'seguidores_facebook', 'nuevos_seguidores'])), array_slice($t['por_pagina'], 0, 20)),
        ];
    }

    public static function esquema(): array
    {
        $txt = ['type' => 'string'];
        $lista = ['type' => 'array', 'items' => $txt];
        $obj = fn(array $props) => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props];
        $nivel = ['type' => 'string', 'enum' => ['alta', 'media', 'baja']];
        return $obj([
            'resumen_ejecutivo' => $txt,
            'estado' => $obj(['nivel' => ['type' => 'string', 'enum' => ['bueno', 'atencion', 'critico']], 'titulo' => $txt, 'motivo' => $txt]),
            'emociones' => $obj([
                'lectura' => $txt, 'emocion_dominante' => $txt,
                'riesgo_reputacional' => $nivel, 'que_la_provoca' => $lista, 'como_responder' => $lista,
            ]),
            'comportamientos' => ['type' => 'array', 'items' => $obj(['titulo' => $txt, 'detalle' => $txt, 'evidencia' => $txt])],
            'tendencias' => ['type' => 'array', 'items' => $obj(['titulo' => $txt, 'direccion' => ['type' => 'string', 'enum' => ['sube', 'baja', 'estable']], 'detalle' => $txt])],
            'pronostico' => $obj([
                'lectura' => $txt, 'confianza' => $nivel,
                'escenario_optimista' => $txt, 'escenario_base' => $txt, 'escenario_riesgo' => $txt,
            ]),
            'oportunidades' => ['type' => 'array', 'items' => $obj(['titulo' => $txt, 'detalle' => $txt, 'impacto' => $nivel])],
            'riesgos' => ['type' => 'array', 'items' => $obj(['titulo' => $txt, 'detalle' => $txt, 'mitigacion' => $txt])],
            'acciones' => ['type' => 'array', 'items' => $obj(['accion' => $txt, 'por_que' => $txt, 'prioridad' => $nivel, 'plazo' => $txt, 'kpi' => $txt, 'meta' => $txt])],
            'plan_semana' => ['type' => 'array', 'items' => $obj(['dia' => $txt, 'hora' => $txt, 'red' => $txt, 'formato' => $txt, 'tema' => $txt, 'titulo' => $txt, 'texto' => $txt, 'objetivo' => $txt])],
            'datos_faltantes' => $lista,
        ]);
    }
}
