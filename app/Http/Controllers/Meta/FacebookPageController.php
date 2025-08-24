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
use Illuminate\Auth\Access\AuthorizationException;
use App\Support\FacebookGraph;


class FacebookPageController extends Controller
{
    protected FacebookGraph $fb;

    public function __construct(FacebookGraph $fb)
    {
        $this->fb = $fb;
    }
    public function index(Request $request)
    {
        $user = Auth::user();
        $ownerId = $request->query('owner_id');

        if ($user->isAdmin()) {
            $owners = User::whereHas('metaPages')
                ->orderBy('name')
                ->get(['id', 'name']);

            $query = \App\Models\MetaPage::with('users');

            if ($ownerId) {
                $query->whereHas('users', function ($q) use ($ownerId) {
                    $q->where('users.id', $ownerId);
                });
            }

            $pages = $query->latest()
                ->paginate(18)
                ->appends($request->query()); // <-- conserva ?owner_id en la paginación
        } else {
            $owners = collect(); // vacío para no romper la vista
            $pages = $user->metaPages()
                ->with('users')
                ->paginate(18);
        }

        return view('meta.pages.index', compact('pages', 'owners', 'ownerId'));
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
    public function unlinkAccount()
    {
        $user = Auth::user();

        DB::transaction(function () use ($user) {
            // Limpia tokens del pivot de ese usuario con todas sus páginas
            $user->metaPages()->updateExistingPivot(
                $user->metaPages()->pluck('meta_pages.id')->all(),
                ['is_active' => false, 'page_access_token' => null, 'expires_at' => null]
            );

            // Elimina (o deja nulo) su SocialAccount de facebook
            SocialAccount::where('user_id', $user->id)
                ->where('provider', 'facebook')
                ->delete();
        });

        return back()->with('success', 'Facebook desvinculado de tu cuenta y tokens limpiados.');
    }

    public function unlinkPage(Request $request, MetaPage $metaPage)
    {
        $user    = auth()->user();
        $ownerId = $request->input('owner_id'); // opcional

        if ($user->isAdmin()) {
            if ($ownerId) {
                // Desvincula solo para ese propietario
                $exists = $metaPage->users()->where('users.id', $ownerId)->exists();
                if (!$exists) {
                    return back()->with('error', 'Ese propietario no está asociado a esta página.');
                }

                $metaPage->users()->updateExistingPivot($ownerId, [
                    'is_active'         => false,
                    'page_access_token' => '',   // o null si tu columna lo permite
                    'expires_at'        => null,
                ]);

                return back()->with('success', "Página «{$metaPage->name}» desvinculada para el usuario seleccionado.");
            }

            // Desvincula para todos los usuarios que tengan esta página
            $userIds = $metaPage->users()->pluck('users.id')->all();
            if (empty($userIds)) {
                return back()->with('success', "Página «{$metaPage->name}» no tenía vínculos activos.");
            }

            foreach ($userIds as $uid) {
                $metaPage->users()->updateExistingPivot($uid, [
                    'is_active'         => false,
                    'page_access_token' => '',   // o null si tu columna lo permite
                    'expires_at'        => null,
                ]);
            }

            return back()->with('success', "Página «{$metaPage->name}» desvinculada para todos los usuarios.");
        }

        // Usuario normal: solo su propio pivot
        $exists = $user->metaPages()->where('meta_page_id', $metaPage->id)->exists();
        abort_unless($exists, 403);

        $user->metaPages()->updateExistingPivot($metaPage->id, [
            'is_active'         => false,
            'page_access_token' => '',   // o null si tu columna lo permite
            'expires_at'        => null,
        ]);

        return back()->with('success', "Página «{$metaPage->name}» desvinculada.");
    }

    public function linkSinglePage(Request $request, MetaPage $metaPage)
    {
        $user    = auth()->user();
        $ownerId = $request->input('owner_id'); // opcional: admin puede forzar propietario

        // === Usuario normal: solo su propia página ===
        if (!$user->isAdmin()) {
            $pivot = $user->metaPages()->where('meta_page_id', $metaPage->id)->first();
            abort_unless($pivot, 403);

            $social = SocialAccount::where('user_id', $user->id)
                ->where('provider', 'facebook')
                ->first();

            if (!$social) {
                return redirect()->route('facebook.redirect')
                    ->with('info', 'Conecta tu Facebook y vuelve a intentar.');
            }

            $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string)$metaPage->page_id);
            if (!$found || empty($found['access_token'])) {
                return back()->with('error', 'No se encontró token para esta página. Verifica tu rol y permisos (pages_manage_posts).');
            }

            // Actualiza metadatos (opcional)
            $metaPage->update([
                'name'        => $found['name'] ?? $metaPage->name,
                'category'    => $found['category'] ?? $metaPage->category,
                'picture_url' => "https://graph.facebook.com/v20.0/{$metaPage->page_id}/picture?type=normal",
                'tasks'       => is_array($found['tasks'] ?? null) ? array_values($found['tasks']) : $metaPage->tasks,
                'instagram_business_account_id' => data_get($found, 'connected_instagram_business_account.id', $metaPage->instagram_business_account_id),
            ]);

            // Reactiva SOLO el pivot del usuario actual
            $user->metaPages()->syncWithoutDetaching([
                $metaPage->id => [
                    'page_access_token' => $found['access_token'],
                    'social_account_id' => $social->id,
                    'expires_at'        => null,
                    'is_active'         => true,
                ]
            ]);

            return back()->with('success', "Página «{$metaPage->name}» vinculada correctamente.");
        }

        // === ADMIN ===
        // Si vino owner_id, úsalo; si no, detecta un propietario con social_account_id en el pivot
        if ($ownerId) {
            $owner = User::find($ownerId);
            if (!$owner) return back()->with('error', 'El propietario especificado no existe.');
            if (!$metaPage->users()->where('users.id', $owner->id)->exists()) {
                return back()->with('error', 'Ese propietario no tiene esta página asociada.');
            }
            $social = SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();
            if (!$social) return back()->with('error', "«{$owner->name}» no tiene Facebook conectado.");
        } else {
            $owner = $metaPage->users()
                ->wherePivotNotNull('social_account_id')
                ->withPivot(['social_account_id'])
                ->first();

            if (!$owner) return back()->with('error', 'No hay un usuario propietario con Facebook conectado para esta página.');

            $social = SocialAccount::find($owner->pivot->social_account_id)
                ?: SocialAccount::where('user_id', $owner->id)->where('provider', 'facebook')->first();

            if (!$social) return back()->with('error', 'No se encontró el token del propietario.');
        }

        $found = $this->fb->getPageDataFromMeAccounts($social->access_token, (string)$metaPage->page_id);
        if (!$found || empty($found['access_token'])) {
            return back()->with('error', 'El propietario no tiene permisos actuales sobre esta página o no hay token.');
        }

        // Actualiza metadatos (opcional)
        $metaPage->update([
            'name'        => $found['name'] ?? $metaPage->name,
            'category'    => $found['category'] ?? $metaPage->category,
            'picture_url' => "https://graph.facebook.com/v20.0/{$metaPage->page_id}/picture?type=normal",
            'tasks'       => is_array($found['tasks'] ?? null) ? array_values($found['tasks']) : $metaPage->tasks,
            'instagram_business_account_id' => data_get($found, 'connected_instagram_business_account.id', $metaPage->instagram_business_account_id),
        ]);

        // Reactiva el pivot del DUEÑO (no el del admin)
        $metaPage->users()->updateExistingPivot($owner->id, [
            'page_access_token' => $found['access_token'],
            'social_account_id' => $social->id,
            'expires_at'        => null,
            'is_active'         => true,
        ]);

        return back()->with('success', "Página «{$metaPage->name}» vinculada usando la cuenta de «{$owner->name}».");
    }
}
