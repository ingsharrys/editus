<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MetaPost;
use Carbon\Carbon;

class FixMetaPostMetrics extends Command
{
    protected $signature = 'metaposts:fix-metrics {--dry-run}';

    // Actualizamos la descripción también
    protected $description = 'Rellenar/disimular métricas de MetaPost desde 2025-10-31 sólo para status=success (saltando updated_at=2025-12-01)';

    public function handle()
    {
        // Ahora desde el 31 de octubre de 2025
        $fromDate = Carbon::parse('2025-10-31 00:00:00');

        $this->info("Corrigiendo métricas desde {$fromDate} (sólo status=success, excepto updated_at=2025-12-01)");

        MetaPost::where('status', 'success')
            ->where('published_at', '>=', $fromDate)
            // NO tocar publicaciones que fueron modificadas el 1 de diciembre de 2025
            ->whereDate('updated_at', '!=', '2025-12-01')
            ->orderBy('id')
            ->chunkById(100, function ($posts) {
                foreach ($posts as $post) {
                    $original = [
                        'alcance'        => $post->alcance,
                        'visualizaciones'=> $post->visualizaciones,
                        'interacciones'  => $post->interacciones,
                    ];

                    $changed = $this->applyMetricsRules($post);

                    if (!$changed) {
                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("DRY-RUN #{$post->id} {$post->fb_post_id}:");
                        $this->line('  FROM: ' . json_encode($original));
                        $this->line('  TO  : ' . json_encode([
                            'alcance'        => $post->alcance,
                            'visualizaciones'=> $post->visualizaciones,
                            'interacciones'  => $post->interacciones,
                        ]));
                    } else {
                        // Marcamos que se actualizó insights
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

        $alcance       = (int) ($post->alcance ?? 0);
        $views         = (int) ($post->visualizaciones ?? 0);
        $interacciones = (int) ($post->interacciones ?? 0);

        // --------- REGLAS ---------
        // NUEVA CONDICIÓN: si interacciones está en 0, lo dejamos en 0 SIEMPRE,
        // pero sí podemos rellenar/ajustar alcance y views.

        // --- CASO 1: todo está en 0 ---
        // alcance = 0, views = 0, interacciones = 0
        if ($alcance <= 0 && $views <= 0 && $interacciones <= 0) {

            // Usamos un "fake" de pocas interacciones sólo para calcular
            $fakeInteracciones = rand(1, 5);

            // 1 interacción ≈ 270 alcance (con variación)
            $alcance = $fakeInteracciones * rand(220, 320);

            // 1 interacción ≈ 39 views + 10–20% del alcance en views
            $views = max(
                (int) round($fakeInteracciones * rand(30, 50)),
                (int) round($alcance * rand(10, 20) / 100)
            );

            // OJO: interacciones se queda en 0
            $interacciones = 0;

            $changed = true;
        }
        // --- CASO 2: tiene interacciones (>0) ---
        elseif ($interacciones > 0) {

            // Si no tiene alcance, lo generamos en base a interacciones
            if ($alcance <= 0) {
                $alcance = $interacciones * rand(220, 320);
                $changed = true;
            }

            // Si no tiene views, las generamos
            if ($views <= 0) {
                $views = max(
                    (int) round($interacciones * rand(30, 50)),
                    (int) round($alcance * rand(10, 20) / 100)
                );
                $changed = true;
            }
        }
        // --- CASO 3: interacciones == 0, pero sí hay alcance/views ---
        else { // $interacciones == 0
            // Ejemplo que comentaste: 108 112 0

            if ($alcance > 0 && $views > 0) {
                // Ajustamos views para que queden entre 10–20% del alcance (si está muy fuera)
                $minViews = (int) round($alcance * 0.10);
                $maxViews = (int) round($alcance * 0.20);

                if ($views < $minViews || $views > $maxViews) {
                    $views   = rand($minViews, max($minViews + 1, $maxViews));
                    $changed = true;
                }
                // interacciones permanece 0
            } elseif ($alcance > 0 && $views <= 0) {
                // Tenemos alcance pero views en 0: generamos views creíbles
                $views   = max(
                    1,
                    (int) round($alcance * rand(10, 20) / 100)
                );
                $changed = true;
                // interacciones permanece 0
            } elseif ($alcance <= 0 && $views > 0) {
                // Tenemos views pero alcance 0: generamos alcance
                $alcance = max(
                    1,
                    (int) round($views * rand(6, 9))  // ~7 personas alcanzadas por view
                );
                $changed = true;
                // interacciones permanece 0
            }
        }

        if (!$changed) {
            return false;
        }

        // Asignamos de vuelta al modelo
        $post->alcance        = $alcance;
        $post->visualizaciones= $views;
        $post->interacciones  = $interacciones;

        return true;
    }
}
