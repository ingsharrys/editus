<?php

namespace App\Services\Inteligencia;

use App\Models\Campana;
use App\Models\PublicacionRed;
use App\Models\Tema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Clasifica publicaciones por tema de campaña con la IA (corregible a mano desde el panel). */
class ClasificadorService
{
    public const LOTE = 25;

    public function __construct(private ClaudeService $ia)
    {
    }

    /** Clasifica las publicaciones sin tema de las páginas de la campaña. Devuelve cuántas quedaron clasificadas. */
    public function clasificarCampana(Campana $campana, int $limite = 200): int
    {
        $temas = $campana->temas()->get();
        if ($temas->isEmpty() || !$this->ia->configurado()) return 0;
        $paginas = $campana->paginas()->pluck('meta_pages.id');
        $pendientes = PublicacionRed::whereIn('meta_page_id', $paginas)
            ->whereNull('tema_id')->whereNull('tema_fuente')
            ->whereNotNull('texto')->where('texto', '!=', '')
            ->orderByDesc('publicado_en')->limit($limite)->get();
        $n = 0;
        foreach ($pendientes->chunk(self::LOTE) as $lote) {
            $n += $this->clasificarLote($lote, $temas, $campana);
        }
        return $n;
    }

    /** Publicaciones de la campaña que la IA todavía no ha clasificado. */
    public function pendientes(Campana $campana): int
    {
        return $this->consultaPendientes($campana)->count();
    }

    /**
     * Clasifica lotes de pendientes (las más recientes primero) hasta agotar el tiempo dado.
     * Pensado para avanzar por pasos desde el panel sin que el hosting corte la petición.
     */
    public function clasificarPorTiempo(Campana $campana, int $segundos = 45, int $lote = 50): int
    {
        $temas = $campana->temas()->get();
        if ($temas->isEmpty()) return 0;
        $inicio = microtime(true);
        $n = 0;
        do {
            $pubs = $this->consultaPendientes($campana)->orderByDesc('publicado_en')->limit($lote)->get();
            if ($pubs->isEmpty()) break;
            $n += $this->clasificarLote($pubs, $temas, $campana, 'low');
        } while (microtime(true) - $inicio < $segundos);
        return $n;
    }

    private function consultaPendientes(Campana $campana)
    {
        return PublicacionRed::whereIn('meta_page_id', $campana->paginas()->pluck('meta_pages.id'))
            ->whereNull('tema_id')->whereNull('tema_fuente')
            ->whereNotNull('texto')->where('texto', '!=', '');
    }

    public function clasificarLote(Collection $publicaciones, Collection $temas, Campana $campana, string $effort = 'medium'): int
    {
        $listaTemas = $temas->map(fn(Tema $t) => [
            'id' => $t->id, 'nombre' => $t->nombre, 'descripcion' => $t->descripcion,
            'palabras_clave' => array_values((array) ($t->palabras_clave ?? [])),
        ])->values()->all();
        $listaPubs = $publicaciones->values()->map(fn(PublicacionRed $p, $i) => [
            'n' => $i, 'red' => $p->red, 'tipo' => $p->tipo, 'texto' => Str::limit(trim((string) $p->texto), 600, '…'),
        ])->all();

        $sistema = "Eres analista de comunicación de la campaña \"{$campana->nombre}\"" . ($campana->territorio ? " ({$campana->territorio})" : '') . ".\n"
            . "Clasifica cada publicación en UNO de los temas dados según su contenido principal. "
            . "Si ninguna encaja razonablemente, usa tema_id null. Responde solo con el JSON pedido.";
        $usuario = "TEMAS:\n" . json_encode($listaTemas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nPUBLICACIONES:\n" . json_encode($listaPubs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $esquema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['asignaciones'],
            'properties' => ['asignaciones' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['n', 'tema_id', 'confianza'],
                'properties' => [
                    'n' => ['type' => 'integer'],
                    'tema_id' => ['type' => ['integer', 'null']],
                    'confianza' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                ],
            ]]],
        ];
        $respuesta = $this->ia->json($sistema, $usuario, $esquema, 8000, $effort);
        $validos = $temas->pluck('id')->all();
        $porN = $publicaciones->values();
        $n = 0;
        foreach ((array) ($respuesta['asignaciones'] ?? []) as $a) {
            $pub = $porN[(int) ($a['n'] ?? -1)] ?? null;
            if (!$pub) continue;
            $temaId = isset($a['tema_id']) && in_array((int) $a['tema_id'], $validos, true) ? (int) $a['tema_id'] : null;
            $pub->fill(['tema_id' => $temaId, 'tema_fuente' => 'ia', 'tema_confianza' => max(0, min(100, (int) ($a['confianza'] ?? 0)))])->save();
            $n++;
        }
        // Las que la IA no devolvió quedan revisadas sin tema, para no volver a pedirlas en bucle
        $respondidas = collect((array) ($respuesta['asignaciones'] ?? []))->pluck('n')->map(fn($v) => (int) $v)->flip();
        foreach ($porN as $i => $pub) {
            if ($respondidas->has($i) || $pub->tema_fuente) continue;
            $pub->fill(['tema_id' => null, 'tema_fuente' => 'ia', 'tema_confianza' => 0])->save();
            $n++;
        }
        return $n;
    }
}
