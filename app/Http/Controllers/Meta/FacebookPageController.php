<?php

namespace App\Http\Controllers\Meta;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MetaPage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;

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

    protected function socialite()
    {
        return Socialite::driver('facebook')
            ->scopes(config('services.facebook.scopes', []))
            ->redirectUrl(route('facebook.callback'));
    }

    // Paso 1: redirigir a Facebook para VINCULAR (usuario YA está logueado en tu app)
    public function linkRedirect()
    {
        return $this->socialite()
            ->with(['auth_type' => 'rerequest']) // opcional
            ->redirect();
    }

    // Paso 2: callback de VINCULACIÓN (NO crea usuarios; usa Auth::user())
    public function linkCallback()
    {
        $fbUser = $this->socialite()->user();
        $current = Auth::user();

        // Reasigna o crea el social account para el usuario actual
        $existing = SocialAccount::where('provider', 'facebook')
            ->where('provider_user_id', $fbUser->getId())
            ->first();

        if ($existing && $existing->user_id !== $current->id) {
            $existing->update([
                'user_id'       => $current->id,
                'name'          => $fbUser->getName(),
                'avatar'        => $fbUser->getAvatar(),
                'access_token'  => $fbUser->token,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at'    => isset($fbUser->expiresIn) ? now()->addSeconds((int)$fbUser->expiresIn) : null,
                'raw'           => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]);
            $social = $existing;
        } else {
            $social = SocialAccount::updateOrCreate(
                [
                    'user_id'          => $current->id,
                    'provider'         => 'facebook',
                    'provider_user_id' => $fbUser->getId(),
                ],
                [
                    'name'          => $fbUser->getName(),
                    'avatar'        => $fbUser->getAvatar(),
                    'access_token'  => $fbUser->token,
                    'refresh_token' => $fbUser->refreshToken ?? null,
                    'expires_at'    => isset($fbUser->expiresIn) ? now()->addSeconds((int)$fbUser->expiresIn) : null,
                    'raw'           => method_exists($fbUser, 'user') ? $fbUser->user : null,
                ]
            );
        }

        // 🔹 Ejecuta la sincronización directamente (sin redirigir al POST)
        $count = $this->performSync($current, $social);

        return redirect()
            ->route('meta.pages.index')
            ->with('success', "Páginas sincronizadas: {$count}");
    }

    // POST manual desde botón (sigue funcionando)
    public function sync(Request $request)
    {
        $user = Auth::user();

        $social = SocialAccount::where('user_id', $user->id)
            ->where('provider', 'facebook')
            ->first();

        if (!$social) {
            return redirect()->route('facebook.redirect');
        }

        $count = $this->performSync($user, $social);

        return back()->with('success', "Páginas sincronizadas: {$count}");
    }

    // ----------------- LÓGICA COMPARTIDA -----------------
    private function performSync(User $user, SocialAccount $social): int
    {
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';

        $resp = Http::withToken($social->access_token)
            ->get('https://graph.facebook.com/v20.0/me/accounts', ['fields' => $fields]);

        if (!$resp->ok()) {
            Log::error('FB /me/accounts error', [
                'status' => $resp->status(),
                'body'   => $resp->body()
            ]);
            throw new \RuntimeException('No se pudieron obtener las páginas: ' . $resp->body());
        }

        $pages = data_get($resp->json(), 'data', []);
        if (empty($pages)) {
            throw new \RuntimeException("No se encontraron páginas.
- Acepta los permisos requeridos.
- Verifica que la cuenta administre al menos una página.");
        }

        DB::transaction(function () use ($pages, $user, $social) {
            foreach ($pages as $page) {
                $pageId   = (string) data_get($page, 'id');
                $name     = data_get($page, 'name');
                $category = data_get($page, 'category');
                $picture  = "https://graph.facebook.com/v20.0/{$pageId}/picture?type=normal";

                $tasks = data_get($page, 'tasks', []);
                if (!is_array($tasks)) {
                    $tasks = $tasks ? [$tasks] : [];
                }

                $metaPage = \App\Models\MetaPage::updateOrCreate(
                    ['page_id' => $pageId],
                    [
                        'name'        => $name,
                        'category'    => $category,
                        'instagram_business_account_id' => data_get($page, 'connected_instagram_business_account.id'),
                        'picture_url' => $picture,
                        'tasks'       => array_values($tasks),
                    ]
                );

                $user->metaPages()->syncWithoutDetaching([
                    $metaPage->id => [
                        'page_access_token' => data_get($page, 'access_token'),
                        'social_account_id' => $social->id,
                        'expires_at'        => null,
                        'is_active'         => true,
                    ]
                ]);
            }
        });

        return count($pages);
    }
}
