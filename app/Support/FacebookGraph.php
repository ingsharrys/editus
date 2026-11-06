<?php

namespace App\Support;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookGraph
{
    /**
     * Permisos para conectar páginas. Basta con ser editor o administrador de la página, así que no se pide
     * «business_management»: Facebook se lo niega a quien no está en el portafolio comercial dueño de la página
     * y entonces niega todo. Con $portafolio sí se pide, para quien maneja sus páginas solo desde un portafolio
     * comercial (Business Manager) y no le aparecen.
     */
    public static function permisosPaginas(bool $portafolio = false): array
    {
        $base = (array) config('services.facebook.link_scopes', []) ?: ['email', 'public_profile', 'pages_show_list'];
        $permisos = array_values(array_diff($base, ['business_management']));
        if ($portafolio) $permisos[] = 'business_management';
        return $permisos;
    }

    /**
     * ID de configuración de «Facebook Login for Business», si la app lo usa (en ese caso los permisos los define
     * la configuración en Meta, no la lista de arriba). Para el portafolio puede haber una configuración aparte.
     */
    public static function configId(string $flujo, bool $portafolio = false): ?string
    {
        if ($portafolio && ($id = config('services.facebook.portafolio_config_id'))) return (string) $id;
        $id = $flujo === 'login' ? config('services.facebook.login_config_id') : config('services.facebook.link_config_id');
        return $id ? (string) $id : null;
    }
    /**
     * Qué hacer cuando Facebook no le da acceso a editus (la persona canceló o Facebook negó permisos). El caso más común:
     * «No se concedieron los permisos solicitados a los siguientes activos comerciales»: la cuenta con la que entró no tiene
     * control total de una página o portafolio comercial que quedó elegido en la ventana de Facebook.
     */
    public static function ayudaPermisos(?string $detalle = null): string
    {
        $texto = __('Facebook no le dio acceso a editus. Si viste «No se concedieron los permisos solicitados a los siguientes activos comerciales», tu cuenta no tiene control total del portafolio comercial (Business Manager) o de una página que quedó elegida. Para conectar basta con ser editor o administrador de la página, sin pertenecer al portafolio: vuelve a intentarlo con «Conectar mis páginas» y, en la ventana de Facebook, deja marcadas solo tus páginas (si ya habías conectado antes, entra a «Editar acceso»). La opción «desde un portafolio comercial» es solo para quien administra ese portafolio.');
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
