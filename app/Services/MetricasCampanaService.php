<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Models\MetaPost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas de las publicaciones de las campañas con plan de medios (lo que sale en los informes): alcance,
 * visualizaciones (reproducciones en los videos y en vivos) e interacciones, con MetaMetricasService, que sí sabe
 * leer los videos. Se refrescan solas cada pocas horas y con «Actualizar métricas» en Informes.
 */
class MetricasCampanaService
{
    /** Cada cuánto se vuelve a preguntar por una publicación. */
    public const CADA_HORAS = 6;
    /** Las publicaciones más viejas que esto ya no se refrescan (sus métricas casi no cambian). */
    public const DIAS = 120;

    public function __construct(private MetaMetricasService $metricas)
    {
    }

    /**
     * Actualiza las publicaciones pendientes (sin métricas o con métricas de hace más de CADA_HORAS horas).
     * $c: solo esa campaña; $forzar: todas las de la campaña (de cualquier fecha) aunque se hayan actualizado hace poco.
     * @return array{revisadas: int, actualizadas: int, errores: int, error: ?string}
     */
    public function actualizar(?Campaign $c = null, int $limite = 300, bool $forzar = false): array
    {
        $out = ['revisadas' => 0, 'actualizadas' => 0, 'errores' => 0, 'error' => null];
        if (!Schema::hasColumn('meta_posts', 'campaign_id')) return $out;
        $conFecha = Schema::hasColumn('meta_posts', 'last_insights_at');
        $posts = MetaPost::query()
            ->when($c, fn($q) => $q->where('campaign_id', $c->id), fn($q) => $q->whereIn('campaign_id', Campaign::where('plan_medios', true)->where('is_active', true)->pluck('id')))
            ->where('status', 'success')->whereNotNull('fb_post_id')->where('fb_post_id', '!=', '')->whereNotNull('meta_page_id')
            ->when(!$forzar, fn($q) => $q->where(fn($w) => $w->whereNull('published_at')->orWhere('published_at', '>=', now()->subDays(self::DIAS))))
            ->when($conFecha && !$forzar, fn($q) => $q->where(fn($w) => $w->whereNull('last_insights_at')->orWhere('last_insights_at', '<', now()->subHours(self::CADA_HORAS))))
            ->when($conFecha, fn($q) => $q->orderByRaw('last_insights_at IS NOT NULL')->orderBy('last_insights_at'))
            ->orderByDesc('published_at')->limit($limite)->get();
        if ($posts->isEmpty()) return $out;

        $paginas = MetaPage::whereIn('id', $posts->pluck('meta_page_id')->unique())->pluck('page_id', 'id');
        foreach ($posts->chunk(60) as $lote) {
            $lote = $lote->values();
            $items = $lote->map(fn(MetaPost $p) => [
                'red' => $p->network === 'instagram' ? 'instagram' : 'facebook', 'post_id' => (string) $p->fb_post_id,
                'page_id' => (string) ($paginas[$p->meta_page_id] ?? ''), 'tipo' => $p->type === 'video' ? 'video' : 'foto',
            ])->all();
            try {
                $res = $this->metricas->metricas($items);
            } catch (\Throwable $e) {
                Log::warning('[metricas campaña] no se pudieron leer', ['err' => $e->getMessage()]);
                $out['errores'] += $lote->count();
                $out['error'] ??= $e->getMessage();
                continue;
            }
            foreach ($lote as $i => $p) {
                $r = $res[$i] ?? null;
                $out['revisadas']++;
                if ($conFecha) $p->last_insights_at = now(); // aunque falle: se reintenta en CADA_HORAS horas, sin bloquear a las demás
                if (!$r || empty($r['ok'])) {
                    $out['errores']++;
                    $out['error'] ??= $r['error'] ?? null;
                    $p->saveQuietly();
                    continue;
                }
                // Solo se reemplaza lo que Meta sí entregó (un dato que falta no borra el que ya había)
                $vis = $r['reproducciones'] ?? $r['impresiones'];
                if ($r['alcance'] !== null) $p->alcance = (int) $r['alcance'];
                if ($vis !== null) $p->visualizaciones = (int) $vis;
                if ($r['interacciones'] !== null) $p->interacciones = (int) $r['interacciones'];
                $p->saveQuietly();
                $out['actualizadas']++;
            }
        }
        return $out;
    }
}
