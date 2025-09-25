<?php

namespace App\Http\Controllers;

use App\Models\MetaPost;
use App\Models\MetaPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Storage;

class MyPostsController extends Controller
{
    use AuthorizesRequests;
    public function index(Request $request)
    {
        $user = Auth::user();

        // Páginas del usuario (para el select)
        $pages = MetaPage::forUser($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'page_id']);

        $pageId = $request->query('page_id');

        $posts = MetaPost::with([
            'page:id,name,page_id',
            'metrics',
        ])
            ->forUserPages($user->id, true)
            ->where('status', 'success')
            ->when($pageId, fn($q) => $q->where('meta_page_id', $pageId))
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->paginate(12)
            ->withQueryString();

        return view('mis-posts.index', compact('posts', 'pages', 'pageId'));
    }



    public function show(MetaPost $post)
    {
        $this->authorize('view', $post);
        $post->load(['page:id,name,page_id']);
        return view('mis-posts.show', compact('post'));
    }

    // App/Http/Controllers/MyPostsController.php

    public function update(Request $request, MetaPost $post)
    {
        $this->authorize('view', $post);

        // Ronda enviada o calculada automáticamente
        $round = (int) $request->input('round', $post->metrics_next_round ?? 0);

        // Guardias de ventana/ronda válida
        if (!in_array($round, [1, 2], true) || !$post->metrics_next_round || $post->metrics_next_round !== $round) {
            return back()
                ->withErrors(['metrics' => 'No puedes editar métricas ahora. ' . $post->metrics_state_message])
                ->withInput();
        }

        // Métrica destino (crea si no existe)
        $metric = $post->metrics()->firstOrCreate(['round' => $round]);

        // Quitar evidencia si el usuario lo pidió
        if ($request->boolean('remove_evidencia')) {
            if ($metric->evidencia_path) {
                Storage::disk('public')->delete($metric->evidencia_path);
            }
            $metric->evidencia_path = null;
        }

        $data = $request->validate([
            'alcance' => ['nullable', 'integer', 'min:0'],
            'visualizaciones' => ['nullable', 'integer', 'min:0'],
            'interacciones' => ['nullable', 'integer', 'min:0'],
            'evidencia' => ['nullable', 'image', 'max:4096'], // 4MB
        ]);

        // Reemplazar evidencia si suben una nueva
        if ($request->hasFile('evidencia')) {
            if ($metric->evidencia_path) {
                Storage::disk('public')->delete($metric->evidencia_path);
            }
            $path = $request->file('evidencia')->store("meta_posts/{$post->id}/metrics/round-{$round}", 'public');
            $metric->evidencia_path = $path;
        }

        $metric->fill([
            'alcance' => $data['alcance'] ?? $metric->alcance,
            'visualizaciones' => $data['visualizaciones'] ?? $metric->visualizaciones,
            'interacciones' => $data['interacciones'] ?? $metric->interacciones,
        ])->save();

        return back()->with('ok', "¡Métrica de la ronda {$round} guardada!");
    }


}
