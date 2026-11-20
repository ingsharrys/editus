<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\MetricasCampanaService;
use Illuminate\Console\Command;

/** php artisan campanas:metricas [--campana=ID] [--todas]: alcance, visualizaciones e interacciones de lo publicado en las campañas. */
class MetricasCampanas extends Command
{
    protected $signature = 'campanas:metricas {--campana= : Solo esta campaña (id)} {--todas : También las actualizadas hace poco} {--limite=400} {--ver : Muestra lo que respondió Meta por cada publicación}';

    protected $description = 'Actualiza las métricas (alcance, visualizaciones, interacciones) de las publicaciones de las campañas con plan de medios';

    public function handle(MetricasCampanaService $svc): int
    {
        $c = $this->option('campana') ? Campaign::find((int) $this->option('campana')) : null;
        if ($this->option('campana') && !$c) { $this->error('No existe esa campaña.'); return self::FAILURE; }
        $r = $svc->actualizar($c, (int) $this->option('limite'), (bool) $this->option('todas'));
        $this->info("Revisadas: {$r['revisadas']} · actualizadas: {$r['actualizadas']} · con error: {$r['errores']}");
        if ($r['error']) $this->warn('Primer error: ' . $r['error']);
        if ($this->option('ver') && $r['detalle']) {
            $paginas = \App\Models\MetaPage::whereIn('id', array_column($r['detalle'], 'pagina'))->pluck('name', 'id');
            $this->table(['Post', 'Página', 'Id en Facebook', 'Tipo', 'Alcance', 'Visualiz.', 'Interacc.', 'Error'], array_map(fn($d) => [
                $d['post'], $paginas[$d['pagina']] ?? '—', $d['id'], $d['tipo'], $d['alcance'] ?? '—', $d['visualizaciones'] ?? '—', $d['interacciones'] ?? '—',
                \Illuminate\Support\Str::limit((string) $d['error'], 90),
            ], $r['detalle']));
        }
        return self::SUCCESS;
    }
}
