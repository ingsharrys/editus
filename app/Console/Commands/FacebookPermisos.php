<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Muestra qué permisos de Facebook concedió cada cuenta conectada a editus.
 * Sirve para saber por qué no llegan comentarios, estadísticas o demografía:
 * si falta un permiso, la persona debe volver a conectar sus páginas aceptándolo.
 */
class FacebookPermisos extends Command
{
    protected $signature = 'editus:permisos-facebook {--email= : Solo la cuenta de este usuario de editus}';
    protected $description = 'Permisos de Facebook concedidos por cada cuenta conectada (y los que faltan)';

    /** Sin estos la conexión no sirve para publicar, medir o leer comentarios. */
    private const NECESARIOS = [
        'pages_show_list' => 'ver las páginas', 'pages_read_engagement' => 'leer publicaciones', 'read_insights' => 'estadísticas y demografía',
        'pages_read_user_content' => 'leer comentarios', 'pages_manage_posts' => 'publicar y transmitir',
    ];

    /**
     * Solo se informan: Instagram depende de si el medio lo usa, y business_management solo se pide al conectar
     * «desde el portafolio» (a un editor o administrador de la página no le hace falta).
     */
    private const OPCIONALES = [
        'instagram_basic' => 'Instagram (opcional)', 'instagram_content_publish' => 'publicar en Instagram (opcional)',
        'instagram_manage_insights' => 'estadísticas de Instagram (opcional)', 'instagram_manage_comments' => 'comentarios de Instagram (opcional)',
        'business_management' => 'solo al conectar desde el portafolio comercial',
    ];

    public function handle(): int
    {
        $version = (string) config('services.facebook.version', 'v23.0');
        $q = SocialAccount::with('user')->where('provider', 'facebook')->latest('updated_at');
        if ($email = $this->option('email')) $q->whereHas('user', fn($u) => $u->where('email', $email));
        $cuentas = $q->get();
        if ($cuentas->isEmpty()) { $this->warn('No hay cuentas de Facebook conectadas.'); return self::SUCCESS; }

        foreach ($cuentas as $c) {
            $quien = $c->user?->name ?? ($c->usuario_app ? 'usuario de la app #' . $c->usuario_app : 'cuenta #' . $c->id);
            $this->line('');
            $this->info("■ {$quien}" . ($c->user?->email ? " ({$c->user->email})" : ''));
            $r = Http::timeout(20)->get("https://graph.facebook.com/{$version}/me/permissions", ['access_token' => $c->access_token]);
            if (!$r->ok()) { $this->error('  La conexión no responde: ' . (data_get($r->json(), 'error.message') ?: 'HTTP ' . $r->status()) . ' → debe volver a conectar sus páginas.'); continue; }
            $estado = collect((array) data_get($r->json(), 'data', []))->pluck('status', 'permission');
            foreach (self::NECESARIOS + self::OPCIONALES as $permiso => $para) {
                $s = $estado[$permiso] ?? 'no pedido';
                $marca = $s === 'granted' ? '✓' : (isset(self::OPCIONALES[$permiso]) ? '·' : '✕');
                $this->line(sprintf('  %s %-28s %-12s %s', $marca, $permiso, $s === 'granted' ? 'concedido' : ($s === 'declined' ? 'rechazado' : $s), $para));
            }
            if (collect(array_keys(self::NECESARIOS))->contains(fn($p) => ($estado[$p] ?? null) !== 'granted')) {
                $this->warn('  → Faltan permisos: que vuelva a conectar sus páginas (Mis páginas → Conectar mis páginas) y los deje activados.');
            }
        }
        return self::SUCCESS;
    }
}
