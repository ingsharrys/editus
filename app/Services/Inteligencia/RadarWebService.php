<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;
use App\Models\RadarWeb;
use App\Models\Tema;
use Illuminate\Support\Str;

/**
 * Radar web: la IA investiga en internet (noticias, sitios de instituciones, columnas y
 * contenido público indexado) lo que se dice sobre los temas de una campaña en su territorio.
 * No entra a Facebook ni a Instagram: solo lo que está publicado abiertamente en la web.
 *
 * Avanza por pasos para no chocar con el límite de tiempo del hosting: cada paso investiga
 * un frente (un tema, o la conversación general) y el último redacta la síntesis.
 */
class RadarWebService
{
    public const MAX_TEMAS = 6;
    public const BUSQUEDAS_POR_FRENTE = 3;
    private const TONOS = ['favorable', 'desfavorable', 'neutral'];
    private const RELEVANCIAS = ['oportunidad', 'riesgo', 'contexto'];

    public function __construct(private ClaudeService $ia)
    {
    }

    public function iniciar(Campana $campana, ?int $userId = null, string $enfoque = ''): RadarWeb
    {
        $plan = $campana->temas()->orderBy('orden')->limit(self::MAX_TEMAS)->get()->map(fn(Tema $t) => [
            'nombre' => $t->nombre,
            'descripcion' => $t->descripcion,
            'palabras' => array_values((array) ($t->palabras_clave ?? [])),
        ])->all();
        $plan[] = ['nombre' => 'Conversación general y actores', 'descripcion' => 'Lo más comentado en el territorio, quiénes lo dicen y qué posturas hay', 'palabras' => [], 'general' => true];

        return RadarWeb::create([
            'campana_id' => $campana->id, 'user_id' => $userId, 'estado' => 'en_curso',
            'enfoque' => trim($enfoque) !== '' ? Str::limit(trim($enfoque), 290, '') : null,
            'plan' => $plan, 'avance' => 0, 'hallazgos' => [], 'fuentes' => [], 'lineas' => [],
        ]);
    }

    /** Ejecuta el siguiente paso. Devuelve la línea de bitácora del paso. */
    public function paso(RadarWeb $radar): array
    {
        if ($radar->estado !== 'en_curso') return ['frente' => 'Radar', 'ok' => $radar->estado === 'listo', 'detalle' => 'La investigación ya terminó'];
        $campana = $radar->campana;
        $plan = $radar->plan ?? [];

        if ($radar->avance < count($plan)) {
            $frente = $plan[$radar->avance];
            try {
                $r = $this->investigarFrente($campana, $frente, $radar->enfoque);
                $linea = ['frente' => $frente['nombre'], 'ok' => true, 'detalle' => count($r['hallazgos']) . ' hallazgo(s) · ' . count($r['fuentes']) . ' fuente(s) · ' . $r['busquedas'] . ' búsqueda(s)' . ($r['nota'] ? ' · ' . $r['nota'] : '')];
                $radar->hallazgos = $this->unirPorUrl($radar->hallazgos ?? [], $r['hallazgos']);
                $radar->fuentes = $this->unirPorUrl($radar->fuentes ?? [], $r['fuentes']);
                $radar->busquedas += $r['busquedas'];
            } catch (\Throwable $e) {
                \Log::warning('[inteligencia] radar web', ['radar' => $radar->id, 'frente' => $frente['nombre'], 'err' => $e->getMessage()]);
                $linea = ['frente' => $frente['nombre'], 'ok' => false, 'detalle' => 'No se pudo investigar: ' . ClaudeService::mensajeError($e)];
            }
            $radar->avance++;
            $radar->lineas = array_merge($radar->lineas ?? [], [$linea]);
            $radar->save();
            return $linea;
        }

        // Último paso: síntesis
        $hallazgos = $radar->hallazgos ?? [];
        if (!$hallazgos) {
            $radar->fill(['estado' => 'error', 'error' => 'La búsqueda no encontró publicaciones en la web sobre estos temas en el territorio. Prueba con otro enfoque o revisa los temas y el territorio de la campaña.'])->save();
            return ['frente' => 'Síntesis', 'ok' => false, 'detalle' => $radar->error];
        }
        try {
            $radar->sintesis = $this->sintetizar($campana, $hallazgos, $radar->enfoque);
            $radar->estado = 'listo';
            $linea = ['frente' => 'Síntesis', 'ok' => true, 'detalle' => 'Diagnóstico redactado con ' . count($hallazgos) . ' hallazgo(s)'];
        } catch (\Throwable $e) {
            $radar->fill(['estado' => 'error', 'error' => 'La IA no pudo redactar la síntesis: ' . ClaudeService::mensajeError($e)]);
            $linea = ['frente' => 'Síntesis', 'ok' => false, 'detalle' => $radar->error];
        }
        $radar->lineas = array_merge($radar->lineas ?? [], [$linea]);
        $radar->save();
        return $linea;
    }

    private function investigarFrente(Campana $campana, array $frente, ?string $enfoque): array
    {
        $territorio = $campana->territorio ?: 'el Huila (Colombia)';
        $quien = $this->quien($campana);
        $sistema = "Eres investigador de opinión pública y medios. Trabajas para {$quien}. "
            . "Usa la búsqueda web para encontrar lo publicado en los últimos 90 días: noticias de medios locales y nacionales, comunicados y sitios de instituciones, columnas, "
            . "y publicaciones públicas indexadas. Prioriza fuentes del territorio. "
            . "Usa SOLO información de las búsquedas: no inventes fuentes, URL, fechas ni cifras. Puedes nombrar figuras públicas, medios, instituciones y organizaciones; "
            . "no incluyas datos de ciudadanos particulares. Escribe en español.\n\n"
            . "Al final responde SOLO con un objeto JSON (sin texto antes ni después) con esta forma:\n"
            . '{"resumen": "2-3 frases sobre lo que se dice de este frente", "hallazgos": [{"url": "...", "titulo": "...", "medio": "nombre del medio o sitio", '
            . '"fecha": "AAAA-MM-DD o null", "tono": "favorable|desfavorable|neutral", "relevancia": "oportunidad|riesgo|contexto", '
            . '"resumen": "1-2 frases: qué dice y por qué importa a la campaña", "actores": ["figuras públicas, instituciones u organizaciones mencionadas"]}]}' . "\n"
            . "tono = cómo presenta la fuente el tema; relevancia = qué significa para la campaña. Máximo 8 hallazgos, los más relevantes.";
        $usuario = "CAMPAÑA: {$campana->nombre}\nTerritorio: {$territorio}\n"
            . (($tipo = $this->tipo($campana)) ? "Tipo: {$tipo}\n" : '')
            . ($campana->descripcion ? 'Contexto: ' . Str::limit($campana->descripcion, 1500) . "\n" : '')
            . ($enfoque ? "Enfoque pedido por el equipo: {$enfoque}\n" : '')
            . "\nFRENTE A INVESTIGAR: {$frente['nombre']}"
            . (!empty($frente['descripcion']) ? " — {$frente['descripcion']}" : '')
            . (!empty($frente['palabras']) ? "\nPalabras clave: " . implode(', ', $frente['palabras']) : '')
            . (!empty($frente['general'])
                ? "\nBusca los temas más comentados en {$territorio}, quiénes los impulsan (candidatos, funcionarios, medios, gremios) y qué posturas hay."
                : "\nBusca qué se está diciendo de este tema en {$territorio}.");

        $r = $this->ia->investigar($sistema, $usuario, ['city' => Str::limit($territorio, 60, ''), 'country' => 'CO'], self::BUSQUEDAS_POR_FRENTE);
        $json = ClaudeService::extraerJson($r['texto']);
        $verificadas = collect($r['fuentes'])->keyBy('url');
        $hallazgos = [];
        foreach ((array) ($json['hallazgos'] ?? []) as $h) {
            $url = trim((string) ($h['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) continue;
            $hallazgos[] = [
                'url' => $url,
                'titulo' => Str::limit(trim((string) ($h['titulo'] ?? '')) ?: ($verificadas[$url]['titulo'] ?? $url), 200, '…'),
                'medio' => Str::limit(trim((string) ($h['medio'] ?? '')) ?: (parse_url($url, PHP_URL_HOST) ?: ''), 80, ''),
                'fecha' => $this->fecha($h['fecha'] ?? null) ?? $this->fecha($verificadas[$url]['fecha'] ?? null),
                'tono' => in_array($h['tono'] ?? '', self::TONOS, true) ? $h['tono'] : 'neutral',
                'relevancia' => in_array($h['relevancia'] ?? '', self::RELEVANCIAS, true) ? $h['relevancia'] : 'contexto',
                'resumen' => Str::limit(trim((string) ($h['resumen'] ?? '')), 500, '…'),
                'actores' => array_slice(array_values(array_filter(array_map(fn($a) => Str::limit(trim((string) $a), 80, ''), (array) ($h['actores'] ?? [])))), 0, 6),
                'frente' => $frente['nombre'],
                // true si la URL vino en los resultados reales de la búsqueda (no solo en el texto de la IA)
                'verificada' => $verificadas->has($url),
            ];
        }
        $nota = null;
        if ($json === null) $nota = 'la IA no devolvió el formato esperado';
        elseif ($r['pausado']) $nota = 'búsqueda interrumpida, resultados parciales';
        return ['hallazgos' => $hallazgos, 'fuentes' => $r['fuentes'], 'busquedas' => $r['busquedas'], 'nota' => $nota, 'resumen' => (string) ($json['resumen'] ?? '')];
    }

    private function sintetizar(Campana $campana, array $hallazgos, ?string $enfoque): array
    {
        $sistema = "Eres consultor senior de comunicación estratégica y opinión pública. Asesoras a " . $this->quien($campana) . ". "
            . "Recibes HALLAZGOS de una investigación en la web (fuentes reales con su tono y relevancia). Redacta un diagnóstico en español: "
            . "de qué se habla en el territorio, con qué intensidad y tono, quiénes son los actores y su postura, oportunidades y alertas para la campaña, "
            . "y recomendaciones concretas para esta semana. No inventes nada que no esté en los hallazgos. Habla de actores públicos, nunca de ciudadanos particulares.";
        $lista = array_map(fn($h) => array_intersect_key($h, array_flip(['titulo', 'medio', 'fecha', 'tono', 'relevancia', 'resumen', 'actores', 'frente'])), array_slice($hallazgos, 0, 60));
        $usuario = "CAMPAÑA: {$campana->nombre}" . ($campana->territorio ? " · {$campana->territorio}" : '') . "\n"
            . ($campana->descripcion ? 'Contexto: ' . Str::limit($campana->descripcion, 1500) . "\n" : '')
            . ($enfoque ? "Enfoque pedido: {$enfoque}\n" : '')
            . "\nHALLAZGOS (" . count($lista) . "):\n" . json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $nivel = ['type' => 'string', 'enum' => ['alta', 'media', 'baja']];
        $esquema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['resumen', 'temas', 'actores', 'oportunidades', 'alertas', 'recomendaciones'],
            'properties' => [
                'resumen' => ['type' => 'string'],
                'temas' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['tema', 'intensidad', 'tono', 'que_se_dice'], 'properties' => [
                    'tema' => ['type' => 'string'], 'intensidad' => $nivel, 'tono' => ['type' => 'string', 'enum' => self::TONOS], 'que_se_dice' => ['type' => 'string'],
                ]]],
                'actores' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['nombre', 'tipo', 'postura'], 'properties' => [
                    'nombre' => ['type' => 'string'], 'tipo' => ['type' => 'string'], 'postura' => ['type' => 'string'],
                ]]],
                'oportunidades' => ['type' => 'array', 'items' => ['type' => 'string']],
                'alertas' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recomendaciones' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['accion', 'por_que', 'prioridad'], 'properties' => [
                    'accion' => ['type' => 'string'], 'por_que' => ['type' => 'string'], 'prioridad' => $nivel,
                ]]],
            ],
        ];
        return $this->ia->json($sistema, $usuario, $esquema, 8000);
    }

    /** Texto breve con la última investigación lista, para dárselo al consultor de IA. */
    public static function contextoParaConsultor(Campana $campana): ?string
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('radar_web')) return null;
        $r = RadarWeb::where('campana_id', $campana->id)->where('estado', 'listo')->latest()->first();
        if (!$r || !$r->sintesis) return null;
        $s = $r->sintesis;
        return "INVESTIGACIÓN WEB DEL " . $r->created_at->format('d/m/Y') . " (fuentes públicas fuera de las páginas integradas):\n"
            . json_encode(array_intersect_key($s, array_flip(['resumen', 'temas', 'actores', 'alertas', 'oportunidades'])), JSON_UNESCAPED_UNICODE);
    }

    private function tipo(Campana $campana): ?string
    {
        if (!$campana->campaign_id) return null;
        $tipo = $campana->campaign?->tipo;
        return $tipo && $tipo !== 'sistema' ? $tipo : null;
    }

    private function quien(Campana $campana): string
    {
        return "la campaña \"{$campana->nombre}\"" . ($campana->territorio ? " en {$campana->territorio}" : '') . ', del grupo de medios digitales del Huila (Colombia)';
    }

    private function unirPorUrl(array $actuales, array $nuevos): array
    {
        $porUrl = [];
        foreach (array_merge($actuales, $nuevos) as $x) {
            if (empty($x['url'])) continue;
            $porUrl[$x['url']] ??= $x;
        }
        return array_values($porUrl);
    }

    private function fecha($v): ?string
    {
        if (!$v || !is_string($v)) return null;
        try {
            return \Carbon\Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
