<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campana;
use App\Models\MetaPage;
use App\Models\PublicacionRed;
use App\Services\Inteligencia\GraphClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class MetaCommentsReviewController extends Controller
{
    /**
     * Módulo de revisión de Meta: lectura en vivo y de solo lectura.
     * No utiliza Anthropic, no guarda comentarios y no modifica publicaciones.
     */
    public function index(Request $request, Campana $campana, GraphClient $graph): View
    {
        $data = $request->validate([
            'page_id' => ['nullable', 'integer'],
            'post_id' => ['nullable', 'integer'],
            'comment_id' => ['nullable', 'string', 'max:100', 'regex:/^[0-9_]+$/'],
        ]);

        $campana->load('paginas');
        $pages = $campana->paginas->sortBy('name')->values();
        $page = null;
        $post = null;
        $posts = collect();
        $comments = [];
        $replies = [];
        $error = null;
        $retrieved = false;

        if (!empty($data['page_id'])) {
            $page = $pages->firstWhere('id', (int) $data['page_id']);
            abort_unless($page instanceof MetaPage, 404);

            $posts = PublicacionRed::query()
                ->where('meta_page_id', $page->id)
                ->where('red', 'facebook')
                ->whereNotNull('post_id')
                ->orderByDesc('publicado_en')
                ->limit(50)
                ->get(['id', 'meta_page_id', 'post_id', 'texto', 'publicado_en', 'permalink']);

            if (!empty($data['post_id'])) {
                $post = $posts->firstWhere('id', (int) $data['post_id']);
                abort_unless($post instanceof PublicacionRed, 404);

                // Nunca usar tokens de otros usuarios, ni el fallback de System User.
                $pivot = $page->users()
                    ->where('users.id', $request->user()->id)
                    ->wherePivot('is_active', 1)
                    ->first()?->pivot;
                $token = $pivot?->page_access_token;

                if (!$token) {
                    $error = 'Esta Página no tiene un token activo asociado a tu usuario de Editus. Conéctala nuevamente desde tu cuenta.';
                } else {
                    try {
                        $response = $graph->get($post->post_id . '/comments', [
                            'fields' => 'id,message,created_time,from',
                            'limit' => 25,
                            'filter' => 'stream',
                        ], $token);
                        $comments = collect($response['data'] ?? [])
                            ->filter(fn ($item) => is_array($item) && isset($item['id']))
                            ->values()->all();
                        $retrieved = true;

                        if (!empty($data['comment_id'])) {
                            $selected = collect($comments)->firstWhere('id', $data['comment_id']);
                            abort_unless($selected !== null, 404);
                            $replyResponse = $graph->get($data['comment_id'] . '/comments', [
                                'fields' => 'id,message,created_time,from',
                                'limit' => 25,
                            ], $token);
                            $replies = collect($replyResponse['data'] ?? [])
                                ->filter(fn ($item) => is_array($item))
                                ->values()->all();
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[meta-review] fallo al recuperar comentarios', [
                            'page_id' => $page->page_id,
                            'post_id' => $post->post_id,
                            'exception' => get_class($e),
                        ]);
                        $error = 'Meta no permitió recuperar los comentarios. Verifica el token, los permisos y el acceso a la publicación.';
                    }
                }
            }
        }

        return view('admin.inteligencia.meta-comments-review', compact(
            'campana', 'pages', 'page', 'posts', 'post', 'comments', 'replies',
            'error', 'retrieved'
        ));
    }
}
