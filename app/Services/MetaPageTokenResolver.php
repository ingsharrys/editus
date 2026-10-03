<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\MetaPageUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MetaPageTokenResolver
{
    /** Usuario de la app (backend de esnoticia) cuyo token se prefiere en esta petición. */
    private static ?string $usuarioApp = null;

    public static function preferirUsuarioApp(?string $usuarioApp): void
    {
        self::$usuarioApp = ($usuarioApp !== null && $usuarioApp !== '') ? $usuarioApp : null;
    }

    /**
     * Devuelve un Page Access Token para la página.
     * Prioriza el token del usuario de la app (si se indicó), luego el pivot del
     * usuario preferido de editus; si no, cualquiera activo de la organización y,
     * por último, el de cualquier usuario de la app que la haya conectado.
     * Si no hay pivot con token, opcionalmente pide al Graph con un System User.
     */
    public function forPage(string $pageId, ?int $preferredUserId = null, ?string $usuarioApp = null): ?string
    {
        $usuarioApp = $usuarioApp ?? self::$usuarioApp;
        $conApp = Schema::hasColumn('meta_page_user', 'usuario_app');
        if ($usuarioApp !== null && $conApp) {
            $propio = $this->tokenDeLaApp($pageId, $usuarioApp);
            if ($propio) return $propio;
        }

        $page = MetaPage::where('page_id', $pageId)
            ->with(['users' => function ($q) use ($preferredUserId) {
                $q->wherePivot('is_active', 1)
                  ->whereNotNull('page_access_token');

                if ($preferredUserId) {
                    // intentamos primero el dueño del post
                    $q->orderByRaw("CASE WHEN users.id = ? THEN 0 ELSE 1 END", [$preferredUserId]);
                }

                $q->orderByDesc('meta_page_user.updated_at');
            }])
            ->first();

        $token = $page?->users?->first()?->pivot?->page_access_token;
        if ($token) return $token;

        // Página conectada solo por usuarios de la app
        if ($conApp) {
            $deApp = $this->tokenDeLaApp($pageId, null);
            if ($deApp) return $deApp;
        }

        // Fallback opcional: System User (Business Manager)
        $sys = config('services.facebook.system_user_token') ?? env('FB_SYSTEM_USER_TOKEN');
        if ($sys) {
            try {
                $r = Http::timeout(20)->get("https://graph.facebook.com/v23.0/{$pageId}", [
                    'fields'       => 'access_token',
                    'access_token' => $sys,
                ]);
                if ($r->ok()) {
                    return data_get($r->json(), 'access_token');
                }
            } catch (\Throwable $e) {}
        }

        return null;
    }

    private function tokenDeLaApp(string $pageId, ?string $usuarioApp): ?string
    {
        $q = MetaPageUser::query()
            ->whereIn('meta_page_id', MetaPage::where('page_id', $pageId)->select('id'))
            ->where('is_active', 1)
            ->whereNotNull('page_access_token')
            ->whereNotNull('usuario_app')
            ->orderByDesc('updated_at');
        if ($usuarioApp !== null) $q->where('usuario_app', $usuarioApp);
        return $q->value('page_access_token');
    }
}
