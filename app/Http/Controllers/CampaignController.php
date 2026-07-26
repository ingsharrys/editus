<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function __construct()
    {
        // Toda la gestión de campañas es de administradores
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->isAdmin(), 403);
            return $next($request);
        });
    }

    public function index()
    {
        $campaigns = Campaign::query()
            ->withCount('posts')
            ->leftJoin(
                DB::raw('(SELECT campaign_id, COALESCE(SUM(alcance),0) reach, COALESCE(SUM(interacciones),0) inter
                          FROM meta_posts WHERE status = "success" GROUP BY campaign_id) m'),
                'm.campaign_id', '=', 'campaigns.id'
            )
            ->orderByDesc('campaigns.is_active')
            ->orderBy('campaigns.name')
            ->get(['campaigns.*', DB::raw('COALESCE(m.reach,0) as total_reach'), DB::raw('COALESCE(m.inter,0) as total_inter')]);

        return view('campaigns.index', compact('campaigns'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('campaigns', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        Campaign::create([
            'name' => $data['name'],
            'slug' => Campaign::makeSlug($data['name']),
            'description' => $data['description'] ?? null,
            'is_system' => false,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', "Campaña «{$data['name']}» creada.");
    }

    public function update(Request $request, Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, 'Las campañas de sistema no se pueden editar.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('campaigns', 'name')->ignore($campaign->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $campaign->update($data);

        return back()->with('success', "Campaña actualizada.");
    }

    public function toggle(Campaign $campaign)
    {
        abort_if($campaign->is_system, 403, 'Las campañas de sistema no se pueden desactivar.');

        $campaign->update(['is_active' => !$campaign->is_active]);

        return back()->with('success', $campaign->is_active
            ? "Campaña «{$campaign->name}» activada."
            : "Campaña «{$campaign->name}» desactivada (no aparecerá al publicar; su historial se conserva).");
    }
}
