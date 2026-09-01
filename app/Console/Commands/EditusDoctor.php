<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\MetaPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Diagnóstico integral de editus: configuración, base de datos, cola,
 * scheduler y (con --live) validez de los tokens contra la Graph API.
 *
 *   php artisan editus:doctor          # checks locales (sin llamadas a Meta)
 *   php artisan editus:doctor --live   # además valida tokens con Meta
 *   php artisan editus:doctor --live --page=Opanoticias
 */
class EditusDoctor extends Command
{
    protected $signature = 'editus:doctor
        {--live : Validar tokens y accesos contra la Graph API de Meta}
        {--page= : Limitar la verificación en vivo a páginas cuyo nombre contenga este texto}';

    protected $description = 'Diagnóstico integral: configuración, BD, cola, scheduler y tokens de Meta';

    private const REQUIRED_SCOPES = [
        'pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'pages_manage_metadata',
        'read_insights', 'business_management', 'instagram_basic', 'instagram_content_publish',
        'instagram_manage_insights',
    ];

    private array $actions = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>🩺 editus:doctor — ' . now()->format('Y-m-d H:i') . '</>');

        $this->section('Configuración');
        $this->checkConfig();

        $this->section('Almacenamiento y procesos');
        $this->checkStorage();
        $this->checkSchedulerAndQueue();

        $this->section('Base de datos');
        $this->checkDatabase();

        $this->section('Puente esnoticia (medios ↔ páginas)');
        $this->checkMedios();

        if ($this->option('live')) {
            $this->section('Validación en vivo contra Meta');
            $this->checkLive();
        } else {
            $this->line('  <fg=gray>(usa --live para validar tokens contra la Graph API de Meta)</>');
        }

        $this->section('Acciones recomendadas');
        if (empty($this->actions)) {
            $this->line('  <fg=green>✔ Nada pendiente. Todo en orden.</>');
        } else {
            foreach (array_unique($this->actions) as $i => $a) {
                $this->line('  ' . ($i + 1) . '. ' . $a);
            }
        }
        $this->line('');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ helpers

    private function section(string $title): void
    {
        $this->line('');
        $this->line("<options=bold>── {$title}</>");
    }

    private function ok(string $msg): void
    {
        $this->line("  <fg=green>✅</> {$msg}");
    }

    private function warn(string $msg, ?string $action = null): void
    {
        $this->line("  <fg=yellow>⚠️ </> {$msg}");
        if ($action) {
            $this->actions[] = $action;
        }
    }

    private function fail(string $msg, ?string $action = null): void
    {
        $this->line("  <fg=red>❌</> {$msg}");
        if ($action) {
            $this->actions[] = $action;
        }
    }

    // ------------------------------------------------------------------ checks

    private function checkConfig(): void
    {
        $env = config('app.env');
        $debug = config('app.debug');
        $env === 'production'
            ? $this->ok("APP_ENV=production")
            : $this->warn("APP_ENV={$env}", 'Poner APP_ENV=production en .env y ejecutar php artisan config:cache');
        $debug
            ? $this->warn('APP_DEBUG=true (expone datos internos en errores)', 'Poner APP_DEBUG=false en .env y ejecutar php artisan config:cache')
            : $this->ok('APP_DEBUG=false');

        $this->ok('APP_URL=' . config('app.url'));

        foreach ([
            'services.facebook.client_id' => 'FACEBOOK_CLIENT_ID',
            'services.facebook.client_secret' => 'FACEBOOK_CLIENT_SECRET',
            'services.facebook.link_config_id' => 'FACEBOOK_LINK_CONFIG_ID',
            'services.facebook.system_user_token' => 'FACEBOOK_SYSTEM_USER_TOKEN',
            'services.editus.ingest_token' => 'EDITUS_INGEST_TOKEN',
        ] as $key => $envName) {
            $val = config($key);
            if ($val) {
                $this->ok("{$envName} configurado" . ($envName === 'FACEBOOK_SYSTEM_USER_TOKEN' ? ' (' . strlen($val) . ' chars)' : ''));
            } elseif ($envName === 'FACEBOOK_LINK_CONFIG_ID') {
                $this->warn("{$envName} vacío (se usarán scopes clásicos en vez de Login for Business)");
            } elseif ($envName === 'EDITUS_INGEST_TOKEN') {
                $this->fail("{$envName} vacío: el puente /api/articulos/publicar rechazará todo con 401", "Definir EDITUS_INGEST_TOKEN en .env (mismo valor que EDITUS_TOKEN en esnoticia) y php artisan config:cache");
            } else {
                $this->fail("{$envName} vacío", "Definir {$envName} en .env y ejecutar php artisan config:cache");
            }
        }

        $cached = file_exists(base_path('bootstrap/cache/config.php'));
        $cached ? $this->ok('Configuración cacheada (producción)') : $this->warn('Configuración sin cachear', 'Ejecutar bash deploy.sh (activa config/route/view cache)');
    }

    private function checkStorage(): void
    {
        $link = public_path('storage');
        if (is_link($link) || is_dir($link)) {
            $this->ok('public/storage existe (storage:link)');
        } else {
            $this->fail('Falta public/storage: Facebook/Instagram no podrán descargar fotos y videos', 'Ejecutar php artisan storage:link');
        }

        foreach (['images/tmp', 'videos/tmp'] as $dir) {
            $abs = storage_path('app/public/' . $dir);
            if (!is_dir($abs)) {
                @mkdir($abs, 0755, true);
            }
            is_writable($abs) ? $this->ok("storage/app/public/{$dir} escribible") : $this->fail("storage/app/public/{$dir} NO escribible", "Revisar permisos de storage/app/public/{$dir}");
        }
    }

    private function checkSchedulerAndQueue(): void
    {
        $log = storage_path('logs/schedule.log');
        if (file_exists($log)) {
            $age = time() - filemtime($log);
            $age <= 180
                ? $this->ok('Scheduler activo (última ejecución hace ' . $age . 's)')
                : $this->fail('Scheduler sin ejecutarse hace ' . round($age / 60) . ' min', 'Revisar el cron: * * * * * cd /ruta/app && php artisan schedule:run');
        } else {
            $this->warn('No hay storage/logs/schedule.log (no se puede verificar el cron)', 'Verificar con crontab -l que exista la línea de schedule:run');
        }

        try {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $oldestAge = $oldest ? time() - (int) $oldest : 0;
            if ($pending === 0) {
                $this->ok('Cola vacía (worker al día)');
            } elseif ($oldestAge > 300) {
                $this->fail("Cola con {$pending} trabajos; el más antiguo lleva " . round($oldestAge / 60) . " min sin procesarse", 'El worker no está corriendo: verificar el scheduler (lanza queue:work cada minuto)');
            } else {
                $this->ok("Cola con {$pending} trabajo(s) recientes (procesándose)");
            }

            $failed24 = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            $failed24 > 0
                ? $this->warn("{$failed24} trabajo(s) fallidos en las últimas 24h", 'Revisar: php artisan queue:failed (y reintentar con queue:retry all)')
                : $this->ok('Sin trabajos fallidos en 24h');
        } catch (\Throwable $e) {
            $this->warn('No se pudo leer la cola: ' . $e->getMessage());
        }
    }

    private function checkDatabase(): void
    {
        $pages = MetaPage::count();
        $withToken = DB::table('meta_page_user')->where('is_active', 1)->whereNotNull('page_access_token')->where('page_access_token', '!=', '')->distinct()->count('meta_page_id');
        $this->ok("{$pages} páginas registradas, {$withToken} con al menos un token activo guardado");
        if ($pages > 0 && $withToken < $pages) {
            $this->warn(($pages - $withToken) . ' página(s) sin ningún token guardado (publicarán solo si el Usuario de Sistema tiene acceso)', 'Sincronizar con cada cuenta de Facebook administradora o asignar las páginas a editus-bot en Business Manager');
        }

        $withIg = MetaPage::whereNotNull('instagram_business_account_id')->count();
        $this->ok("{$withIg} página(s) con Instagram Business detectado");

        $socials = DB::table('social_accounts')->count();
        $this->ok("{$socials} cuenta(s) de Facebook conectadas por usuarios");

        try {
            Campaign::esnoticia();
            $active = Campaign::selectable()->count();
            $this->ok("Campañas: sistema 'Esnoticia' OK, {$active} campaña(s) seleccionable(s) para publicar");
            if ($active === 0) {
                $this->fail('No hay campañas activas: nadie puede publicar manualmente', 'Crear una campaña en /campanas');
            }
        } catch (\Throwable $e) {
            $this->fail('Tabla de campañas no disponible: ' . $e->getMessage(), 'Ejecutar php artisan migrate --force');
        }
    }

    private function checkMedios(): void
    {
        $medios = (array) config('services.editus.medios', []);
        if (empty($medios)) {
            $this->warn('No hay medios configurados en services.editus.medios');
            return;
        }

        $bySlug = MetaPage::whereNotNull('medio_slug')->get(['id', 'name', 'medio_slug', 'instagram_business_account_id'])->groupBy('medio_slug');

        foreach ($medios as $slug => $nombre) {
            $list = $bySlug->get($slug, collect());
            if ($list->isEmpty()) {
                $this->fail("Medio «{$nombre}» ({$slug}) sin páginas vinculadas: sus artículos de esnoticia NO se publican", "En Meta/Páginas, seleccionar «Medio esnoticia → {$nombre}» en la tarjeta de la página correspondiente");
                continue;
            }
            $names = $list->map(fn($p) => $p->name . ($p->instagram_business_account_id ? ' 📸' : ''))->implode(', ');
            $this->ok("Medio «{$nombre}» → {$names}");
        }

        $orphans = $bySlug->keys()->diff(array_keys($medios));
        if ($orphans->isNotEmpty()) {
            $this->warn('Páginas con medio_slug no reconocido: ' . $orphans->implode(', '));
        }
    }

    private function checkLive(): void
    {
        $v = config('services.facebook.version', 'v23.0');
        $base = "https://graph.facebook.com/{$v}";
        $sys = config('services.facebook.system_user_token');

        // ---- Usuario de Sistema
        $sysOk = false;
        if (!$sys) {
            $this->fail('Sin FACEBOOK_SYSTEM_USER_TOKEN: no hay respaldo para páginas sin token');
        } else {
            $me = Http::withToken($sys)->timeout(20)->get("{$base}/me", ['fields' => 'id,name']);
            if ($me->ok()) {
                $sysOk = true;
                $this->ok('Token de Usuario de Sistema válido: ' . data_get($me->json(), 'name'));

                $perms = Http::withToken($sys)->timeout(20)->get("{$base}/me/permissions");
                $granted = collect((array) data_get($perms->json(), 'data', []))
                    ->where('status', 'granted')->pluck('permission')->all();
                $missing = array_values(array_diff(self::REQUIRED_SCOPES, $granted));
                empty($missing)
                    ? $this->ok('Usuario de Sistema con los 9 permisos requeridos')
                    : $this->fail('Al Usuario de Sistema le faltan permisos: ' . implode(', ', $missing), 'Regenerar el token de editus-bot en Business Manager marcando los 9 permisos y actualizar FACEBOOK_SYSTEM_USER_TOKEN');
            } else {
                $this->fail('Token de Usuario de Sistema INVÁLIDO: ' . $this->graphMsg($me->body()), 'Regenerar el token de editus-bot en Business Manager (Usuarios del sistema → Generar identificador) y actualizar el .env');
            }
        }

        // ---- Páginas a verificar
        $q = MetaPage::query()->orderBy('name');
        if ($filter = $this->option('page')) {
            $q->where('name', 'like', "%{$filter}%");
        } else {
            $q->whereNotNull('medio_slug');
        }
        $pages = $q->get(['id', 'name', 'page_id', 'medio_slug', 'instagram_business_account_id']);

        if ($pages->isEmpty()) {
            $this->warn('No hay páginas para verificar (usa --page=Nombre o vincula medios)');
            return;
        }

        $this->line('  <fg=gray>Verificando ' . $pages->count() . ' página(s)…</>');
        foreach ($pages as $page) {
            $stored = DB::table('meta_page_user')
                ->where('meta_page_id', $page->id)->where('is_active', 1)
                ->whereNotNull('page_access_token')->where('page_access_token', '!=', '')
                ->orderByDesc('updated_at')->value('page_access_token');

            $storedOk = false;
            $storedMsg = 'sin token guardado';
            if ($stored) {
                $r = Http::withToken($stored)->timeout(20)->get("{$base}/me", ['fields' => 'id,name']);
                $storedOk = $r->ok();
                $storedMsg = $storedOk ? 'token guardado válido' : 'token guardado INVÁLIDO (' . $this->graphMsg($r->body()) . ')';
            }

            $sysPageOk = false;
            $sysPageMsg = 'sin Usuario de Sistema';
            if ($sysOk) {
                $r = Http::withToken($sys)->timeout(20)->get("{$base}/{$page->page_id}", ['fields' => 'name,access_token']);
                $sysPageOk = $r->ok() && data_get($r->json(), 'access_token');
                $sysPageMsg = $sysPageOk ? 'respaldo del Usuario de Sistema disponible' : 'Usuario de Sistema sin acceso (' . $this->graphMsg($r->body()) . ')';
            }

            $label = "{$page->name}" . ($page->medio_slug ? " [{$page->medio_slug}]" : '') . ($page->instagram_business_account_id ? ' 📸' : '');
            if ($storedOk) {
                $this->ok("{$label}: publicará con {$storedMsg}" . ($sysPageOk ? ' (+ respaldo OK)' : ''));
            } elseif ($sysPageOk) {
                $this->warn("{$label}: {$storedMsg}; publicará vía respaldo del Usuario de Sistema (se guardará al primer uso)");
            } else {
                $this->fail("{$label}: NO publicará — {$storedMsg}; {$sysPageMsg}", "Página «{$page->name}»: que su administrador en Facebook conecte y sincronice en editus, o asignarla a editus-bot en Business Manager");
            }
        }
    }

    private function graphMsg(string $body): string
    {
        $j = json_decode($body, true);
        $m = data_get($j, 'error.message');
        $c = data_get($j, 'error.code');
        return $m ? mb_substr($m, 0, 110) . ($c ? " #{$c}" : '') : mb_substr($body, 0, 110);
    }
}
