<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;
use App\Models\ConsultaIa;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Consultor de IA: responde preguntas libres (políticas o comerciales) sobre los
 * datos agregados de un conjunto de páginas en un periodo. Devuelve un
 * diagnóstico emocional del público, hallazgos, recomendaciones de acción y
 * publicaciones sugeridas para la siguiente semana. Solo usa datos agregados.
 */
class ConsultorService
{
    public const EMOCIONES = ['alegria' => 'Alegría', 'confianza' => 'Confianza', 'esperanza' => 'Esperanza', 'enojo' => 'Enojo', 'miedo' => 'Miedo', 'tristeza' => 'Tristeza', 'desconfianza' => 'Desconfianza', 'indiferencia' => 'Indiferencia'];

    public function __construct(private AnalisisService $analisis, private ClaudeService $ia)
    {
    }

    /**
     * @param Collection $paginas páginas sobre las que se responde
     * @param Collection $temas   temas para agrupar (los de la campaña o todos)
     * @param array      $ambito  descripción del alcance (tipo, medio, paginas, desde, hasta…) que se guarda con la consulta
     */
    public function consultar(string $pregunta, Collection $paginas, Collection $temas, Carbon $desde, Carbon $hasta, array $ambito, ?Campana $campana = null, ?int $userId = null): ConsultaIa
    {
        if (!$this->ia->configurado()) throw new \RuntimeException('La IA no está configurada (ANTHROPIC_API_KEY).');
        if ($paginas->isEmpty()) throw new \RuntimeException('No hay páginas con datos en ese alcance.');

        $tablero = $this->analisis->tableroPaginas($paginas, $temas, $desde, $hasta);
        $datos = $this->datosParaIa($tablero);

        $quien = $campana
            ? "la campaña \"{$campana->nombre}\"" . ($campana->territorio ? " en {$campana->territorio}" : '')
            : ($ambito['titulo'] ?? 'la organización');
        $sistema = "Eres un consultor senior de comunicación estratégica, marketing político y comercial, y análisis de opinión pública. "
            . "Asesoras a {$quien}, un grupo de medios digitales del Huila (Colombia) con páginas de Facebook e Instagram. "
            . "Recibes DATOS AGREGADOS del periodo (publicaciones por tema, formato, red y página; audiencia por edad, género y ciudad; horarios; "
            . "lectura de comentarios: tono, emociones, preocupaciones, palabras; tendencias) y una PREGUNTA del equipo, que puede ser política o comercial. "
            . "Responde en español, directo, con cifras redondeadas y sin inventar datos: si algo no está en los datos, dilo en 'datos_faltantes'. "
            . "Habla siempre de segmentos (edades, ciudades, temas), nunca de personas individuales. "
            . "El diagnóstico emocional debe explicar qué siente el público y por qué (con evidencia de preocupaciones y palabras). "
            . "Las recomendaciones deben ser acciones concretas para esta semana. "
            . "Las publicaciones sugeridas deben estar listas para publicar: título, texto completo (hasta 500 caracteres, tono del medio), formato, red, mejor momento según los horarios y tema, con el resultado esperado apoyado en los datos.";
        $usuario = "PREGUNTA: {$pregunta}\n\n"
            . "Periodo: {$desde->format('d/m/Y')} a {$hasta->format('d/m/Y')}. Páginas: " . $paginas->pluck('name')->take(15)->implode(', ') . ($paginas->count() > 15 ? ' y ' . ($paginas->count() - 15) . ' más' : '') . ".\n"
            . ($campana?->descripcion ? "Contexto de la campaña: {$campana->descripcion}\n" : '')
            . "\nDATOS AGREGADOS:\n" . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $respuesta = $this->ia->json($sistema, $usuario, self::esquema(), 8000);
        $respuesta = $this->normalizar($respuesta);

        return ConsultaIa::create([
            'user_id' => $userId, 'campana_id' => $campana?->id, 'ambito' => $ambito + ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'paginas_n' => $paginas->count()],
            'pregunta' => $pregunta, 'respuesta' => $respuesta, 'modelo' => $this->ia->modelo(),
        ]);
    }

    /** Preguntas de ejemplo para el equipo (políticas y comerciales). */
    public static function ejemplos(): array
    {
        return [
            'Política' => [
                '¿Qué emociones predominan en la gente frente a la seguridad y cómo debería responder la campaña?',
                '¿Qué temas me conviene impulsar esta semana para ganar confianza en Neiva y qué debo evitar?',
                '¿Cómo percibe el público al candidato según los comentarios y qué mensaje recomiendas?',
            ],
            'Comercial' => [
                '¿Qué tipo de contenido conecta mejor con el público de 25 a 44 años para vender pauta a comercios locales?',
                '¿Qué horarios y formatos recomiendas para una campaña de un restaurante o almacén en Neiva?',
                '¿Qué preocupaciones del público puede aprovechar una marca para ser relevante?',
            ],
            'Contenido' => [
                'Propón cinco publicaciones para la próxima semana con el mayor alcance esperado.',
                '¿Por qué bajó la interacción y qué cambio concreto haría?',
            ],
        ];
    }

    private function datosParaIa(array $t): array
    {
        $d = $t;
        unset($d['horarios']['matriz'], $d['horarios']['en_linea'], $d['serie']);
        $d['serie_resumen'] = array_map(fn($x) => [$x['fecha'], $x['alcance_pagina'], $x['publicaciones'], $x['interacciones']], $t['serie']);
        $d['por_pagina'] = array_slice($t['por_pagina'], 0, 25);
        $d['mejores'] = array_slice($t['mejores'], 0, 8);
        if (isset($d['comentarios']['resumenes'])) $d['comentarios']['resumenes'] = array_slice($d['comentarios']['resumenes'], 0, 6);
        return $d;
    }

    private static function esquema(): array
    {
        $texto = ['type' => 'string'];
        $lista = ['type' => 'array', 'items' => $texto, 'maxItems' => 8];
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['respuesta', 'diagnostico_emocional', 'hallazgos', 'recomendaciones', 'publicaciones_sugeridas', 'riesgos', 'datos_faltantes'],
            'properties' => [
                'respuesta' => $texto,
                'diagnostico_emocional' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['resumen', 'tono', 'emociones'],
                    'properties' => [
                        'resumen' => $texto,
                        'tono' => ['type' => 'string', 'enum' => ['favorable', 'dividido', 'desfavorable', 'sin datos']],
                        'emociones' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['emocion', 'peso', 'evidencia'],
                            'properties' => ['emocion' => $texto, 'peso' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'evidencia' => $texto]]],
                    ],
                ],
                'hallazgos' => $lista,
                'recomendaciones' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['accion', 'por_que', 'prioridad', 'plazo'],
                    'properties' => ['accion' => $texto, 'por_que' => $texto, 'prioridad' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']], 'plazo' => $texto]]],
                'publicaciones_sugeridas' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['titulo', 'texto', 'formato', 'red', 'mejor_momento', 'tema', 'resultado_esperado'],
                    'properties' => ['titulo' => $texto, 'texto' => $texto, 'formato' => $texto, 'red' => $texto, 'mejor_momento' => $texto, 'tema' => $texto, 'resultado_esperado' => $texto]]],
                'riesgos' => $lista,
                'datos_faltantes' => $lista,
            ],
        ];
    }

    private function normalizar(array $r): array
    {
        $lista = fn($v) => array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', (array) $v)));
        $emo = array_values(array_filter(array_map(fn($e) => is_array($e) ? ['emocion' => (string) ($e['emocion'] ?? ''), 'peso' => max(0, min(100, (int) ($e['peso'] ?? 0))), 'evidencia' => (string) ($e['evidencia'] ?? '')] : null, (array) data_get($r, 'diagnostico_emocional.emociones', []))));
        return [
            'respuesta' => trim((string) ($r['respuesta'] ?? '')),
            'diagnostico_emocional' => ['resumen' => trim((string) data_get($r, 'diagnostico_emocional.resumen', '')), 'tono' => (string) data_get($r, 'diagnostico_emocional.tono', 'sin datos'), 'emociones' => $emo],
            'hallazgos' => $lista($r['hallazgos'] ?? []),
            'recomendaciones' => array_values(array_filter(array_map(fn($x) => is_array($x) ? ['accion' => (string) ($x['accion'] ?? ''), 'por_que' => (string) ($x['por_que'] ?? ''), 'prioridad' => in_array($x['prioridad'] ?? '', ['alta', 'media', 'baja'], true) ? $x['prioridad'] : 'media', 'plazo' => (string) ($x['plazo'] ?? '')] : null, (array) ($r['recomendaciones'] ?? [])))),
            'publicaciones_sugeridas' => array_values(array_filter(array_map(fn($x) => is_array($x) ? array_map('strval', array_intersect_key($x + ['titulo' => '', 'texto' => '', 'formato' => '', 'red' => '', 'mejor_momento' => '', 'tema' => '', 'resultado_esperado' => ''], array_flip(['titulo', 'texto', 'formato', 'red', 'mejor_momento', 'tema', 'resultado_esperado']))) : null, (array) ($r['publicaciones_sugeridas'] ?? [])))),
            'riesgos' => $lista($r['riesgos'] ?? []),
            'datos_faltantes' => $lista($r['datos_faltantes'] ?? []),
        ];
    }
}
