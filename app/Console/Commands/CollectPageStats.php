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

        $tokenDeadPages = [];
        $otherErrors = 0;
        $ok = 0;

        foreach ($pages as $page) {
            try {
                $daily = $stats->collectDaily($page, $since->copy(), $until->copy());
                $dailyError = $stats->lastError;
                $tokenDead = $dailyError && str_contains($dailyError, '"code":190');

                // Con token muerto no tiene sentido gastar más llamadas
                $audience = $tokenDead ? 0 : $stats->collectAudience($page);
                $audienceError = $tokenDead ? null : $stats->lastError;

                $igDaily = 0;
                $ig = 0;
                if (!$tokenDead && $page->instagram_business_account_id) {
                    $igDaily = $stats->collectInstagramDaily($page, $since->copy(), $until->copy());
                    $ig = $stats->collectInstagramDemographics($page);
                }

                $this->line("  [{$page->name}] FB días: {$daily}, audiencia: {$audience} | IG días: {$igDaily}, demografía: {$ig}");

                if ($tokenDead) {
                    $tokenDeadPages[] = $page->name;
                    $this->warn('    ↳ token de página vencido (código 190): requiere sincronizar');
                } elseif ($daily === 0 && $dailyError) {
                    $this->warn('    ↳ métricas: ' . $this->shortGraphError($dailyError));
                    $otherErrors++;
                } else {
                    $ok++;
                    if ($audience === 0 && $audienceError) {
                        $this->warn('    ↳ audiencia: ' . $this->shortGraphError($audienceError));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[stats:collect] page.error', ['meta_page_id' => $page->id, 'err' => $e->getMessage()]);
                $this->warn("  [{$page->name}] error: {$e->getMessage()}");
                $otherErrors++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Resumen: %d página(s) con datos, %d con token vencido, %d con otros errores.',
            $ok,
            count($tokenDeadPages),
            $otherErrors
        ));

        if ($tokenDeadPages !== []) {
            $this->comment('Para renovar los tokens vencidos: entra a la app > Meta/Páginas y pulsa «Sincronizar» con la cuenta de Facebook que administra esas páginas. Las páginas de otros usuarios requieren que ESE usuario conecte su Facebook y sincronice.');
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
                    $updated = $post->network === 'instagram'
                        ? $stats->collectInstagramPostMetrics($post)
                        : $stats->collectPostReactions($post);
                    if ($updated) {
                        $ok++;
                    }
                } catch (\Throwable $e) {
                    Log::debug('[stats:collect] reactions.error', ['meta_post_id' => $post->id, 'err' => $e->getMessage()]);
                }
                usleep(200_000); // 200ms entre posts para no golpear rate limits
            }
            $this->info("Métricas de posts actualizadas en {$ok}/{$posts->count()}.");
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
