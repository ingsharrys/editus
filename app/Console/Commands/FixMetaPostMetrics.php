<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MetaPost;
use Carbon\Carbon;

class FixMetaPostMetrics extends Command
{
    protected $signature = 'metaposts:fix-metrics {--dry-run}';

    protected $description = 'Rellenar/ajustar métricas MetaPost desde 2025-11-29 20:59, status=success. Interacciones 0 permitido salvo páginas especiales.';

    public function handle()
    {
        $fromDate = Carbon::parse('2025-11-29 20:59:00');

        $this->info("Corrigiendo métricas desde {$fromDate} (status=success, fb_post_id NOT NULL)");

        MetaPost::where('status', 'success')
            ->whereNotNull('fb_post_id')
            ->where('published_at', '>=', $fromDate)
            ->orderBy('id')
            ->chunkById(100, function ($posts) {
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
                        $this->line("DRY-RUN #{$post->id} {$post->fb_post_id}:");
                        $this->line('  FROM: ' . json_encode($original));
                        $this->line('  TO  : ' . json_encode([
                            'alcance'         => $post->alcance,
                            'visualizaciones' => $post->visualizaciones,
                            'interacciones'   => $post->interacciones,
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
     * Reglas:
     * - alcance y visualizaciones NUNCA pueden quedar en 0.
     * - páginas especiales (31,16,34,35,58): interacciones SI o SI 1..5.
     * - no especiales: interacciones puede ser 0 (preferido), y máximo 1 si ya venía >0.
     */
    protected function applyMetricsRules(MetaPost $post): bool
    {
        $changed = false;

        $specialPages = [31, 16, 34, 35, 58];
        $isSpecial = in_array((int) $post->meta_page_id, $specialPages, true);

        $alcance       = (int) ($post->alcance ?? 0);
        $views         = (int) ($post->visualizaciones ?? 0);
        $interacciones = (int) ($post->interacciones ?? 0);

        // 0) Normalizar interacciones según tipo de página
        if ($isSpecial) {
            // Especiales: 1..5 sí o sí
            if ($interacciones < 1) {
                $interacciones = rand(1, 5);
                $changed = true;
            } elseif ($interacciones > 5) {
                $interacciones = 5;
                $changed = true;
            }
        } else {
            // No especiales: puede ser 0, máximo 1
            if ($interacciones < 0) {
                $interacciones = 0;
                $changed = true;
            } elseif ($interacciones > 1) {
                $interacciones = 1;
                $changed = true;
            }
            // OJO: si es 0, lo dejamos 0 (no lo subimos a 1).
        }

        // 1) Si interacciones > 0: asegurar alcance/views (tipo realista como tu script)
        if ($interacciones > 0) {
            if ($alcance <= 0) {
                // 1 interacción ~ 220-320 alcance
                $alcance = $interacciones * rand(220, 320);
                $changed = true;
            }

            if ($views <= 0) {
                // views: base por interacción o 10-20% del alcance
                $views = max(
                    (int) round($interacciones * rand(30, 50)),
                    (int) round($alcance * rand(10, 20) / 100)
                );
                $changed = true;
            }
        }

        // 2) Si interacciones == 0: interacciones se queda 0 (solo no-especiales),
        //    pero alcance/views JAMÁS pueden quedar en 0.
        if ($interacciones === 0) {
            // Caso: todo en 0 (o vacío)
            if ($alcance <= 0 && $views <= 0) {
                // Para 0 interacciones queremos métricas bajitas (no exagerar)
                $alcance = rand(50, 160);
                $views   = max(1, (int) round($alcance * rand(10, 25) / 100)); // 10-25%
                $changed = true;
            }
            // Caso: alcance > 0, views en 0
            elseif ($alcance > 0 && $views <= 0) {
                $views   = max(1, (int) round($alcance * rand(10, 25) / 100));
                $changed = true;
            }
            // Caso: views > 0, alcance en 0
            elseif ($alcance <= 0 && $views > 0) {
                $alcance = max(1, (int) round($views * rand(6, 10))); // ~6-10 alcance por view
                $changed = true;
            }
            // Caso: ambos > 0, ajustar si views muy fuera del rango (10-25% del alcance)
            else {
                $minViews = (int) round($alcance * 0.10);
                $maxViews = (int) round($alcance * 0.25);

                if ($views < $minViews || $views > $maxViews) {
                    $views = rand($minViews, max($minViews + 1, $maxViews));
                    $changed = true;
                }
            }
        }

        // 3) Seguridad final: alcance/views jamás en 0 (por si acaso)
        if ($alcance <= 0) {
            $alcance = $isSpecial ? rand(220, 320) : rand(50, 160);
            $changed = true;
        }
        if ($views <= 0) {
            $views = max(1, (int) round($alcance * rand(10, 25) / 100));
            $changed = true;
        }

        // 4) Evitar incoherencia: views > alcance
        if ($views > $alcance) {
            $alcance = $views + rand(5, 20);
            $changed = true;
        }

        if (!$changed) {
            return false;
        }

        $post->alcance = $alcance;
        $post->visualizaciones = $views;
        $post->interacciones = $interacciones;

        return true;
    }
}
