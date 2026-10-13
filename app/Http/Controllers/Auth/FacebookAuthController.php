<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\MetaPage;


class FacebookAuthController extends Controller
{
    public function redirect()
    {
        $version = config('services.facebook.version', 'v23.0');
        $scopes = config('services.facebook.link_scopes', ['email', 'pages_show_list']);

        $with = [];

        // si tienes config_id (business login), lo mandas
        if ($cid = config('services.facebook.login_config_id')) {
            $with['config_id'] = $cid;
        }

        return Socialite::driver('facebook')
            ->usingGraphVersion($version)
            ->scopes($scopes)
            ->with($with)
            ->redirect();
    }

    public function callback(Request $request)
    {
        Log::info('FB callback payload', [
            'query' => $request->query(),
            'full' => $request->all(),
        ]);

        if ($request->has('error')) {
            $desc = $request->get('error_description') ?: $request->get('error_reason') ?: $request->get('error');
            return redirect()->route('facebook.login')->with('error', __('Facebook OAuth error: :detalle', ['detalle' => $desc]));
        }

        if (!$request->filled('code')) {
            return redirect()->route('facebook.login')->with('error', __('Facebook no devolvió el parámetro "code".'));
        }

        $version = config('services.facebook.version', 'v23.0');

        try {
            $fbUser = Socialite::driver('facebook')->usingGraphVersion($version)->user();
        } catch (\Throwable $e) {
            return redirect()->route('facebook.login')->with('error', __('Fallo al obtener el usuario de FB: :detalle', ['detalle' => $e->getMessage()]));
        }

        // 1. Intercambio por token de larga duración siempre (incluso si el usuario ya existe)
        $ex = Http::get("https://graph.facebook.com/{$version}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $fbUser->token,
        ]);

        $userAccessToken = $fbUser->token;
        $expiresAt = null;

        if ($ex->ok()) {
            $j = $ex->json();
            $userAccessToken = $j['access_token'] ?? $userAccessToken;
            if (!empty($j['expires_in'])) {
                $expiresAt = now()->addSeconds((int) $j['expires_in']);
            }
        } elseif (!empty($fbUser->expiresIn)) {
            $expiresAt = now()->addSeconds((int) $fbUser->expiresIn);
        }

        // 2. Gestionar el usuario (crear o recuperar)
        $email = $fbUser->getEmail();
        $user = $email ? User::where('email', $email)->first() : null;

        // Buscar si ya existía por el ID de proveedor en caso de que no haya correo
        $existingSocial = SocialAccount::where('provider', 'facebook')
            ->where('provider_user_id', $fbUser->getId())
            ->first();

        if ($existingSocial && !$user) {
            $user = $existingSocial->user;
        }

        if (!$user) {
            $user = User::create([
                'name' => $fbUser->getName() ?: $fbUser->getNickname() ?: 'FB User',
                'email' => $email ?: ('fb_' . $fbUser->getId() . '@no-email.local'),
                'password' => bcrypt(Str::random(32)),
                'role_id' => optional(Role::where('slug', 'user')->first())->id,
            ]);
        }

        // Un usuario desactivado no entra (aunque use Facebook)
        if (!$user->estaActivo()) {
            return redirect()->route('login')->withErrors(['email' => __('Tu usuario está desactivado. Habla con un administrador.')]);
        }

        // 3. Crear o actualizar la cuenta social (asegurando tener siempre el token fresco)
        $social = SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_user_id' => $fbUser->getId(),
            ],
            [
                'access_token' => $userAccessToken,
                'refresh_token' => $fbUser->refreshToken ?? null,
                'expires_at' => $expiresAt,
                'raw' => method_exists($fbUser, 'user') ? $fbUser->user : null,
            ]
        );

        // 4. Iniciar sesión
        Auth::login($user, true);

        // 5. Sincronizar las páginas
        try {
            $count = $this->performSync($user, $social);
        } catch (\Throwable $e) {
            Log::error('[FB] callback: fallo en performSync', ['err' => $e->getMessage()]);
            return redirect()->route('meta.pages.index')->with('error', __('Usuario conectado, pero falló la sincronización: :detalle', ['detalle' => $e->getMessage()]));
        }

        // 6. Redirigir con el mensaje de éxito y el conteo
        return redirect()->route('meta.pages.index')->with('success', __('¡Conexión exitosa! Páginas sincronizadas: :n', ['n' => $count]));
    }

    private function performSync(User $user, SocialAccount $social): int
    {
        $version = config('services.facebook.version', 'v23.0');
        $base = "https://graph.facebook.com/{$version}";

        $fieldsFull = 'id,name,category,access_token,tasks,instagram_business_account,picture{url}';
        // Sin el campo de Instagram: para tokens que aún no tienen instagram_basic,
        // Facebook rechaza la llamada completa si se pide ese campo.
        $fieldsBasic = 'id,name,category,access_token,tasks';

        $http = Http::withToken($social->access_token)->timeout(30)->connectTimeout(10);

        /**
         * Recorre un edge paginado. Si Facebook rechaza los campos completos
         * (error #100 por campo/permiso), reintenta con los básicos.
         * @return array{0: array, 1: ?string} [páginas, error]
         */
        $fetchEdge = function (string $edge) use ($http, $base, $fieldsFull, $fieldsBasic): array {
            foreach ([$fieldsFull, $fieldsBasic] as $attempt => $fields) {
                $rows = [];
                $url = "{$base}/me/{$edge}?fields={$fields}&limit=100";
                $failed = null;

                while ($url) {
                    $resp = $http->get($url);
                    if (!$resp->ok()) {
                        $failed = $resp->body();
                        break;
                    }
                    $json = $resp->json();
                    $rows = array_merge($rows, (array) data_get($json, 'data', []));
                    $url = data_get($json, 'paging.next');
                }

                if ($failed === null) {
                    return [$rows, null];
                }

                $code = (int) data_get(json_decode($failed, true), 'error.code');
                $isFieldError = $code === 100 || str_contains($failed, 'nonexisting field') || str_contains($failed, 'instagram');
                if ($attempt === 0 && $isFieldError) {
                    Log::info('[sync] campos completos rechazados, reintentando con básicos', ['edge' => $edge]);
                    continue;
                }

                return [[], $failed];
            }

            return [[], null];
        };

        // 1) Páginas "clásicas" del usuario: /me/accounts
        [$pages, $err] = $fetchEdge('accounts');
        if ($err !== null) {
            Log::error('FB /me/accounts error', ['body' => $err]);
            throw new \RuntimeException(__('No se pudieron obtener las páginas: :detalle', ['detalle' => $this->graphErrorMessage($err)]));
        }

        // 2) Fallback: páginas asignadas vía Business Manager: /me/assigned_pages
        if (empty($pages)) {
            [$pages, $err] = $fetchEdge('assigned_pages');
            if ($err !== null) {
                Log::warning('FB /me/assigned_pages error', ['body' => $err]);
            }
        }

        // 3) Si sigue vacío, no hay páginas para ese usuario
        if (empty($pages)) {
            throw new \RuntimeException(__("No se encontraron páginas.\n- Asegúrate de haber aceptado estos permisos: pages_show_list, pages_manage_posts, pages_manage_metadata, pages_read_engagement, read_insights, business_management.\n- Verifica que tu cuenta administre al menos una página o tenga páginas asignadas en Business Manager.\n- Revisa en Facebook > Configuración > Integraciones que la app tenga acceso a esa(s) página(s)."));
        }

        // 4) Guardado página por página: un fallo en una no bloquea a las demás
        $saved = 0;
        foreach ($pages as $page) {
            try {
                DB::transaction(function () use ($page, $user, $social, $base, $http) {
                    $pageId = (string) data_get($page, 'id');
                    $igId = data_get($page, 'instagram_business_account.id');
                    $pageAccessToken = data_get($page, 'access_token');

                    if (!$pageAccessToken) {
                        $try = $http->get("{$base}/{$pageId}", ['fields' => 'access_token']);
                        if ($try->ok()) {
                            $pageAccessToken = data_get($try->json(), 'access_token');
                        } else {
                            Log::warning('No page_access_token (fallback)', $try->json() ?? []);
                        }
                    }

                    $tasks = data_get($page, 'tasks', []);
                    if (!is_array($tasks)) {
                        $tasks = $tasks ? [$tasks] : [];
                    }

                    $metaPage = MetaPage::updateOrCreate(
                        ['page_id' => $pageId],
                        [
                            'name' => data_get($page, 'name'),
                            'category' => data_get($page, 'category'),
                            'instagram_business_account_id' => $igId,
                            'picture_url' => "{$base}/{$pageId}/picture?type=normal",
                            'tasks' => array_values($tasks),
                        ]
                    );

                    $user->metaPages()->syncWithoutDetaching([
                        $metaPage->id => [
                            'page_access_token' => $pageAccessToken,
                            'social_account_id' => $social->id,
                            'expires_at' => null,
                            'is_active' => $pageAccessToken ? 1 : 0,
                            'updated_at' => now(),
                        ]
                    ]);

                    if (!$pageAccessToken) {
                        Log::warning('Sin token de página: revisar rol/permisos del usuario en la página', ['page_id' => $pageId, 'tasks' => $tasks]);
                    } elseif (!in_array('ANALYZE', $tasks, true)) {
                        Log::info('Token OK pero tasks sin ANALYZE (insights pueden fallar)', ['page_id' => $pageId, 'tasks' => $tasks]);
                    }
                });
                $saved++;
            } catch (\Throwable $e) {
                Log::warning('[sync] página no guardada', ['page' => data_get($page, 'id'), 'name' => data_get($page, 'name'), 'err' => $e->getMessage()]);
            }
        }

        return $saved;
    }

    /** Mensaje legible de un error de la Graph API (cuerpo JSON de la respuesta). */
    private function graphErrorMessage(string $body): string
    {
        $e = (array) data_get(json_decode($body, true), 'error', []);
        $msg = (string) ($e['error_user_msg'] ?? $e['message'] ?? '');
        if ($msg === '') return mb_substr(trim(strip_tags($body)), 0, 200) ?: __('error desconocido');
        return trim($msg . (isset($e['code']) ? " (#{$e['code']})" : ''));
    }

    /**
     * Ruta temporal para ver la URL exacta que genera Socialite.
     * Úsala SOLO para debug y luego la borras.
     */
    public function debugUrl()
    {
        $scopes = config('services.facebook.login_scopes', ['email', 'public_profile']);
        $version = config('services.facebook.version', 'v23.0');

        $url = Socialite::driver('facebook')
            ->usingGraphVersion($version)
            ->scopes($scopes)
            ->redirect()
            ->getTargetUrl();

        dd($url);
    }

    public function redirectBasic()
    {
        return $this->redirect();
    }

    public function callbackBasic(Request $request)
    {
        return $this->callback($request);
    }
}
