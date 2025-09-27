<?php

namespace App\Console\Commands;

use App\Models\MetaPost;
use App\Services\MetaInsightsService;
use Illuminate\Console\Command;

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

        $ok = 0; $empty = 0;
        foreach ($posts as $post) {
            try {
                $updated = $svc->updatePostMetrics($post);
                if ($updated) {
                    $ok++;
                    $this->line("✓ Post #{$post->id} → alc={$post->alcance} vis={$post->visualizaciones} int={$post->interacciones}");
                } else {
                    $empty++;
                    $this->line("· Sin cambios #{$post->id}");
                }
            } catch (\Throwable $e) {
                $this->error("ERROR #{$post->id}: ".$e->getMessage());
            }
        }

        $this->info("Listo. Actualizados: {$ok} | Sin cambios: {$empty}");
        return self::SUCCESS;
    }
}
