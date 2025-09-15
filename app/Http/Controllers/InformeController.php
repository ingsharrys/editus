<?php

namespace App\Http\Controllers;

use App\Models\MetaPost;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class InformeController extends Controller
{
     public function index(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        $ownerId = $request->query('owner_id');

        // Base: solo posts aprobados (success)
        $base = MetaPost::query()->where('status', 'success');

        if ($ownerId) {
            $base->where('user_id', $ownerId);
        }

        // Totales globales
        $totals = (clone $base)
            ->selectRaw('COUNT(*) as total_posts,
                         SUM(COALESCE(alcance,0)) as total_alcance,
                         SUM(COALESCE(visualizaciones,0)) as total_visualizaciones,
                         SUM(COALESCE(interacciones,0)) as total_interacciones')
            ->first();

        // Agrupación por publicación (batch) o single
        $groups = (clone $base)
            ->selectRaw("
                COALESCE(batch_uuid, CONCAT('single-', id)) as group_key,
                MAX(COALESCE(published_at, created_at)) as effective_at,
                MAX(message) as message_sample,
                COUNT(*) as posts_count,
                SUM(COALESCE(alcance,0)) as alcance_sum,
                SUM(COALESCE(visualizaciones,0)) as visualizaciones_sum,
                SUM(COALESCE(interacciones,0)) as interacciones_sum,
                MIN(fb_permalink_url) as any_permalink
            ")
            ->groupBy('group_key')
            ->orderByDesc('effective_at')
            ->paginate(12)
            ->withQueryString();

        // Owners para filtro (usuarios que tienen páginas vinculadas o que han publicado)
        $owners = User::whereHas('metaPages')
            ->orderBy('name')
            ->get(['id','name']);

        return view('informe.index', compact('totals','groups','owners','ownerId'));
    }

    public function show(string $key)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Si key es single-#, mostramos ese único post; si no, es un batch_uuid
        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $posts = MetaPost::with(['page:id,name,page_id'])
                ->where('status','success')
                ->where('id', $id)
                ->get();
        } else {
            $posts = MetaPost::with(['page:id,name,page_id'])
                ->where('status','success')
                ->where('batch_uuid', $key)
                ->get();
        }

        abort_if($posts->isEmpty(), 404);

        // Totales del grupo
        $summary = [
            'total_posts'        => $posts->count(),
            'alcance_sum'        => $posts->sum(fn($p)=> (int)($p->alcance ?? 0)),
            'visualizaciones_sum'=> $posts->sum(fn($p)=> (int)($p->visualizaciones ?? 0)),
            'interacciones_sum'  => $posts->sum(fn($p)=> (int)($p->interacciones ?? 0)),
            'message_sample'     => (string)($posts->first()->message ?? '—'),
            'effective_at'       => $posts->max(fn($p)=> optional($p->published_at ?? $p->created_at)),
            'any_permalink'      => $posts->firstWhere('fb_permalink_url')?->fb_permalink_url,
        ];

        // Orden por métricas y nombre de página
        $byPage = $posts->sortBy(fn($p)=> $p->page?->name ?? '')->values();

        return view('informe.show', compact('key','summary','byPage'));
    }
}
