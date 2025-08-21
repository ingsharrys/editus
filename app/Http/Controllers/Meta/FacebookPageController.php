<?php

namespace App\Http\Controllers\Meta;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MetaPage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class FacebookPageController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if ($user->isAdmin()) {
            $pages = MetaPage::with(['users'])->latest()->paginate(20);
        } else {
            $pages = $user->metaPages()->with('users')->paginate(20);
        }

        return view('meta.pages.index', compact('pages'));
    }

    // Sincroniza páginas del usuario autenticado
    public function sync(Request $request)
    {
        $user = Auth::user();

        $social = \App\Models\SocialAccount::where('user_id', $user->id)
            ->where('provider', 'facebook')
            ->first();

        // 👉 Si aún no conectó Facebook, lo mando a OAuth directo
        if (!$social) {
            return redirect()->route('facebook.redirect');
        }

        // Llama Graph: /me/accounts
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';
        $url = 'https://graph.facebook.com/v20.0/me/accounts';

        $resp = Http::withToken($social->access_token)
            ->get($url, ['fields' => $fields]);

        if (!$resp->ok()) {
            return back()->with('error', 'No se pudieron obtener las páginas: ' . $resp->body());
        }

        $data = $resp->json('data') ?? [];

        foreach ($data as $page) {
            $metaPage = MetaPage::updateOrCreate(
                ['page_id' => $page['id']],
                [
                    'name'   => $page['name'] ?? null,
                    'category' => $page['category'] ?? null,
                    'instagram_business_account_id' => data_get($page, 'connected_instagram_business_account.id'),
                    'picture_url' => data_get($page, 'picture.data.url'),
                    'tasks'  => $page['tasks'] ?? null,
                ]
            );

            // Vincula al user con token de página
            $user->metaPages()->syncWithoutDetaching([
                $metaPage->id => [
                    'page_access_token' => $page['access_token'],
                    'social_account_id' => $social->id,
                    'expires_at'        => null, // page token suele ser long-lived
                    'is_active'         => true,
                ]
            ]);
        }

        return back()->with('success', 'Páginas sincronizadas.');
    }

    // Publicar en múltiples páginas (solo admin)
    public function publish(Request $request)
    {
        $request->validate([
            'message' => ['required', 'string', 'max:63206'], // límite FB msg aprox
            'page_ids' => ['required', 'array', 'min:1'],
            'page_ids.*' => [Rule::exists('meta_pages', 'id')],
            // opcional: 'link' => ['url']
        ]);

        $pages = MetaPage::whereIn('id', $request->page_ids)
            ->with(['users' => function ($q) {
                $q->wherePivot('is_active', true);
            }])->get();

        $results = [];

        foreach ($pages as $page) {
            // toma cualquier token activo asociado (el primero)
            $pivot = $page->users->first()?->pivot;
            if (!$pivot?->page_access_token) {
                $results[] = ['page' => $page->name, 'ok' => false, 'error' => 'Sin token activo'];
                continue;
            }

            $url = "https://graph.facebook.com/v20.0/{$page->page_id}/feed";
            $payload = ['message' => $request->message, 'access_token' => $pivot->page_access_token];

            // si incluyes un link:
            if ($request->filled('link')) {
                $payload['link'] = $request->input('link');
            }

            $resp = Http::asForm()->post($url, $payload);

            $results[] = [
                'page'  => $page->name,
                'ok'    => $resp->ok(),
                'body'  => $resp->json(),
                'error' => $resp->ok() ? null : $resp->body(),
            ];
        }

        // Muestra un resumen simple
        $fails = collect($results)->where('ok', false)->count();
        $ok = collect($results)->where('ok', true)->count();

        return back()->with('success', "Publicación enviada. OK: {$ok}, Fails: {$fails}")
            ->with('publish_results', $results);
    }
}
