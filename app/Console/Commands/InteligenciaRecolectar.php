<?php

namespace App\Console\Commands;

use App\Models\Campana;
use App\Models\MetaPage;
use App\Services\Inteligencia\RecolectorAudienciaService;
use Illuminate\Console\Command;

/**
 * Recolecta el histórico diario y las publicaciones (con métricas) de TODAS las
 * páginas integradas con token activo, estén o no en una campaña: así la vista
 * general de Inteligencia tiene datos de toda la organización y no se pierde
 * historial (Meta solo entrega los días recientes). Las páginas de campañas
 * activas van primero. Entre página y página se hace una pausa para no agotar
 * el límite de llamadas de Meta.
 */
class InteligenciaRecolectar extends Command
{
    protected $signature = 'inteligencia:recolectar
        {--dias=7 : Días hacia atrás}
        {--pagina= : Solo esta meta_pages.id}
        {--campana= : Solo las páginas de esta campaña}
        {--solo-campanas : Solo las páginas que están en campañas activas (comportamiento anterior)}
        {--pausa=1 : Segundos de espera entre páginas}
        {--limite=0 : Máximo de páginas a procesar (0 = todas)}';

    protected $description = 'Recolecta el histórico diario y las publicaciones (con métricas) de todas las páginas integradas (o de una campaña / página).';

    public function handle(RecolectorAudienciaService $recolector): int
    {
        $dias = max(1, min(90, (int) $this->option('dias')));
        $pausa = max(0, (int) $this->option('pausa'));
        $limite = max(0, (int) $this->option('limite'));

        $enCampanas = Campana::where('activa', true)->with('paginas')->get()->flatMap(fn($c) => $c->paginas->pluck('id'))->unique()->values();

        if ($this->option('pagina')) {
            $paginas = MetaPage::whereKey((int) $this->option('pagina'))->get();
        } elseif ($this->option('campana')) {
            $paginas = Campana::findOrFail((int) $this->option('campana'))->paginas()->get();
        } elseif ($this->option('solo-campanas')) {
            $paginas = MetaPage::whereIn('id', $enCampanas)->get();
        } else {
            // Todas las páginas con un token activo (conectadas desde la web de editus o desde la app)
            $paginas = MetaPage::whereHas('vinculos', fn($q) => $q->where('is_active', 1)->whereNotNull('page_access_token'))->orderBy('name')->get();
        }
        // Primero las de campañas activas: son las que más importa tener al día
        $paginas = $paginas->sortBy(fn(MetaPage $p) => $enCampanas->contains($p->id) ? 0 : 1)->values();
        if ($limite > 0) $paginas = $paginas->take($limite);

        if ($paginas->isEmpty()) {
            $this->warn('No hay páginas con token activo para recolectar.');
            return self::SUCCESS;
        }
        $this->info(sprintf('Recolectando %d página(s), %d días hacia atrás…', $paginas->count(), $dias));
        $conError = 0;
        foreach ($paginas as $i => $p) {
            $r = $recolector->recolectar($p, $dias);
            if ($r['errores']) $conError++;
            $this->line(sprintf('%s%s: FB %s días, IG %s días, %d publicaciones, %d métricas%s', $enCampanas->contains($p->id) ? '★ ' : '', $p->name, $r['facebook'] ?? '-', $r['instagram'] ?? '-', $r['publicaciones'], $r['metricas'],
                $r['errores'] ? ' · errores: ' . implode(' | ', $r['errores']) : ''));
            if ($pausa > 0 && $i < $paginas->count() - 1) sleep($pausa);
        }
        $this->info(sprintf('Listo: %d página(s), %d con errores.', $paginas->count(), $conError));
        return self::SUCCESS;
    }
}
