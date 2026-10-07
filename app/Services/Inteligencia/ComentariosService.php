<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;
use App\Models\ComentarioAnalisis;
use App\Models\PublicacionRed;
use App\Services\MetaPageTokenResolver;
use Illuminate\Support\Str;

/**
 * Lee los comentarios de una publicación y guarda SOLO la lectura agregada
 * (tono, preocupaciones, palabras, resumen). Los textos no se almacenan y
 * nunca se guardan nombres ni identificadores de quienes comentan.
 */
class ComentariosService
{
    public const MINIMO = 5;

    public function __construct(private GraphClient $graph, private MetaPageTokenResolver $tokens, private ClaudeService $ia)
    {
    }

    /** Analiza publicaciones de la campaña con comentarios suficientes y sin análisis reciente. */
    public function analizarCampana(Campana $campana, int $limite = 30): int
    {
        if (!$this->ia->configurado()) return 0;
        $paginas = $campana->paginas()->pluck('meta_pages.id');
        $pubs = PublicacionRed::with('analisis', 'page')->whereIn('meta_page_id', $paginas)
            ->where('comentarios', '>=', self::MINIMO)
            ->where('publicado_en', '>=', now()->subDays(60))
            ->orderByDesc('publicado_en')->limit($limite * 3)->get()
            ->filter(fn($p) => !$p->analisis || ($p->analisis->analizado_en->lt(now()->subDays(3)) && $p->comentarios >= $p->analisis->total * 1.5))
            ->take($limite);
        $n = 0;
        foreach ($pubs as $p) {
            try {
                if ($this->analizar($p, $campana)) $n++;
            } catch (\Throwable $e) {
                \Log::warning('[inteligencia] comentarios', ['pub' => $p->id, 'err' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /** Publicaciones de la campaña que necesitan lectura de comentarios (sin las ya intentadas). */
    public function candidatas(Campana $campana, array $excluir = [], int $limite = 3): \Illuminate\Support\Collection
    {
        return PublicacionRed::with('analisis', 'page')->whereIn('meta_page_id', $campana->paginas()->pluck('meta_pages.id'))
            ->where('comentarios', '>=', self::MINIMO)
            ->where('publicado_en', '>=', now()->subDays(60))
            ->when($excluir, fn($q) => $q->whereNotIn('id', $excluir))
            ->orderByDesc('comentarios')->limit($limite * 5)->get()
            ->filter(fn($p) => !$p->analisis || ($p->analisis->analizado_en->lt(now()->subDays(3)) && $p->comentarios >= $p->analisis->total * 1.5))
            ->take($limite)->values();
    }

    /** Publicaciones con comentarios suficientes en los últimos 60 días (para explicar por qué no hay lecturas). */
    public function conComentarios(Campana $campana): int
    {
        return PublicacionRed::whereIn('meta_page_id', $campana->paginas()->pluck('meta_pages.id'))
            ->where('comentarios', '>=', self::MINIMO)->where('publicado_en', '>=', now()->subDays(60))->count();
    }

    public function analizar(PublicacionRed $pub, Campana $campana): bool
    {
        return $this->analizarDetalle($pub, $campana)['ok'];
    }

    /** Igual que analizar() pero explica el motivo cuando no se pudo (sin token, Meta no entregó los comentarios…). */
    public function analizarDetalle(PublicacionRed $pub, Campana $campana): array
    {
        $token = $this->tokens->forPage($pub->page->page_id);
        if (!$token) return ['ok' => false, 'motivo' => 'La página no tiene un token activo de Facebook'];
        try {
            $textos = $this->textos($pub, $token);
        } catch (\Throwable $e) {
            return ['ok' => false, 'motivo' => 'Meta no entregó los comentarios: ' . preg_replace('/^Meta [^:]+: /', '', $e->getMessage())];
        }
        if (count($textos) < self::MINIMO) return ['ok' => false, 'motivo' => 'Meta entregó solo ' . count($textos) . ' comentario(s) con texto (se necesitan ' . self::MINIMO . ')'];

        $enfoque = Enfoque::deCampana($campana);
        $sistema = "Eres analista de audiencias y opinión pública para \"{$campana->nombre}\" (" . Enfoque::NOMBRES[$enfoque] . "). Lees comentarios de redes sociales y produces una lectura AGREGADA: "
            . "cuántos están a favor, en contra o neutros respecto a la publicación o al tema, cuántos comentarios expresan cada emoción "
            . "(alegria, confianza, esperanza, enojo, miedo, tristeza, desconfianza, indiferencia: una emoción dominante por comentario), las preocupaciones que más se repiten, "
            . "las preguntas que hace la gente, las quejas, lo que piden, las figuras públicas, instituciones, marcas o productos que mencionan, "
            . "cuántos comentarios muestran " . Enfoque::intencion($enfoque) . ", "
            . "las palabras o expresiones más frecuentes y un resumen de 2 o 3 frases con lo que la gente pide o critica. "
            . "Frases cortas en español. No menciones nombres de ciudadanos particulares ni cites comentarios textuales. Responde solo con el JSON pedido.";
        $usuario = "PUBLICACIÓN:\n" . Str::limit(trim((string) $pub->texto), 800, '…') . "\n\nCOMENTARIOS (" . count($textos) . "):\n"
            . implode("\n", array_map(fn($t, $i) => ($i + 1) . '. ' . $t, $textos, array_keys($textos)));
        $lista = fn(int $max) => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => $max];
        $esquema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['a_favor', 'en_contra', 'neutro', 'emociones', 'preocupaciones', 'preguntas', 'quejas', 'pedidos', 'menciones', 'intencion', 'palabras', 'resumen'],
            'properties' => [
                'a_favor' => ['type' => 'integer'], 'en_contra' => ['type' => 'integer'], 'neutro' => ['type' => 'integer'],
                'emociones' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys(ConsultorService::EMOCIONES),
                    'properties' => array_map(fn() => ['type' => 'integer', 'minimum' => 0], ConsultorService::EMOCIONES)],
                'preocupaciones' => $lista(8), 'preguntas' => $lista(6), 'quejas' => $lista(6), 'pedidos' => $lista(6), 'menciones' => $lista(8),
                'intencion' => ['type' => 'integer'],
                'palabras' => $lista(15),
                'resumen' => ['type' => 'string'],
            ],
        ];
        $r = $this->ia->json($sistema, $usuario, $esquema, 4000);
        ComentarioAnalisis::updateOrCreate(['publicacion_id' => $pub->id], [
            'total' => count($textos),
            'a_favor' => (int) ($r['a_favor'] ?? 0), 'en_contra' => (int) ($r['en_contra'] ?? 0), 'neutro' => (int) ($r['neutro'] ?? 0),
            'emociones' => \Illuminate\Support\Facades\Schema::hasColumn('comentarios_analisis', 'emociones') ? array_map(fn($k) => max(0, (int) data_get($r, "emociones.{$k}", 0)), array_combine(array_keys(ConsultorService::EMOCIONES), array_keys(ConsultorService::EMOCIONES))) : null,
            'preocupaciones' => array_values(array_filter(array_map('strval', (array) ($r['preocupaciones'] ?? [])))),
            'palabras' => array_values(array_filter(array_map(fn($p) => mb_strtolower(trim((string) $p)), (array) ($r['palabras'] ?? [])))),
        ] + (\Illuminate\Support\Facades\Schema::hasColumn('comentarios_analisis', 'preguntas') ? [
            'preguntas' => self::frases($r['preguntas'] ?? []), 'quejas' => self::frases($r['quejas'] ?? []), 'pedidos' => self::frases($r['pedidos'] ?? []),
            'menciones' => self::frases($r['menciones'] ?? []), 'intencion' => max(0, min(count($textos), (int) ($r['intencion'] ?? 0))),
        ] : []) + [
            'resumen' => Str::limit((string) ($r['resumen'] ?? ''), 1500, ''),
            'analizado_en' => now(),
        ]);
        return ['ok' => true, 'motivo' => count($textos) . ' comentarios leídos'];
    }

    /** Lista de frases cortas, sin vacíos ni duplicados. */
    private static function frases($lista, int $max = 8): array
    {
        $out = [];
        foreach ((array) $lista as $x) {
            $x = Str::limit(trim((string) $x), 140, '…');
            if ($x !== '' && !in_array(mb_strtolower($x), array_map('mb_strtolower', $out), true)) $out[] = $x;
        }
        return array_slice($out, 0, $max);
    }

    /** Textos de los comentarios (hasta 150), sin autor. */
    private function textos(PublicacionRed $pub, string $token): array
    {
        $campo = $pub->red === 'instagram' ? 'text' : 'message';
        $params = $pub->red === 'instagram' ? ['fields' => 'text', 'limit' => 50] : ['fields' => 'message', 'limit' => 100, 'filter' => 'stream'];
        $items = $this->graph->listar("{$pub->post_id}/comments", $params, $token, 3);
        $textos = [];
        foreach ($items as $c) {
            $t = trim((string) ($c[$campo] ?? ''));
            if ($t === '' || mb_strlen($t) < 2) continue;
            $textos[] = Str::limit(preg_replace('/\s+/', ' ', $t), 280, '…');
            if (count($textos) >= 150) break;
        }
        return $textos;
    }
}
