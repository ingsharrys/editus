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

    public function analizar(PublicacionRed $pub, Campana $campana): bool
    {
        $token = $this->tokens->forPage($pub->page->page_id);
        if (!$token) return false;
        $textos = $this->textos($pub, $token);
        if (count($textos) < self::MINIMO) return false;

        $sistema = "Eres analista de opinión pública de la campaña \"{$campana->nombre}\". Lees comentarios de redes sociales y produces una lectura AGREGADA: "
            . "cuántos están a favor, en contra o neutros respecto a la publicación o al tema, las preocupaciones que más se repiten (frases cortas, en español), "
            . "las palabras o expresiones más frecuentes y un resumen de 2 o 3 frases con lo que la gente pide o critica. "
            . "No menciones nombres de personas ni cites comentarios textuales. Responde solo con el JSON pedido.";
        $usuario = "PUBLICACIÓN:\n" . Str::limit(trim((string) $pub->texto), 800, '…') . "\n\nCOMENTARIOS (" . count($textos) . "):\n"
            . implode("\n", array_map(fn($t, $i) => ($i + 1) . '. ' . $t, $textos, array_keys($textos)));
        $esquema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['a_favor', 'en_contra', 'neutro', 'preocupaciones', 'palabras', 'resumen'],
            'properties' => [
                'a_favor' => ['type' => 'integer'], 'en_contra' => ['type' => 'integer'], 'neutro' => ['type' => 'integer'],
                'preocupaciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
                'palabras' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 15],
                'resumen' => ['type' => 'string'],
            ],
        ];
        $r = $this->ia->json($sistema, $usuario, $esquema, 4000);
        ComentarioAnalisis::updateOrCreate(['publicacion_id' => $pub->id], [
            'total' => count($textos),
            'a_favor' => (int) ($r['a_favor'] ?? 0), 'en_contra' => (int) ($r['en_contra'] ?? 0), 'neutro' => (int) ($r['neutro'] ?? 0),
            'preocupaciones' => array_values(array_filter(array_map('strval', (array) ($r['preocupaciones'] ?? [])))),
            'palabras' => array_values(array_filter(array_map(fn($p) => mb_strtolower(trim((string) $p)), (array) ($r['palabras'] ?? [])))),
            'resumen' => Str::limit((string) ($r['resumen'] ?? ''), 1500, ''),
            'analizado_en' => now(),
        ]);
        return true;
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
