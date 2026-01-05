<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MetaPost;
use Carbon\Carbon;

class FixMetaPostMetrics extends Command
{
    protected $signature = 'metaposts:fix-metrics {--dry-run}';

    protected $description = 'Arregla métricas desde 2025-11-29 20:59:00 (status=success). Alcance/Views nunca 0. Interacciones 1..5 para páginas especiales.';

    public function handle()
    {
        $fromDate = Carbon::parse('2025-11-29 20:59:00');

        $this->info("Corrigiendo métricas desde {$fromDate} (status=success, fb_post_id NOT NULL)");

        MetaPost::where('status', 'success')
            ->whereNotNull('fb_post_id')
            ->whereRaw('COALESCE(published_at, created_at) >= ?', [$fromDate->toDateTimeString()])
            ->orderBy('id')
            ->chunkById(200, function ($posts) {
                foreach ($posts as $post) {
                    $original = [
                        'alcance'         => $post->alcance,
                        'visualizaciones' => $post->visualizaciones,
                        'interacciones'   => $post->interacciones,
                    ];

                    $changed = $this->applyMetricsRules($post);

                    if (!$changed) {
                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("DRY-RUN #{$post->id} {$post->fb_post_id} (page={$post->meta_page_id}):");
                        $this->line('  FROM: ' . json_encode($original));
                        $this->line('  TO  : ' . json_encode([
                            'alcance'         => $post->alcance,
                            'visualizaciones' => $post->visualizaciones,
                            'interacciones'   => $post->interacciones,
                        ]));
                    } else {
                        $post->last_insights_at = now();
                        $post->save();
                        $this->info("Actualizado #{$post->id} {$post->fb_post_id} (page={$post->meta_page_id})");
                    }
                }
            });

        $this->info('Listo parc 🤙');
        return 0;
    }

    protected function applyMetricsRules(MetaPost $post): bool
    {
        $changed = false;

        $specialPages = [31, 16, 34, 35, 58];
        $isSpecial = in_array((int) $post->meta_page_id, $specialPages, true);

        $alc = (int) ($post->alcance ?? 0);
        $vis = (int) ($post->visualizaciones ?? 0);
        $int = (int) ($post->interacciones ?? 0);

        // ---------------------------
        // 1) INTERACCIONES (reglas)
        // ---------------------------
        if ($isSpecial) {
            // especiales: SI O SI 1..5
            if ($int < 1) { $int = rand(1, 5); $changed = true; }
            if ($int > 5) { $int = 5; $changed = true; }
        } else {
            // no especiales: preferimos 0, máximo 1
            if ($int < 0) { $int = 0; $changed = true; }
            if ($int > 1) { $int = 1; $changed = true; }
            // si está en 0, se queda 0 (no lo subimos)
        }

        // -----------------------------------------
        // 2) ALCANCE / VIEWS (NUNCA pueden ser 0)
        //    y BAJAR valores absurdos
        // -----------------------------------------

        if ($int === 0) {
            // interacciones 0 => métricas bajitas, y cap fuerte
            // objetivo: alcance 60–220 (máx 300), views 8–25% del alcance
            $maxReach = 300;

            if ($alc <= 0 || $alc > $maxReach) {
                $alc = rand(60, 220);
                $changed = true;
            }

            $minViews = max(1, (int) round($alc * 0.08));
            $maxViews = max($minViews + 1, (int) round($alc * 0.25));

            if ($vis <= 0 || $vis < $minViews || $vis > $maxViews) {
                $vis = rand($minViews, $maxViews);
                $changed = true;
            }
        } else {
            // interacciones > 0 => métricas basadas en interacciones pero no exageradas
            // ejemplo que quieres: int=2 -> alcance ~ 360-520, views ~ 80-160 aprox
            $minReach = $int * 180;
            $maxReach = $int * 260;

            if ($alc <= 0 || $alc < $minReach || $alc > $maxReach) {
                $alc = rand($minReach, $maxReach);
                $changed = true;
            }

            $minViews = max(1, (int) round($alc * 0.12));
            $maxViews = max($minViews + 1, (int) round($alc * 0.30));

            // también mete un piso leve por interacciones
            $minViews = max($minViews, $int * 25);
            $maxViews = max($maxViews, $int * 45);

            if ($vis <= 0 || $vis < $minViews || $vis > $maxViews) {
                $vis = rand($minViews, $maxViews);
                $changed = true;
            }
        }

        // Seguridad final: views no puede ser > alcance
        if ($vis > $alc) {
            $alc = $vis + rand(5, 25);
            $changed = true;
        }

        if (!$changed) return false;

        $post->alcance = $alc;
        $post->visualizaciones = $vis;
        $post->interacciones = $int;

        return true;
    }
}
