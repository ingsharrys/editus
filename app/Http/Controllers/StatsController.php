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

        $allPages = $pagesQuery->orderBy('name')->get(['meta_pages.id', 'meta_pages.name', 'meta_pages.page_id']);

        // ----- Filtros -----
        $days = in_array((int) $request->query('days'), [7, 30, 90], true)
            ? (int) $request->query('days')
            : 30;

        $pageId = $request->query('page_id');
        $selectedIds = $pageId && $allPages->contains('id', (int) $pageId)
            ? [(int) $pageId]
            : $allPages->pluck('id')->all();

        $since = now()->subDays($days)->startOfDay();
        $prevSince = now()->subDays($days * 2)->startOfDay();

        // ----- Serie diaria (suma de las páginas seleccionadas) -----
        $daily = MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
            ->where('date', '>=', $since->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get([
                'date',
                DB::raw('SUM(reach) as reach'),
                DB::raw('SUM(impressions) as impressions'),
                DB::raw('SUM(engagements) as engagements'),
                DB::raw('SUM(video_views) as video_views'),
            ]);

        // ----- KPIs del período y comparación con el período anterior -----
        $totals = [
            'reach' => (int) $daily->sum('reach'),
            'impressions' => (int) $daily->sum('impressions'),
            'engagements' => (int) $daily->sum('engagements'),
            'video_views' => (int) $daily->sum('video_views'),
        ];

        $prev = MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
            ->whereBetween('date', [$prevSince->toDateString(), $since->copy()->subDay()->toDateString()])
            ->selectRaw('SUM(reach) as reach, SUM(impressions) as impressions, SUM(engagements) as engagements')
            ->first();

        $variation = function (int $current, $previous): ?float {
            $previous = (int) $previous;
            if ($previous <= 0) {
                return null;
            }
            return round((($current - $previous) / $previous) * 100, 1);
        };

        $kpiVariation = [
            'reach' => $variation($totals['reach'], $prev->reach ?? 0),
            'impressions' => $variation($totals['impressions'], $prev->impressions ?? 0),
            'engagements' => $variation($totals['engagements'], $prev->engagements ?? 0),
        ];

        // Seguidores actuales: último snapshot de fans por página
        $fansTotal = (int) MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
            ->whereIn('id', function ($q) use ($selectedIds) {
                $q->selectRaw('MAX(id)')
                    ->from('meta_page_daily_metrics')
                    ->whereIn('meta_page_id', $selectedIds)
                    ->where('fans', '>', 0)
                    ->groupBy('meta_page_id');
            })
            ->sum('fans');

        // ----- Publicaciones del período -----
        $posts = MetaPost::whereIn('meta_page_id', $selectedIds)
            ->where('status', 'success')
            ->where('published_at', '>=', $since)
            ->get(['id', 'meta_page_id', 'type', 'message', 'published_at', 'alcance', 'visualizaciones', 'interacciones', 'fb_permalink_url']);

        $topPosts = $posts->sortByDesc(fn($p) => (int) $p->alcance)->take(10)->values();

        $byType = $posts->groupBy('type')->map(fn($group) => [
            'posts' => $group->count(),
            'alcance' => (int) $group->sum('alcance'),
            'interacciones' => (int) $group->sum('interacciones'),
        ]);

        // ----- Reacciones por tipo (período) -----
        $reactions = DB::table('meta_post_reactions')
            ->join('meta_posts', 'meta_posts.id', '=', 'meta_post_reactions.meta_post_id')
            ->whereIn('meta_posts.meta_page_id', $selectedIds)
            ->where('meta_posts.published_at', '>=', $since)
            ->groupBy('meta_post_reactions.type')
            ->orderByDesc(DB::raw('SUM(meta_post_reactions.total)'))
            ->pluck(DB::raw('SUM(meta_post_reactions.total) as total'), 'meta_post_reactions.type');

        // ----- Audiencia geográfica: último snapshot por página, agregado -----
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

                // Edad y sexo ordenados por clave; el resto por volumen
                $audience[$dim] = in_array($dim, ['ig_gender', 'ig_age'], true)
                    ? $grouped->sortKeys()
                    : $grouped->sortDesc()->take(12);
            }
        }

        // ----- Ranking de páginas (solo cuando se ven todas) -----
        $pageRanking = collect();
        if (count($selectedIds) > 1) {
            $pageRanking = MetaPageDailyMetric::whereIn('meta_page_id', $selectedIds)
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
                    $row->name = $allPages->firstWhere('id', $row->meta_page_id)?->name ?? '—';
                    return $row;
                });
        }

        return view('stats.index', [
            'pages' => $allPages,
            'selectedPageId' => $pageId ? (int) $pageId : null,
            'days' => $days,
            'daily' => $daily,
            'totals' => $totals,
            'kpiVariation' => $kpiVariation,
            'fansTotal' => $fansTotal,
            'postsCount' => $posts->count(),
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
