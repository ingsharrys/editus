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
            ->with(['page:id,name,page_id', 'user:id,name'])
            ->orderBy($filters['sort'], $filters['dir'])
            ->orderByDesc('id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        // ----- Catálogos para los filtros -----
        $pages = ($user->isAdmin() ? MetaPage::query() : $user->metaPages())
            ->orderBy('name')
            ->get(['meta_pages.id', 'meta_pages.name']);

        $authors = $user->isAdmin()
            ? User::whereIn('id', MetaPost::query()->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('reports.posts', compact('posts', 'kpis', 'activity', 'byNetwork', 'byType', 'pages', 'authors', 'filters'));
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

            fputcsv($out, ['Fecha', 'Página', 'Red', 'Tipo', 'Estado', 'Autor', 'Mensaje', 'Alcance', 'Impresiones', 'Interacciones', 'Enlace'], ';');

            $query->with(['page:id,name', 'user:id,name'])
                ->orderBy($filters['sort'], $filters['dir'])
                ->orderByDesc('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $p) {
                        fputcsv($out, [
                            optional($p->published_at)->format('Y-m-d H:i') ?? optional($p->created_at)->format('Y-m-d H:i'),
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
