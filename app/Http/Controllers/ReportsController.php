<?php

namespace App\Http\Controllers;

use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    /**
     * Informe profesional de publicaciones: búsqueda, filtros combinables,
     * ordenamiento, KPIs del conjunto filtrado y gráfica de actividad.
     */
    public function posts(Request $request)
    {
        $user = Auth::user();
        [$query, $filters] = $this->buildQuery($request, $user);

        // ----- KPIs del conjunto filtrado -----
        $kpis = (clone $query)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as exitosas,
                SUM(CASE WHEN status = 'fail' THEN 1 ELSE 0 END) as fallidas,
                SUM(CASE WHEN status IN ('pending','queued') THEN 1 ELSE 0 END) as pendientes,
                COALESCE(SUM(alcance), 0) as alcance,
                COALESCE(SUM(visualizaciones), 0) as visualizaciones,
                COALESCE(SUM(interacciones), 0) as interacciones
            ")
            ->first();

        // ----- Actividad por día (para la gráfica) -----
        $activity = (clone $query)
            ->whereNotNull('published_at')
            ->groupBy(DB::raw('DATE(published_at)'))
            ->orderBy(DB::raw('DATE(published_at)'))
            ->get([
                DB::raw('DATE(published_at) as day'),
                DB::raw('COUNT(*) as posts'),
                DB::raw('COALESCE(SUM(alcance), 0) as alcance'),
            ]);

        // ----- Distribución por red y por tipo (del conjunto filtrado) -----
        $byNetwork = (clone $query)
            ->groupBy('network')
            ->pluck(DB::raw('COUNT(*) as c'), 'network');

        $byType = (clone $query)
            ->groupBy('type')
            ->pluck(DB::raw('COUNT(*) as c'), 'type');

        // ----- Tabla paginada -----
        $posts = (clone $query)
            ->with(['page:id,name,page_id', 'user:id,name', 'campaign:id,name'])
            ->orderBy($filters['sort'], $filters['dir'])
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        // ----- Catálogos para los filtros -----
        $pages = ($user->isAdmin() ? MetaPage::query() : $user->metaPages())
            ->orderBy('name')
            ->get(['meta_pages.id', 'meta_pages.name']);

        $campaigns = \App\Models\Campaign::orderBy('name')->get(['id', 'name', 'is_system']);

        $authors = $user->isAdmin()
            ? User::whereIn('id', MetaPost::query()->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('reports.posts', compact('posts', 'kpis', 'activity', 'byNetwork', 'byType', 'pages', 'authors', 'campaigns', 'filters'));
    }

    /**
     * Análisis de rendimiento: ranking de medios, mejores horas/días para
     * publicar, comparación de formatos y redes, y conclusiones automáticas.
     *
     * Metodología: solo publicaciones exitosas con alcance registrado.
     * Los promedios exigen un mínimo de muestras (n >= 3) para que una
     * conclusión sea considerada confiable.
     */
    public function analytics(Request $request)
    {
        $user = Auth::user();

        $days = in_array((int) $request->query('days'), [30, 90, 180, 365, 0], true)
            ? (int) $request->query('days') : 90;
        $network = in_array($request->query('network'), ['facebook', 'instagram'], true)
            ? $request->query('network') : null;

        $pagesCatalog = ($user->isAdmin() ? MetaPage::query() : $user->metaPages())
            ->orderBy('name')
            ->get(['meta_pages.id', 'meta_pages.name']);

        // Selección múltiple de medios para comparar (con compatibilidad
        // hacia atrás con el parámetro simple page_id)
        $campaignId = $request->query('campaign_id');
        $campaignsCatalog = \App\Models\Campaign::orderBy('name')->get(['id', 'name', 'is_system']);

        $pageIds = array_values(array_filter(array_map('intval', (array) $request->query('page_ids', []))));
        if (empty($pageIds) && $request->query('page_id')) {
            $pageIds = [(int) $request->query('page_id')];
        }
        $pageIds = array_values(array_intersect($pageIds, $pagesCatalog->pluck('id')->all()));

        $query = MetaPost::query()
            ->where('status', 'success')
            ->whereNotNull('published_at')
            ->where(fn($q) => $q->where('alcance', '>', 0)->orWhere('interacciones', '>', 0));

        if (!$user->isAdmin()) {
            $query->forUserPages($user->id);
        }
        if ($days > 0) {
            $query->where('published_at', '>=', now()->subDays($days));
        }
        if ($network) {
            $query->where('network', $network);
        }
        if (!empty($pageIds)) {
            $query->whereIn('meta_page_id', $pageIds);
        }
        if ($campaignId && $campaignsCatalog->contains('id', (int) $campaignId)) {
            $query->where('campaign_id', (int) $campaignId);
        }

        $posts = $query->get(['id', 'meta_page_id', 'user_id', 'campaign_id', 'type', 'network', 'message', 'published_at', 'alcance', 'visualizaciones', 'interacciones', 'fb_permalink_url']);

        $MIN_N = 3; // muestras mínimas para promediar con confianza

        $agg = function ($group) {
            $n = $group->count();
            $reach = (int) $group->sum('alcance');
            $inter = (int) $group->sum('interacciones');
            return [
                'n' => $n,
                'reach' => $reach,
                'inter' => $inter,
                'avg_reach' => $n ? (int) round($reach / $n) : 0,
                'avg_inter' => $n ? (int) round($inter / $n) : 0,
                'engagement' => $reach > 0 ? round($inter / $reach * 100, 2) : null,
            ];
        };

        // ----- Ranking de medios (páginas) -----
        $pageNames = $pagesCatalog->pluck('name', 'id');
        $byPage = $posts->groupBy('meta_page_id')
            ->map(function ($g, $pid) use ($agg, $pageNames) {
                $best = $g->sortByDesc('alcance')->first();
                return $agg($g) + [
                    'page_id' => (int) $pid,
                    'name' => $pageNames[$pid] ?? '—',
                    'best_post' => $best ? \Illuminate\Support\Str::limit($best->message ?: '(sin texto)', 60) : null,
                    'best_reach' => (int) ($best->alcance ?? 0),
                ];
            })
            ->sortByDesc('reach')
            ->values();

        // ----- Por hora del día (0-23) -----
        $byHour = collect(range(0, 23))->map(function ($h) use ($posts, $agg) {
            return ['hour' => $h] + $agg($posts->filter(fn($p) => (int) $p->published_at->format('G') === $h));
        });

        // ----- Por día de la semana (ISO: 1=Lun ... 7=Dom) -----
        $dowLabels = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $byDow = collect(range(1, 7))->map(function ($d) use ($posts, $agg, $dowLabels) {
            return ['dow' => $d, 'label' => $dowLabels[$d]] + $agg($posts->filter(fn($p) => $p->published_at->dayOfWeekIso === $d));
        });

        // ----- Mapa de calor día × franja horaria (bloques de 3 horas) -----
        $blocks = ['00-02', '03-05', '06-08', '09-11', '12-14', '15-17', '18-20', '21-23'];
        $heatmap = [];
        $heatMax = 1;
        foreach (range(1, 7) as $d) {
            foreach ($blocks as $bi => $label) {
                $slice = $posts->filter(fn($p) => $p->published_at->dayOfWeekIso === $d
                    && intdiv((int) $p->published_at->format('G'), 3) === $bi);
                $n = $slice->count();
                $avg = $n ? (int) round($slice->sum('alcance') / $n) : 0;
                $heatmap[$d][$bi] = ['avg' => $avg, 'n' => $n];
                $heatMax = max($heatMax, $avg);
            }
        }

        // ----- Comparaciones por tipo y por red -----
        $typeLabels = ['text' => '📝 Texto', 'photo' => '🖼️ Foto', 'video' => '🎬 Video'];
        $byType = $posts->groupBy('type')->map(fn($g, $t) => $agg($g) + ['label' => $typeLabels[$t] ?? $t])->sortByDesc('avg_reach');
        $byNetwork = $posts->groupBy('network')->map(fn($g, $n) => $agg($g) + ['label' => $n === 'instagram' ? '📸 Instagram' : '📘 Facebook'])->sortByDesc('avg_reach');

        // Comparación entre campañas
        $campaignNames = $campaignsCatalog->pluck('name', 'id');
        $byCampaign = $posts->groupBy('campaign_id')
            ->map(fn($g, $cid) => $agg($g) + ['name' => $campaignNames[$cid] ?? 'Sin campaña'])
            ->sortByDesc('reach')
            ->values();

        // ----- Top y peores publicaciones -----
        $withReach = $posts->filter(fn($p) => (int) $p->alcance > 0);
        $topPosts = $withReach->sortByDesc('alcance')->take(5)->values();
        $worstPosts = $withReach->sortBy('alcance')->take(5)->values();

        // ----- Conclusiones automáticas (con n mínimo) -----
        $insights = [];

        if ($best = $byPage->first(fn($r) => $r['n'] >= $MIN_N) ?? $byPage->first()) {
            $insights[] = ['icon' => '🏆', 'title' => 'Mejor medio', 'value' => $best['name'],
                'detail' => "Promedio de " . number_format($best['avg_reach'], 0, ',', '.') . " de alcance por publicación ({$best['n']} publicaciones)"];
        }
        if ($best = $byHour->filter(fn($r) => $r['n'] >= $MIN_N)->sortByDesc('avg_reach')->first()) {
            $insights[] = ['icon' => '⏰', 'title' => 'Mejor hora para publicar', 'value' => sprintf('%02d:00', $best['hour']),
                'detail' => "Promedio de " . number_format($best['avg_reach'], 0, ',', '.') . " de alcance ({$best['n']} publicaciones)"];
        }
        if ($best = $byDow->filter(fn($r) => $r['n'] >= $MIN_N)->sortByDesc('avg_reach')->first()) {
            $insights[] = ['icon' => '📅', 'title' => 'Mejor día de la semana', 'value' => $best['label'],
                'detail' => "Promedio de " . number_format($best['avg_reach'], 0, ',', '.') . " de alcance ({$best['n']} publicaciones)"];
        }
        if (($best = $byType->filter(fn($r) => $r['n'] >= $MIN_N)->first()) && $byType->count() > 1) {
            $insights[] = ['icon' => '🎨', 'title' => 'Mejor formato', 'value' => $best['label'],
                'detail' => "Promedio de " . number_format($best['avg_reach'], 0, ',', '.') . " de alcance por publicación"];
        }
        if ($byNetwork->count() > 1 && ($best = $byNetwork->filter(fn($r) => $r['n'] >= $MIN_N)->first())) {
            $insights[] = ['icon' => '🌐', 'title' => 'Mejor red', 'value' => $best['label'],
                'detail' => "Promedio de " . number_format($best['avg_reach'], 0, ',', '.') . " de alcance vs " . number_format($byNetwork->last()['avg_reach'], 0, ',', '.')];
        }
        if ($eng = $byPage->filter(fn($r) => $r['n'] >= $MIN_N && $r['engagement'] !== null)->sortByDesc('engagement')->first()) {
            $insights[] = ['icon' => '❤️', 'title' => 'Audiencia más participativa', 'value' => $eng['name'],
                'detail' => "Tasa de interacción del {$eng['engagement']}% (interacciones/alcance)"];
        }

        // ----- Comparador por publicación (batch multi-medio) -----
        $batchQ = trim((string) $request->query('batch_q', ''));

        $batchCatalog = MetaPost::query()
            ->when(!$user->isAdmin(), fn($q) => $q->forUserPages($user->id))
            ->whereNotNull('batch_uuid')
            ->whereNotNull('published_at')
            ->when($batchQ !== '', fn($q) => $q->where('message', 'like', '%' . $batchQ . '%'))
            ->groupBy('batch_uuid')
            ->havingRaw('COUNT(DISTINCT meta_page_id) > 1')
            ->orderByDesc(DB::raw('MAX(published_at)'))
            ->limit(100)
            ->get([
                'batch_uuid',
                DB::raw('MIN(message) as message'),
                DB::raw('MAX(published_at) as published_at'),
                DB::raw('COUNT(DISTINCT meta_page_id) as pages_count'),
            ]);

        $batchCompare = null;
        if ($batchUuid = $request->query('batch')) {
            $batchPosts = MetaPost::with('page:id,name')
                ->when(!$user->isAdmin(), fn($q) => $q->forUserPages($user->id))
                ->where('batch_uuid', $batchUuid)
                ->get(['id', 'meta_page_id', 'network', 'status', 'message', 'published_at', 'alcance', 'visualizaciones', 'interacciones', 'fb_permalink_url']);

            if ($batchPosts->isNotEmpty()) {
                $rows = $batchPosts->map(fn($p) => [
                    'page' => $p->page->name ?? '—',
                    'network' => $p->network,
                    'status' => $p->status,
                    'alcance' => (int) $p->alcance,
                    'visualizaciones' => (int) $p->visualizaciones,
                    'interacciones' => (int) $p->interacciones,
                    'engagement' => (int) $p->alcance > 0 ? round((int) $p->interacciones / (int) $p->alcance * 100, 2) : null,
                    'permalink' => $p->fb_permalink_url,
                ])->sortByDesc('alcance')->values();

                $batchCompare = [
                    'uuid' => $batchUuid,
                    'message' => \Illuminate\Support\Str::limit($batchPosts->first()->message ?: '(sin texto)', 140),
                    'date' => $batchPosts->first()->published_at,
                    'rows' => $rows,
                    'winner' => $rows->first(),
                    'total_reach' => (int) $rows->sum('alcance'),
                ];
            }
        }

        return view('reports.analysis', [
            'filters' => ['days' => $days, 'network' => $network, 'page_ids' => $pageIds, 'batch_q' => $batchQ, 'batch' => $batchUuid ?? null, 'campaign_id' => $campaignId],
            'campaignsCatalog' => $campaignsCatalog,
            'byCampaign' => $byCampaign,
            'batchCatalog' => $batchCatalog,
            'batchCompare' => $batchCompare,
            'pagesCatalog' => $pagesCatalog,
            'totalPosts' => $posts->count(),
            'byPage' => $byPage,
            'byHour' => $byHour,
            'byDow' => $byDow,
            'heatmap' => $heatmap,
            'heatMax' => $heatMax,
            'heatBlocks' => $blocks,
            'dowLabels' => $dowLabels,
            'byType' => $byType,
            'byNetwork' => $byNetwork,
            'topPosts' => $topPosts,
            'worstPosts' => $worstPosts,
            'insights' => $insights,
            'minN' => $MIN_N,
        ]);
    }

    /**
     * Exporta el conjunto filtrado actual a CSV (compatible con Excel).
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = Auth::user();
        [$query, $filters] = $this->buildQuery($request, $user);

        $filename = 'publicaciones_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query, $filters) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel abra los acentos correctamente
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Fecha', 'Campaña', 'Página', 'Red', 'Tipo', 'Estado', 'Autor', 'Mensaje', 'Alcance', 'Impresiones', 'Interacciones', 'Enlace'], ';');

            $query->with(['page:id,name', 'user:id,name', 'campaign:id,name'])
                ->orderBy($filters['sort'], $filters['dir'])
                ->orderByDesc('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $p) {
                        fputcsv($out, [
                            optional($p->published_at)->format('Y-m-d H:i') ?? optional($p->created_at)->format('Y-m-d H:i'),
                            $p->campaign->name ?? '',
                            $p->page->name ?? '',
                            $p->network === 'instagram' ? 'Instagram' : 'Facebook',
                            ucfirst($p->type),
                            $p->status,
                            $p->user->name ?? '',
                            mb_substr((string) $p->message, 0, 500),
                            (int) $p->alcance,
                            (int) $p->visualizaciones,
                            (int) $p->interacciones,
                            (string) $p->fb_permalink_url,
                        ], ';');
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Construye la consulta filtrada (compartida por la vista y el CSV).
     *
     * @return array{0: Builder, 1: array}
     */
    private function buildQuery(Request $request, User $user): array
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'page_id' => $request->query('page_id'),
            'network' => in_array($request->query('network'), ['facebook', 'instagram'], true) ? $request->query('network') : null,
            'campaign_id' => $request->query('campaign_id'),
            'type' => in_array($request->query('type'), ['text', 'photo', 'video'], true) ? $request->query('type') : null,
            'status' => in_array($request->query('status'), ['success', 'fail', 'pending'], true) ? $request->query('status') : null,
            'user_id' => $user->isAdmin() ? $request->query('user_id') : null,
            'from' => $this->parseDate($request->query('from')),
            'to' => $this->parseDate($request->query('to')),
            'sort' => in_array($request->query('sort'), ['published_at', 'alcance', 'visualizaciones', 'interacciones'], true)
                ? $request->query('sort') : 'published_at',
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
            'per_page' => in_array((int) $request->query('per_page'), [15, 30, 50, 100], true)
                ? (int) $request->query('per_page') : 30,
        ];

        $query = MetaPost::query();

        if (!$user->isAdmin()) {
            $query->forUserPages($user->id);
        }

        $query
            ->when($filters['q'] !== '', fn($q) => $q->where(function ($qq) use ($filters) {
                $qq->where('message', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('fb_post_id', 'like', '%' . $filters['q'] . '%');
            }))
            ->when($filters['page_id'], fn($q, $v) => $q->where('meta_page_id', $v))
            ->when($filters['network'], fn($q, $v) => $q->where('network', $v))
            ->when($filters['campaign_id'], fn($q, $v) => $q->where('campaign_id', $v))
            ->when($filters['type'], fn($q, $v) => $q->where('type', $v))
            ->when($filters['user_id'], fn($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'], fn($q, $v) => $q->where('published_at', '>=', $v->startOfDay()))
            ->when($filters['to'], fn($q, $v) => $q->where('published_at', '<=', $v->endOfDay()));

        // 'pending' agrupa pending + queued (estados intermedios)
        if ($filters['status'] === 'pending') {
            $query->whereIn('status', ['pending', 'queued']);
        } elseif ($filters['status']) {
            $query->where('status', $filters['status']);
        }

        return [$query, $filters];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
