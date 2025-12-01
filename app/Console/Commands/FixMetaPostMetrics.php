<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MetaPost;
use Carbon\Carbon;

class FixMetaPostMetrics extends Command
{
    protected $signature = 'metaposts:fix-metrics {--dry-run}';
    protected $description = 'Rellenar/disimular métricas de MetaPost a partir de 2025-11-18 17:23:11 sólo para status=success';

    public function handle()
    {
        $fromDate = Carbon::parse('2025-11-18 17:23:11');

        $this->info("Corrigiendo métricas desde {$fromDate} (sólo status=success)");

        MetaPost::where('status', 'success')
            ->where('published_at', '>=', $fromDate)
            ->orderBy('id')
            ->chunkById(100, function ($posts) {
                foreach ($posts as $post) {
                    $original = [
                        'alcance' => $post->alcance,
                        'visualizaciones' => $post->visualizaciones,
                        'interacciones' => $post->interacciones,
                    ];

                    $changed = $this->applyMetricsRules($post);

                    if (!$changed) {
                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("DRY-RUN #{$post->id} {$post->fb_post_id}:");
                        $this->line('  FROM: ' . json_encode($original));
                        $this->line('  TO  : ' . json_encode([
                            'alcance' => $post->alcance,
                            'visualizaciones' => $post->visualizaciones,
                            'interacciones' => $post->interacciones,
                        ]));
                    } else {
                        $post->last_insights_at = now();
                        $post->save();

                        $this->info("Actualizado #{$post->id} {$post->fb_post_id}");
                    }
                }
            });

        $this->info('Listo parc, script terminado 🤙');
        return 0;
    }

    /**
     * Aplica reglas para rellenar/disimular métricas.
     * Devuelve true si cambió algo.
     */
    protected function applyMetricsRules(MetaPost $post): bool
    {
        $changed = false;

        $alcance = (int) ($post->alcance ?? 0);
        $views = (int) ($post->visualizaciones ?? 0);
        $interacciones = (int) ($post->interacciones ?? 0);

        // --- CASO 1: todo está en 0 → inventamos todo en base a pocas interacciones ---
        if ($alcance <= 0 && $views <= 0 && $interacciones <= 0) {
            $interacciones = rand(1, 5); // poquito para no levantar sospechas

            // 1 interacción ≈ 270 alcance (metemos variación)
            $alcance = $interacciones * rand(220, 320);

            // 1 interacción ≈ 39 views + aseguramos 10–20% del alcance en views
            $views = max(
                (int) round($interacciones * rand(30, 50)),
                (int) round($alcance * rand(10, 20) / 100)
            );

            $changed = true;
        }
        // --- CASO 2: tiene interacciones pero 0 alcance/views ---
        elseif ($interacciones > 0) {

            if ($alcance <= 0) {
                $alcance = $interacciones * rand(220, 320);
                $changed = true;
            }

            if ($views <= 0) {
                $views = max(
                    (int) round($interacciones * rand(30, 50)),
                    (int) round($alcance * rand(10, 20) / 100)
                );
                $changed = true;
            }
        }
        // --- CASO 3: no tiene interacciones pero sí alcance/views ---
        else {
            // Si hay alcance y/o views pero interacciones en 0 => calculamos unas poquitas
            if ($alcance > 0 && $views > 0) {
                $interacciones = max(1, (int) round($alcance / rand(240, 320)));
                $changed = true;
            } elseif ($alcance > 0) {
                $interacciones = max(1, (int) round($alcance / rand(240, 320)));
                $views = max(
                    1,
                    (int) round($alcance * rand(10, 20) / 100)
                );
                $changed = true;
            } elseif ($views > 0) {
                $interacciones = max(1, (int) round($views / rand(30, 50)));
                $alcance = max(
                    (int) round($views * rand(6, 9)),          // 1 view ~ 7 personas alcanzadas
                    (int) round($interacciones * rand(220, 320))
                );
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }

        // Asignamos de vuelta al modelo
        $post->alcance = $alcance;
        $post->visualizaciones = $views;
        $post->interacciones = $interacciones;

        return true;
    }
}
