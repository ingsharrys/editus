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

        $posts = MetaPost::with(['page:id,name,page_id'])
            ->forUserPages($user->id, true)
            ->where('status', 'success')                 // ← SOLO aprobados
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

    public function update(Request $request, MetaPost $post)
    {
        $this->authorize('view', $post);

        // Eliminar evidencia ya guardada (si el user presiona "Quitar actual")
        if ($request->boolean('remove_evidencia')) {
            if ($post->evidencia_path) {
                Storage::disk('public')->delete($post->evidencia_path);
            }
            $post->evidencia_path = null;
        }

        $data = $request->validate([
            'alcance' => ['nullable', 'integer', 'min:0'],
            'visualizaciones' => ['nullable', 'integer', 'min:0'],
            'interacciones' => ['nullable', 'integer', 'min:0'],
            'evidencia' => ['nullable', 'image', 'max:4096'], // 4MB
        ]);

        // Reemplazar evidencia si suben una nueva
        if ($request->hasFile('evidencia')) {
            if ($post->evidencia_path) {
                Storage::disk('public')->delete($post->evidencia_path);
            }
            $path = $request->file('evidencia')->store('meta_posts/evidencias', 'public');
            $post->evidencia_path = $path;
        }

        $post->fill([
            'alcance' => $data['alcance'] ?? $post->alcance,
            'visualizaciones' => $data['visualizaciones'] ?? $post->visualizaciones,
            'interacciones' => $data['interacciones'] ?? $post->interacciones,
        ])->save();

        return back()->with('ok', '¡Guardado correctamente!');
    }
}
