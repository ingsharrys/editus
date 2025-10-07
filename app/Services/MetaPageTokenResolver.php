<?php

namespace App\Services;

use App\Models\MetaPage;
use Illuminate\Support\Facades\Http;

class MetaPageTokenResolver
{
    /**
     * Devuelve un Page Access Token para la página.
     * Prioriza el pivot del usuario preferido; si no, cualquiera activo.
     * Si no hay pivot con token, opcionalmente pide al Graph con un System User.
     */
    public function forPage(string $pageId, ?int $preferredUserId = null): ?string
    {
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
}
