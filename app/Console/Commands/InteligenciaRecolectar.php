<?php

namespace App\Console\Commands;

use App\Models\Campana;
use App\Models\MetaPage;
use App\Services\Inteligencia\RecolectorAudienciaService;
use Illuminate\Console\Command;

class InteligenciaRecolectar extends Command
{
    protected $signature = 'inteligencia:recolectar {--dias=7 : Días hacia atrás} {--pagina= : Solo esta meta_pages.id} {--campana= : Solo las páginas de esta campaña}';
    protected $description = 'Recolecta el histórico diario y las publicaciones (con métricas) de las páginas de las campañas activas.';

    public function handle(RecolectorAudienciaService $recolector): int
    {
        $dias = max(1, min(90, (int) $this->option('dias')));
        if ($this->option('pagina')) {
            $paginas = MetaPage::whereKey((int) $this->option('pagina'))->get();
        } elseif ($this->option('campana')) {
            $paginas = Campana::findOrFail((int) $this->option('campana'))->paginas()->get();
        } else {
            $ids = Campana::where('activa', true)->with('paginas')->get()->flatMap(fn($c) => $c->paginas->pluck('id'))->unique();
            $paginas = MetaPage::whereIn('id', $ids)->get();
        }
        if ($paginas->isEmpty()) {
            $this->warn('No hay páginas en campañas activas.');
            return self::SUCCESS;
        }
        foreach ($paginas as $p) {
            $r = $recolector->recolectar($p, $dias);
            $this->line(sprintf('%s: FB %s días, IG %s días, %d publicaciones, %d métricas%s', $p->name, $r['facebook'] ?? '-', $r['instagram'] ?? '-', $r['publicaciones'], $r['metricas'],
                $r['errores'] ? ' · errores: ' . implode(' | ', $r['errores']) : ''));
        }
        return self::SUCCESS;
    }
}
