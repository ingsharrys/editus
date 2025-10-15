<?php
namespace App\Services;

use App\Models\MetaPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PageTokenResolver
{
    public function forPage(MetaPost $post, bool $needInsights = false): array
    {
        $post->loadMissing('page.users');
        $page   = $post->page;
        $pageId = $page?->page_id;
        if (!$pageId) return ['ok'=>false,'error'=>'page_id missing'];

        // 1) Pivot token activo primero (owner y luego cualquier activo)
        $cands = [];
        $owner = $page->users->firstWhere('id', $post->user_id);
        if ($owner?->pivot?->is_active && $owner?->pivot?->page_access_token) {
            $cands[] = ['token'=>$owner->pivot->page_access_token, 'source'=>'pivot_owner', 'pivot'=>$owner->pivot];
        }
        foreach ($page->users as $u) {
            if ($u->id === ($owner?->id)) continue;
            if ($u->pivot?->is_active && $u->pivot?->page_access_token) {
                $cands[] = ['token'=>$u->pivot->page_access_token, 'source'=>'pivot', 'pivot'=>$u->pivot];
            }
        }

        // 2) Tokens de usuario (para derivar Page Token)
        //    Usa primero el dueño; si no, cualquier social_account activo.
        $userOrder = collect([$owner])->filter()->merge($page->users->reject(fn($u)=>$owner && $u->id===$owner->id));
        foreach ($userOrder as $u) {
            if ($u?->pivot?->is_active && $u?->pivot?->social_account_id) {
                $sa = SocialAccount::find($u->pivot->social_account_id);
                if ($sa?->access_token) {
                    $pageTok = $this->exchangeUserForPageToken($pageId, $sa->access_token);
                    if ($pageTok) {
                        // Persistir en pivot
                        $u->pivot->page_access_token = $pageTok;
                        $u->pivot->save();
                        $cands[] = ['token'=>$pageTok, 'source'=>'derived_from_user', 'pivot'=>$u->pivot];
                        break; // con uno basta
                    }
                }
            }
        }

        // 3) Fallback opcional desde .env (para métricas)
        $envTok = config('services.facebook.metrics_token');
        if ($envTok) $cands[] = ['token'=>$envTok, 'source'=>'metrics_env'];

        // 4) Elegir el primero que cumpla (si se pide read_insights, validar con un ping)
        foreach ($cands as $c) {
            if (!$needInsights) return ['ok'=>true,'token'=>$c['token'],'source'=>$c['source']];
            if ($this->hasReadInsights($pageId, $c['token'])) {
                return ['ok'=>true,'token'=>$c['token'],'source'=>$c['source']];
            }
        }

        return ['ok'=>false,'error'=>'no usable page token'];
    }

    private function exchangeUserForPageToken(string $pageId, string $userToken): ?string
    {
        try {
            $r = Http::timeout(30)->connectTimeout(10)->retry(2, 800)
                ->get("https://graph.facebook.com/v23.0/{$pageId}", [
                    'fields'=>'access_token',
                    'access_token'=>$userToken,
                ]);
            return $r->ok() ? (data_get($r->json(),'access_token') ?: null) : null;
        } catch (\Throwable $e) {
            Log::debug('[token] exchange error', ['err'=>$e->getMessage()]);
            return null;
        }
    }

    private function hasReadInsights(string $pageId, string $pageToken): bool
    {
        try {
            // ping barato que requiere read_insights: una métrica de página
            $r = Http::timeout(20)->connectTimeout(8)->retry(1, 600)
                ->get("https://graph.facebook.com/v23.0/{$pageId}/insights", [
                    'metric'=>'page_impressions',
                    'period'=>'day',
                    'access_token'=>$pageToken,
                ]);
            if ($r->ok()) return true;
            $msg = (string)($r->json()['error']['message'] ?? $r->body());
            return !str_contains($msg, 'read_insights') ? true : false; // si falla por otra cosa, no bloquees
        } catch (\Throwable $e) {
            return false;
        }
    }
}
