<?php

namespace App\Support;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookGraph
{
    /**
     * Qué hacer cuando Facebook no le da acceso a editus (la persona canceló o Facebook negó permisos). El caso más común:
     * «No se concedieron los permisos solicitados a los siguientes activos comerciales»: la cuenta con la que entró no tiene
     * control total de una página o portafolio comercial que quedó elegido en la ventana de Facebook.
     */
    public static function ayudaPermisos(?string $detalle = null): string
    {
        $texto = __('Facebook no le dio acceso a editus. Si viste «No se concedieron los permisos solicitados a los siguientes activos comerciales», la cuenta de Facebook con la que entraste no tiene control total de alguna página o portafolio comercial que quedó elegido. Vuelve a intentarlo y, en la ventana de Facebook, deja marcadas solo las páginas y negocios que administras (si ya habías conectado antes, entra a «Editar acceso»), o pide al administrador de esa página o portafolio que te dé control total.');
        $detalle = trim((string) $detalle);
        return $detalle !== '' ? $texto . ' ' . __('(Detalle de Facebook: :d)', ['d' => \Illuminate\Support\Str::limit($detalle, 200)]) : $texto;
    }

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
