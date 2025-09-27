<?php

namespace App\Console\Commands;

use App\Models\MetaPost;
use App\Services\MetaInsightsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
class CollectSimpleMetaInsights extends Command
{
    protected $signature = 'meta:collect-metrics-simple 
                            {--limit=300 : Máximo de posts a procesar}
                            {--only-missing : Solo posts que tengan algún campo nulo}';

    protected $description = 'Actualiza alcance / visualizaciones / interacciones directamente en meta_posts.';

    public function handle(MetaInsightsService $svc): int
    {
        $limit = (int) $this->option('limit');
        $onlyMissing = (bool) $this->option('only-missing');

        $q = MetaPost::query()
            ->where('status', 'success')
            ->whereNotNull('fb_post_id')
            ->orderByRaw('COALESCE(published_at, created_at) ASC');

        if ($onlyMissing) {
            $q->where(function ($w) {
                $w->whereNull('alcance')
                    ->orWhereNull('visualizaciones')
                    ->orWhereNull('interacciones');
            });
        }

        $posts = $q->limit($limit)->get();
        if ($posts->isEmpty()) {
            $this->info('No hay posts para actualizar.');
            return self::SUCCESS;
        }

        $ok = 0;
        $empty = 0;
        foreach ($posts as $post) {
            try {
                Log::info('[metrics] processing', [
                    'post_id' => $post->id,
                    'meta_page_id' => $post->meta_page_id,
                    'fb_post_id' => $post->fb_post_id,
                ]);

                $updated = $svc->updatePostMetrics($post);

                if ($updated) {
                    $ok++;
                    $msg = "✓ Post #{$post->id} → alc={$post->alcance} vis={$post->visualizaciones} int={$post->interacciones}";
                    $this->line($msg);
                    Log::info('[metrics] command.updated', [
                        'post_id' => $post->id,
                        'alcance' => $post->alcance,
                        'visualizaciones' => $post->visualizaciones,
                        'interacciones' => $post->interacciones,
                    ]);
                } else {
                    $empty++;
                    $msg = "· Sin cambios #{$post->id}";
                    $this->line($msg);
                    Log::info('[metrics] command.no-change', ['post_id' => $post->id]);
                }

            } catch (\Throwable $e) {
                $this->error("ERROR #{$post->id}: " . $e->getMessage());
                Log::error('[metrics] command.error', [
                    'post_id' => $post->id,
                    'err' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Listo. Actualizados: {$ok} | Sin cambios: {$empty}");
        return self::SUCCESS;
    }
}
