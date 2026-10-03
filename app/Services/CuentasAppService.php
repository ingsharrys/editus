<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\MetaPageUser;
use App\Models\SocialAccount;
use App\Models\YoutubeCanal;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas conectadas por los usuarios de la app del editor.
 *
 * Cada usuario de la app (id del backend de esnoticia = usuario_app) vincula
 * sus propias páginas de Facebook y canales de YouTube con un enlace firmado
 * que abre el navegador; editus guarda los tokens con su usuario_app y la
 * app solo ve (y transmite a) lo suyo más lo que la organización conectó
 * desde la web de editus (usuario_app NULL, marcado como visible en la app).
 */
class CuentasAppService
{
    /** Vigencia máxima (segundos) del enlace firmado para abrir la conexión. */
    public const ENLACE_VIGENCIA = 900;

    /**
     * Verifica la firma del backend: sig = HMAC-SHA256("{u}|{exp}", EDITUS_INGEST_TOKEN).
     * Devuelve el usuario de la app o null si la firma no sirve o venció.
     */
    public static function usuarioFirmado(Request $request): ?string
    {
        $u = trim((string) $request->input('u', ''));
        $exp = (int) $request->input('exp', 0);
        $sig = (string) $request->input('sig', '');
        $token = (string) config('services.editus.ingest_token');
        if ($token === '' || $u === '' || $exp <= 0 || $sig === '' || $exp < time()) return null;
        if (!hash_equals(hash_hmac('sha256', "{$u}|{$exp}", $token), $sig)) return null;
        return $u;
    }

    /** Esquema de la app (deep link) al que vuelve el navegador al terminar. */
    public static function urlVolverApp(string $destino = 'cuentas', array $datos = []): string
    {
        $esquema = trim((string) config('services.editor_app.scheme', 'editor')) ?: 'editor';
        return $esquema . '://' . ltrim($destino, '/') . ($datos ? '?' . http_build_query($datos) : '');
    }

    // ------------------------------------------------------------------
    // Qué ve cada usuario
    // ------------------------------------------------------------------

    /**
     * Páginas de Facebook disponibles para un usuario de la app: las suyas
     * (conectadas desde la app) más las de la organización visibles en el editor.
     * Sin usuario → solo las de la organización (comportamiento anterior).
     */
    public function paginasDe(?string $usuarioApp): Collection
    {
        $conUsuarioApp = Schema::hasColumn('meta_page_user', 'usuario_app');
        $visible = Schema::hasColumn('meta_pages', 'visible_en_editor');
        $activo = fn($q) => $q->where('is_active', 1)->whereNotNull('page_access_token');

        $q = MetaPage::query()->where(function ($w) use ($usuarioApp, $conUsuarioApp, $visible, $activo) {
            // De la organización (visible en la app)
            $w->where(function ($o) use ($conUsuarioApp, $visible, $activo) {
                if ($visible) $o->where('visible_en_editor', 1);
                $o->whereHas('vinculos', function ($v) use ($conUsuarioApp, $activo) {
                    $activo($v);
                    if ($conUsuarioApp) $v->whereNull('usuario_app');
                });
            });
            // Las propias del usuario
            if ($usuarioApp !== null && $usuarioApp !== '' && $conUsuarioApp) {
                $w->orWhereHas('vinculos', fn($v) => $activo($v)->where('usuario_app', $usuarioApp));
            }
        });

        $propias = ($usuarioApp !== null && $usuarioApp !== '' && $conUsuarioApp)
            ? MetaPageUser::where('usuario_app', $usuarioApp)->where('is_active', 1)->whereNotNull('page_access_token')->pluck('meta_page_id')->flip()
            : collect();

        return $q->orderBy('name')->get()->each(function (MetaPage $p) use ($propias) {
            $p->setAttribute('propia', $propias->has($p->id));
        })->values();
    }

    /** Ids (page_id de Facebook) accesibles para el usuario. */
    public function puedeUsarPaginas(?string $usuarioApp, array $pageIds): bool
    {
        if ($usuarioApp === null || $usuarioApp === '') return true;
        $permitidas = $this->paginasDe($usuarioApp)->pluck('page_id')->map(fn($x) => (string) $x)->flip();
        foreach ($pageIds as $id) {
            if (!$permitidas->has((string) $id)) return false;
        }
        return true;
    }

    /** Canales de YouTube disponibles: los propios más los de la organización visibles. */
    public function canalesDe(?string $usuarioApp): Collection
    {
        if (!Schema::hasTable('youtube_canales')) return collect();
        $conUsuarioApp = Schema::hasColumn('youtube_canales', 'usuario_app');
        return YoutubeCanal::query()->where(function ($w) use ($usuarioApp, $conUsuarioApp) {
            $w->where(function ($o) use ($conUsuarioApp) {
                $o->where('visible_en_editor', true);
                if ($conUsuarioApp) $o->whereNull('usuario_app');
            });
            if ($usuarioApp !== null && $usuarioApp !== '' && $conUsuarioApp) {
                $w->orWhere('usuario_app', $usuarioApp);
            }
        })->orderBy('titulo')->get();
    }

    public function puedeUsarCanales(?string $usuarioApp, array $canalIds): bool
    {
        if ($usuarioApp === null || $usuarioApp === '' || !$canalIds) return true;
        $permitidos = $this->canalesDe($usuarioApp)->pluck('id')->flip();
        foreach ($canalIds as $id) {
            if (!$permitidos->has((int) $id)) return false;
        }
        return true;
    }

    /** Resumen de las cuentas del usuario para la pantalla "Mis cuentas" de la app. */
    public function resumen(string $usuarioApp): array
    {
        $social = Schema::hasColumn('social_accounts', 'usuario_app')
            ? SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->latest('updated_at')->first()
            : null;
        $paginas = $this->paginasDe($usuarioApp)->map(fn(MetaPage $p) => [
            'id' => $p->id,
            'page_id' => (string) $p->page_id,
            'nombre' => (string) $p->name,
            'foto' => $p->pictureUrl('small'),
            'instagram' => !empty($p->instagram_business_account_id),
            'propia' => (bool) $p->getAttribute('propia'),
        ])->values();
        $canales = $this->canalesDe($usuarioApp)->map(fn(YoutubeCanal $c) => $c->paraApi($usuarioApp))->values();

        return [
            'facebook' => [
                'configurado' => (string) config('services.facebook.client_id') !== '',
                'conectada' => (bool) $social,
                'nombre' => $social?->name,
                'foto' => $social?->avatar,
                'conectada_en' => $social?->updated_at?->toIso8601String(),
                'paginas' => $paginas,
            ],
            'youtube' => [
                'configurado' => (string) config('services.google.client_id') !== '',
                'canales' => $canales,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Facebook: sincronizar páginas de una cuenta conectada
    // ------------------------------------------------------------------

    /**
     * Lee las páginas que administra la cuenta de Facebook y guarda el token
     * de cada una (para un usuario de editus o para un usuario de la app).
     * Devuelve cuántas páginas quedaron vinculadas.
     */
    public function sincronizarPaginas(SocialAccount $social, ?int $userId, ?string $usuarioApp = null): int
    {
        $version = config('services.facebook.version', 'v23.0');
        $base = "https://graph.facebook.com/{$version}";
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';
        $http = Http::withToken($social->access_token);

        $pages = $this->leerPaginas($http, "{$base}/me/accounts?fields={$fields}", true);
        if (empty($pages)) {
            $pages = $this->leerPaginas($http, "{$base}/me/assigned_pages?fields={$fields}", false);
        }
        if (empty($pages)) {
            throw new \RuntimeException("No se encontraron páginas.
- Asegúrate de haber aceptado estos permisos: pages_show_list, pages_manage_posts, pages_manage_metadata, pages_read_engagement, read_insights, business_management.
- Verifica que tu cuenta administre al menos una página o tenga páginas asignadas en Business Manager.
- Revisa en Facebook > Configuración > Integraciones que la app tenga acceso a esa(s) página(s).");
        }

        DB::transaction(function () use ($pages, $userId, $usuarioApp, $social, $base, $http) {
            foreach ($pages as $page) {
                $pageId = (string) data_get($page, 'id');
                $tasks = data_get($page, 'tasks', []);
                if (!is_array($tasks)) $tasks = $tasks ? [$tasks] : [];

                $pageAccessToken = data_get($page, 'access_token');
                if (!$pageAccessToken) {
                    $try = $http->get("{$base}/{$pageId}", ['fields' => 'access_token']);
                    if ($try->ok()) $pageAccessToken = data_get($try->json(), 'access_token');
                    else Log::warning('No page_access_token (fallback)', $try->json() ?? []);
                }

                $metaPage = MetaPage::updateOrCreate(['page_id' => $pageId], [
                    'name' => data_get($page, 'name'),
                    'category' => data_get($page, 'category'),
                    'instagram_business_account_id' => data_get($page, 'connected_instagram_business_account.id'),
                    'picture_url' => "{$base}/{$pageId}/picture?type=normal",
                    'tasks' => array_values($tasks),
                ]);

                $clave = ['meta_page_id' => $metaPage->id, 'user_id' => $userId];
                if (Schema::hasColumn('meta_page_user', 'usuario_app')) $clave['usuario_app'] = $usuarioApp;
                $vinculo = MetaPageUser::query()->where($clave)->first() ?: new MetaPageUser($clave);
                $vinculo->fill([
                    'page_access_token' => $pageAccessToken,
                    'social_account_id' => $social->id,
                    'expires_at' => null,
                    'is_active' => $pageAccessToken ? 1 : 0,
                ]);
                $vinculo->save();

                if (!$pageAccessToken) {
                    Log::warning('Sin token de página: revisar rol/permisos del usuario en la página', ['page_id' => $pageId, 'tasks' => $tasks]);
                } elseif (!in_array('ANALYZE', $tasks, true)) {
                    Log::info('Token OK pero tasks sin ANALYZE (insights pueden fallar)', ['page_id' => $pageId, 'tasks' => $tasks]);
                }
            }
        });

        return count($pages);
    }

    private function leerPaginas($http, ?string $url, bool $estricto): array
    {
        $pages = [];
        while ($url) {
            $resp = $http->get($url);
            if (!$resp->ok()) {
                Log::error('FB páginas error', ['url' => $url, 'status' => $resp->status(), 'body' => $resp->body()]);
                if ($estricto) throw new \RuntimeException('No se pudieron obtener las páginas: ' . $resp->body());
                break;
            }
            $json = $resp->json();
            $pages = array_merge($pages, data_get($json, 'data', []));
            $url = data_get($json, 'paging.next');
        }
        return $pages;
    }

    /** Vuelve a leer las páginas de la cuenta de Facebook del usuario de la app. */
    public function resincronizarFacebook(string $usuarioApp): int
    {
        $social = SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->latest('updated_at')->first();
        if (!$social) throw new \RuntimeException('Primero conecta tu cuenta de Facebook desde la app.');
        return $this->sincronizarPaginas($social, null, $usuarioApp);
    }

    /** Borra los tokens y la cuenta de Facebook del usuario de la app. */
    public function desconectarFacebook(string $usuarioApp): int
    {
        return DB::transaction(function () use ($usuarioApp) {
            $n = MetaPageUser::where('usuario_app', $usuarioApp)->delete();
            SocialAccount::where('provider', 'facebook')->where('usuario_app', $usuarioApp)->delete();
            return $n;
        });
    }

    /** Desconecta un canal de YouTube solo si lo conectó ese usuario. */
    public function desconectarCanal(YoutubeCanal $canal, string $usuarioApp): void
    {
        if ((string) $canal->usuario_app !== $usuarioApp) {
            throw new \RuntimeException('Ese canal lo conectó la organización: solo se puede desconectar desde la web de editus.');
        }
        $canal->delete();
    }
}
