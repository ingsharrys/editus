<?php

namespace App\Http\Controllers;

use App\Models\MetaPage;
use App\Models\MetaPageAudience;
use App\Models\MetaPageDailyMetric;
use App\Models\MetaPost;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // ----- Páginas visibles según rol -----
        $pagesQuery = $user->isAdmin()
            ? MetaPage::query()
            : $user->metaPages();

        $allPages = $pagesQuery->orderBy('name')
            ->get(['meta_pages.id', 'meta_pages.name', 'meta_pages.page_id', 'meta_pages.instagram_business_account_id']);

        // ----- Filtros -----
        $days = in_array((int) $request->query('days'), [7, 30, 90], true)
            ? (int) $request->query('days')
            : 30;

        $network = in_array($request->query('network'), ['facebook', 'instagram'], true)
            ? $request->query('network')
            : 'all';

        $networkList = $network === 'all' ? ['facebook', 'instagram'] : [$network];

        $pageId = $request->query('page_id');
        $selectedIds = $pageId && $allPages->contains('id', (int) $pageId)
            ? [(int) $pageId]
            : $allPages->pluck('id')->all();

        $since = now()->subDays($days)->startOfDay();
        $prevSince = now()->subDays($days * 2)->startOfDay();

        // ----- Serie diaria por red -----
        $dailyRows = MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
            ->whereIn('network', $networkList)
            ->where('date', '>=', $since->toDateString())
            ->groupBy('date', 'network')
            ->orderBy('date')
            ->get([
                'date',
                'network',
                DB::raw('SUM(reach) as reach'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(engagements) as engagements'),
                DB::raw('SUM(video_views) as video_views'),
            ]);

        $labels = $dailyRows->pluck('date')->map(fn($d) => $d->toDateString())->unique()->sort()->values();
        $seriesOf = function (string $net, string $col) use ($dailyRows, $labels) {
            $map = $dailyRows->where('network', $net)->keyBy(fn($r) => $r->date->toDateString());
            return $labels->map(fn($d) => (int) ($map[$d]->{$col} ?? 0))->all();
        };

        // Datasets del gráfico según el filtro de red
        $datasets = [];
        if (in_array('facebook', $networkList, true)) {
            $datasets[] = ['label' => 'Alcance Facebook', 'data' => $seriesOf('facebook', 'reach'), 'borderColor' => '#2563eb', 'backgroundColor' => 'rgba(37,99,235,.08)', 'fill' => true, 'tension' => .3, 'pointRadius' => 0];
            $datasets[] = ['label' => 'Interacciones Facebook', 'data' => $seriesOf('facebook', 'engagements'), 'borderColor' => '#059669', 'tension' => .3, 'pointRadius' => 0];
            if ($network === 'facebook') {
                $datasets[] = ['label' => 'Impresiones Facebook', 'data' => $seriesOf('facebook', 'impressions'), 'borderColor' => '#7c3aed', 'tension' => .3, 'pointRadius' => 0];
            }
        }
        if (in_array('instagram', $networkList, true)) {
            $datasets[] = ['label' => 'Alcance Instagram', 'data' => $seriesOf('instagram', 'reach'), 'borderColor' => '#ec4899', 'backgroundColor' => 'rgba(236,72,153,.08)', 'fill' => $network === 'instagram', 'tension' => .3, 'pointRadius' => 0];
        }

        $chart = ['labels' => $labels->all(), 'datasets' => $datasets];

        // ----- Totales por red y KPIs -----
        $sumByNetwork = fn(string $net, string $col) => (int) $dailyRows->where('network', $net)->sum($col);

        $perNetwork = [];
        foreach (['facebook', 'instagram'] as $net) {
            $perNetwork[$net] = [
                'reach' => $sumByNetwork($net, 'reach'),
                'impressions' => $sumByNetwork($net, 'impressions'),
                'engagements' => $sumByNetwork($net, 'engagements'),
                'video_views' => $sumByNetwork($net, 'video_views'),
            ];
        }

        $totals = [
            'reach' => (int) $dailyRows->sum('reach'),
            'impressions' => (int) $dailyRows->sum('impressions'),
            'engagements' => (int) $dailyRows->sum('engagements'),
            'video_views' => (int) $dailyRows->sum('video_views'),
        ];

        $prev = MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
            ->whereIn('network', $networkList)
            ->whereBetween('date', [$prevSince->toDateString(), $since->copy()->subDay()->toDateString()])
            ->selectRaw('SUM(reach) as reach, SUM(impressions) as impressions, SUM(engagements) as engagements')
            ->first();

        $variation = function (int $current, $previous): ?float {
            $previous = (int) $previous;
            return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : null;
        };

        $kpiVariation = [
            'reach' => $variation($totals['reach'], $prev->reach ?? 0),
            'impressions' => $variation($totals['impressions'], $prev->impressions ?? 0),
            'engagements' => $variation($totals['engagements'], $prev->engagements ?? 0),
        ];

        // Seguidores: último snapshot con fans > 0 por página y red
        $fansByNetwork = [];
        foreach (['facebook', 'instagram'] as $net) {
            $fansByNetwork[$net] = (int) MetaPageDailyMetric::whereIn('id', function ($q) use ($selectedIds, $net) {
                $q->selectRaw('MAX(id)')
                    ->from('meta_page_daily_metrics')
                    ->whereIn('meta_page_id', $selectedIds)
                    ->where('network', $net)
                    ->where('fans', '>', 0)
                    ->groupBy('meta_page_id');
            })->sum('fans');
        }
        $fansTotal = array_sum(array_intersect_key($fansByNetwork, array_flip($networkList)));

        // ----- Publicaciones del período (por red) -----
        $posts = MetaPost::whereIn('meta_page_id', $selectedIds)
            ->whereIn('network', $networkList)
            ->where('status', 'success')
            ->where('published_at', '>=', $since)
            ->get(['id', 'meta_page_id', 'type', 'network', 'message', 'published_at', 'alcance', 'visualizaciones', 'interacciones', 'fb_permalink_url']);

        $postsByNetwork = [
            'facebook' => $posts->where('network', 'facebook')->count(),
            'instagram' => $posts->where('network', 'instagram')->count(),
        ];

        $topPosts = $posts->sortByDesc(fn($p) => (int) $p->alcance)->take(10)->values();

        $byType = $posts->groupBy('type')->map(fn($group) => [
            'posts' => $group->count(),
            'alcance' => (int) $group->sum('alcance'),
            'interacciones' => (int) $group->sum('interacciones'),
        ]);

        // ----- Reacciones por tipo (solo Facebook las expone) -----
        $reactions = collect();
        if (in_array('facebook', $networkList, true)) {
            $reactions = DB::table('meta_post_reactions')
                ->join('meta_posts', 'meta_posts.id', '=', 'meta_post_reactions.meta_post_id')
                ->whereIn('meta_posts.meta_page_id', $selectedIds)
                ->where('meta_posts.network', 'facebook')
                ->where('meta_posts.published_at', '>=', $since)
                ->groupBy('meta_post_reactions.type')
                ->orderByDesc(DB::raw('SUM(meta_post_reactions.total)'))
                ->pluck(DB::raw('SUM(meta_post_reactions.total) as total'), 'meta_post_reactions.type');
        }

        // ----- Audiencia: último snapshot por página, agregado -----
        $latestAudienceDates = MetaPageAudience::whereIn('meta_page_id', $selectedIds)
            ->groupBy('meta_page_id')
            ->pluck(DB::raw('MAX(captured_date) as d'), 'meta_page_id');

        $audience = [
            'country' => collect(),
            'city' => collect(),
            'ig_gender' => collect(),
            'ig_age' => collect(),
            'ig_country' => collect(),
            'ig_city' => collect(),
        ];

        if ($latestAudienceDates->isNotEmpty()) {
            $rows = MetaPageAudience::whereIn('meta_page_id', $selectedIds)
                ->where(function ($q) use ($latestAudienceDates) {
                    foreach ($latestAudienceDates as $pid => $date) {
                        $q->orWhere(fn($qq) => $qq->where('meta_page_id', $pid)->where('captured_date', $date));
                    }
                })
                ->get(['dimension', 'key', 'value']);

            foreach (array_keys($audience) as $dim) {
                $grouped = $rows->where('dimension', $dim)
                    ->groupBy('key')
                    ->map(fn($g) => (int) $g->sum('value'));

                $audience[$dim] = in_array($dim, ['ig_gender', 'ig_age'], true)
                    ? $grouped->sortKeys()
                    : $grouped->sortDesc()->take(12);
            }
        }

        // ----- Ranking de páginas (por alcance del filtro actual) -----
        $pageRanking = collect();
        if (count($selectedIds) > 1) {
            $pageRanking = MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
                ->whereIn('network', $networkList)
                ->where('date', '>=', $since->toDateString())
                ->groupBy('meta_page_id')
                ->orderByDesc(DB::raw('SUM(reach)'))
                ->limit(10)
                ->get([
                    'meta_page_id',
                    DB::raw('SUM(reach) as reach'),
                    DB::raw('SUM(impressions) as impressions'),
                    DB::raw('SUM(engagements) as engagements'),
                ])
                ->map(function ($row) use ($allPages) {
                    $page = $allPages->firstWhere('id', $row->meta_page_id);
                    $row->name = $page?->name ?? '—';
                    $row->has_ig = !empty($page?->instagram_business_account_id);
                    return $row;
                });
        }

        return view('stats.index', [
            'pages' => $allPages,
            'selectedPageId' => $pageId ? (int) $pageId : null,
            'days' => $days,
            'network' => $network,
            'chart' => $chart,
            'totals' => $totals,
            'perNetwork' => $perNetwork,
            'kpiVariation' => $kpiVariation,
            'fansTotal' => $fansTotal,
            'fansByNetwork' => $fansByNetwork,
            'postsCount' => $posts->count(),
            'postsByNetwork' => $postsByNetwork,
            'topPosts' => $topPosts,
            'byType' => $byType,
            'reactions' => $reactions,
            'audience' => $audience,
            'pageRanking' => $pageRanking,
            'lastCollectedAt' => MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)->max('updated_at'),
        ]);
    }

    /**
     * Dispara la recolección manualmente desde el dashboard.
     */
    public function collect(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        \Illuminate\Support\Facades\Artisan::queue('stats:collect', ['--days' => 7]);

        return back()->with('success', 'Recolección de estadísticas iniciada. Los datos aparecerán en unos minutos.');
    }
}
