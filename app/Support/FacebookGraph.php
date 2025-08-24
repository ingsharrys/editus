<?php

namespace App\Support;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookGraph
{
    /**
     * Busca una página por ID en /me/accounts usando el token de usuario de Facebook.
     * Recorre paginación (limit=200 + cursor "after").
     *
     * @param  string $userAccessToken  access_token del usuario (propietario)
     * @param  string $targetPageId     ID de la página a encontrar
     * @return array|null               Array con datos de la página (incluye access_token) o null si no se encontró
     */
    public function getPageDataFromMeAccounts(string $userAccessToken, string $targetPageId): ?array
    {
        $fields = 'id,name,category,access_token,tasks,connected_instagram_business_account,picture{url}';
        $params = ['fields' => $fields, 'limit' => 200];

        while (true) {
            $resp = Http::withToken($userAccessToken)
                ->get('https://graph.facebook.com/v20.0/me/accounts', $params);

            if (!$resp->ok()) {
                Log::warning('FB /me/accounts error', ['status' => $resp->status(), 'body' => $resp->body()]);
                return null;
            }

            $json  = $resp->json();
            $pages = $json['data'] ?? [];
            $found = collect($pages)->firstWhere('id', (string)$targetPageId);
            if ($found) {
                return $found;
            }

            $after = data_get($json, 'paging.cursors.after');
            if (!$after) {
                break;
            }
            $params['after'] = $after;
        }

        return null;
    }
}
