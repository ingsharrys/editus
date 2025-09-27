<?php

namespace App\Support;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookGraph
{

    /**
     * Busca una página por ID en /me/accounts usando el token de usuario.
     * Recorre paginación (limit=200 + cursor "after").
     */
    public function getPageDataFromMeAccounts(string $userAccessToken, string $targetPageId): ?array
    {
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';
        $params = ['fields' => $fields, 'limit' => 200];

        while (true) {
            $resp = Http::withToken($userAccessToken)
                ->get(self::url('me/accounts'), $params); // <— usa url()

            if (!$resp->ok()) {
                Log::warning('FB /me/accounts error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'trace' => $resp->header('x-fb-trace-id'),
                ]);
                return null;
            }

            $json = $resp->json();
            $pages = $json['data'] ?? [];
            $found = collect($pages)->firstWhere('id', (string) $targetPageId);
            if ($found) {
                return $found;
            }

            $after = data_get($json, 'paging.cursors.after');
            if (!$after)
                break;
            $params['after'] = $after;
        }

        return null;
    }

    public static function url(string $path, bool $video = false): string
    {
        $v = config('services.facebook.version', 'v23.0');
        $host = $video ? 'https://graph-video.facebook.com' : 'https://graph.facebook.com';
        return "{$host}/{$v}/{$path}";
    }
}
