<?php

namespace App\Console\Commands;

use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Services\PageStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CollectPageStats extends Command
{
    protected $signature = 'stats:collect
        {--days=7 : Días hacia atrás para métricas diarias}
        {--page= : ID interno de una página específica (meta_pages.id)}
        {--skip-reactions : No recolectar reacciones por tipo de posts}';

    protected $description = 'Recolecta estadísticas de páginas: métricas diarias, audiencia por país/ciudad y reacciones por tipo';

    public function handle(PageStatsService $stats): int
    {
        $days = max(1, min((int) $this->option('days'), 90));
        $since = now()->subDays($days)->startOfDay();
        $until = now()->startOfDay();

        $pages = MetaPage::query()
            ->when($this->option('page'), fn($q, $id) => $q->where('id', $id))
            ->whereHas('users', fn($q) => $q->where('meta_page_user.is_active', 1))
            ->get();

        if ($pages->isEmpty()) {
            $this->warn('No hay páginas activas con token.');
            return self::SUCCESS;
        }

        $this->info("Recolectando estadísticas de {$pages->count()} página(s), últimos {$days} días...");

        $firstError = null;

        foreach ($pages as $page) {
            try {
                $daily = $stats->collectDaily($page, $since->copy(), $until->copy());
                $audience = $stats->collectAudience($page);
                $this->line("  [{$page->name}] días: {$daily}, audiencia: {$audience}");

                if ($daily === 0 && $stats->lastError) {
                    $reason = $this->shortGraphError($stats->lastError);
                    $this->warn("    ↳ motivo: {$reason}");
                    $firstError ??= $reason;
                }
            } catch (\Throwable $e) {
                Log::warning('[stats:collect] page.error', ['meta_page_id' => $page->id, 'err' => $e->getMessage()]);
                $this->warn("  [{$page->name}] error: {$e->getMessage()}");
            }
        }

        if ($firstError) {
            $this->newLine();
            $this->error('Hubo páginas sin datos. Motivo más común: ' . $firstError);
            if (str_contains($firstError, 'read_insights') || str_contains($firstError, '(#10)') || str_contains($firstError, '(#200)')) {
                $this->comment('El token no tiene el permiso read_insights: reconecta Facebook aceptando todos los permisos, o revisa que la configuración de "Login for Business" en Meta incluya read_insights.');
            }
        }

        if (!$this->option('skip-reactions')) {
            // Reacciones por tipo de posts recientes exitosos
            $posts = MetaPost::where('status', 'success')
                ->whereNotNull('fb_post_id')
                ->where('published_at', '>=', now()->subDays($days))
                ->orderByDesc('published_at')
                ->limit(200)
                ->get();

            $ok = 0;
            foreach ($posts as $post) {
                try {
                    if ($stats->collectPostReactions($post)) {
                        $ok++;
                    }
                } catch (\Throwable $e) {
                    Log::debug('[stats:collect] reactions.error', ['meta_post_id' => $post->id, 'err' => $e->getMessage()]);
                }
                usleep(200_000); // 200ms entre posts para no golpear rate limits
            }
            $this->info("Reacciones actualizadas en {$ok}/{$posts->count()} posts.");
        }

        return self::SUCCESS;
    }

    /** Extrae el mensaje de error legible de una respuesta de la Graph API. */
    private function shortGraphError(string $body): string
    {
        $json = json_decode($body, true);
        $msg = data_get($json, 'error.message');
        $code = data_get($json, 'error.code');

        return $msg
            ? trim($msg . ($code ? " (código {$code})" : ''))
            : mb_substr($body, 0, 200);
    }
}
