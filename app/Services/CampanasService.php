<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Campana;
use App\Models\MetaPage;
use App\Models\Tema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campañas unificadas: la campaña (`campaigns`) es la única y se crea en el módulo
 * Campañas. Este servicio mantiene su PERFIL DE ANÁLISIS (`campanas`: temas, lecturas,
 * informes de la IA) sincronizado con ella y calcula sus páginas a partir de los
 * medios donde se publica (más las agregadas a mano).
 */
class CampanasService
{
    public const TIPOS = ['politica' => 'Política', 'medio' => 'Medio de comunicación', 'comercial' => 'Comercial', 'institucional' => 'Institucional'];
    private const COLORES = ['#2563eb', '#dc2626', '#16a34a', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#4b5563'];

    /** Medios configurados (slug => nombre). */
    public function medios(): array
    {
        return (array) config('services.editus.medios', []);
    }

    /**
     * Crea o actualiza una campaña con todos sus datos y sincroniza su perfil de análisis.
     * $datos: name, description, tipo, contexto, territorio, starts_on, ends_on, medios[], paginas_extra[], temas (texto, solo al crear)
     */
    public function guardar(Campaign $c, array $datos, ?int $userId = null): Campaign
    {
        $nuevo = !$c->exists;
        $medios = array_values(array_intersect(array_map('strval', (array) ($datos['medios'] ?? [])), array_keys($this->medios())));
        $c->fill([
            'name' => trim((string) $datos['name']),
            'description' => ($datos['description'] ?? null) ?: null,
            'tipo' => array_key_exists($datos['tipo'] ?? '', self::TIPOS) ? $datos['tipo'] : 'institucional',
            'contexto' => ($datos['contexto'] ?? null) ?: null,
            'territorio' => ($datos['territorio'] ?? null) ?: null,
            'starts_on' => ($datos['starts_on'] ?? null) ?: null,
            'ends_on' => ($datos['ends_on'] ?? null) ?: null,
            'medios' => $medios,
        ]);
        if ($nuevo) {
            $c->slug = Campaign::makeSlug($c->name);
            $c->is_system = false;
            $c->is_active = true;
            $c->created_by = $userId;
        }
        $c->save();

        $perfil = $this->sincronizarPerfil($c, array_map('intval', (array) ($datos['paginas_extra'] ?? [])));

        if ($nuevo && !empty($datos['temas'])) {
            $nombres = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\n,;]+/', (string) $datos['temas'])))));
            foreach ($nombres as $i => $nombre) {
                Tema::create(['campana_id' => $perfil->id, 'nombre' => mb_substr($nombre, 0, 80), 'orden' => $i, 'color' => self::COLORES[$i % count(self::COLORES)]]);
            }
        }
        return $c;
    }

    /**
     * Crea o actualiza el perfil de análisis de la campaña (nombre, contexto, fechas, estado)
     * y recalcula sus páginas: las de sus medios (origen = medio) + las manuales.
     * $paginasExtra: si se pasa, reemplaza las páginas agregadas a mano.
     */
    public function sincronizarPerfil(Campaign $c, ?array $paginasExtra = null): Campana
    {
        $perfil = Campana::firstOrNew(['campaign_id' => $c->id]);
        $perfil->fill([
            'nombre' => $c->name,
            'descripcion' => $c->contexto ?: $c->description,
            'territorio' => $c->territorio,
            'desde' => $c->starts_on,
            'hasta' => $c->ends_on,
            'activa' => (bool) $c->is_active,
        ]);
        $perfil->save();

        $conOrigen = Schema::hasColumn('campana_pagina', 'origen');
        $deMedios = $this->paginasDeMedios($c)->pluck('id')->all();

        DB::transaction(function () use ($perfil, $deMedios, $paginasExtra, $conOrigen) {
            if (!$conOrigen) {
                $perfil->paginas()->sync(array_values(array_unique(array_merge($deMedios, (array) $paginasExtra))));
                return;
            }
            $manuales = $paginasExtra !== null
                ? $paginasExtra
                : DB::table('campana_pagina')->where('campana_id', $perfil->id)->where('origen', 'manual')->pluck('meta_page_id')->all();
            $filas = [];
            foreach ($deMedios as $id) $filas[$id] = ['origen' => 'medio'];
            foreach ($manuales as $id) if (!isset($filas[$id])) $filas[$id] = ['origen' => 'manual'];
            $perfil->paginas()->sync($filas);
        });
        return $perfil;
    }

    /** Recalcula todas las campañas (cuando cambia el medio de una página o tras la migración). */
    public function sincronizarTodas(): int
    {
        if (!Schema::hasTable('campaigns') || !Schema::hasColumn('campaigns', 'medios') || !Schema::hasTable('campanas')) return 0;
        $n = 0;
        foreach (Campaign::all() as $c) {
            $this->sincronizarPerfil($c);
            $n++;
        }
        return $n;
    }

    /** Páginas de los medios de la campaña. La de sistema (Esnoticia) cubre todos los medios. */
    public function paginasDeMedios(Campaign $c): Collection
    {
        $medios = $c->medios;
        if ($c->is_system && ($medios === null || $medios === [])) {
            return MetaPage::whereNotNull('medio_slug')->where('medio_slug', '!=', '')->get();
        }
        $medios = array_values(array_filter((array) $medios));
        return $medios ? MetaPage::whereIn('medio_slug', $medios)->get() : collect();
    }

    /** Todas las páginas de la campaña (de sus medios + manuales), para preseleccionarlas al publicar. */
    public function paginasDe(Campaign $c): Collection
    {
        $perfil = $c->relationLoaded('perfil') ? $c->perfil : $c->perfil()->with('paginas')->first();
        $extra = $perfil ? $perfil->paginas : collect();
        return $this->paginasDeMedios($c)->merge($extra)->unique('id')->values();
    }

    /** [campaign_id => [meta_pages.id, ...]] de las campañas dadas. */
    public function mapaPaginas(Collection $campanas): array
    {
        $mapa = [];
        foreach ($campanas as $c) $mapa[$c->id] = $this->paginasDe($c)->pluck('id')->map(fn($v) => (int) $v)->values()->all();
        return $mapa;
    }

    /** Se asegura de que toda campaña tenga su perfil de análisis (p. ej. campañas creadas antes de la unificación). */
    public function asegurarPerfiles(): void
    {
        if (!Schema::hasTable('campanas') || !Schema::hasColumn('campanas', 'campaign_id')) return;
        $con = Campana::whereNotNull('campaign_id')->pluck('campaign_id')->flip();
        foreach (Campaign::all() as $c) if (!$con->has($c->id)) $this->sincronizarPerfil($c);
    }
}
