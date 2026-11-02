<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * En vivos dentro de una campaña con plan de medios (p. ej. «En vivo» en la Campaña de Salud 2026).
 *
 * Al abrir el estudio se elige la campaña, la subcampaña y el tipo. Cuando la transmisión sale al aire,
 * cada página de Facebook queda como una publicación de la campaña (el video del en vivo), todas en un
 * mismo lote, así que suma una pieza al informe, cuenta en los cupos y recoge sus métricas como
 * cualquier publicación.
 */
class EnVivoCampanaService
{
    public function __construct(private PlanMediosService $plan, private CampanasService $campanas)
    {
    }

    public static function disponible(): bool
    {
        return PlanMediosService::disponible() && Schema::hasColumn('transmisiones_en_vivo', 'campaign_id');
    }

    /** Tipos de en vivo de la campaña (nombres singulares, p. ej. «En vivo»). */
    public function tiposEnVivo(Campaign $c): array
    {
        if (PlanMediosService::conTipos()) {
            $tipos = TipoPublicacion::where('campaign_id', $c->id)->where('is_active', true)->orderBy('orden')->orderBy('id')->get();
            if ($tipos->isNotEmpty()) return array_values(array_unique($tipos->filter(fn($t) => $t->esEnVivo())->map(fn($t) => $t->etiquetaSingular())->all()));
        }
        return array_values(array_filter($this->plan->tipos($c), fn($t) => str_contains(mb_strtolower((string) $t), 'vivo')));
    }

    /** Tipos de en vivo que se pueden hacer en la subcampaña (o en la campaña, si no tiene subcampañas). */
    public function tiposDe(Campaign $c, ?Subcampana $s): array
    {
        $vivos = collect($this->tiposEnVivo($c))->keyBy(fn($t) => PlanMediosService::clave($t));
        return array_values(array_filter($this->plan->tiposDe($c, $s), fn($t) => $vivos->has(PlanMediosService::clave($t))));
    }

    /**
     * Campañas donde se pueden hacer en vivos, para el formulario del estudio y la app:
     * [{id, nombre, paginas: [page_id], subs: [{id, nombre, tipos}], tipos, cupos: {page_id: {tipo: [usados, cupo]}}}]
     */
    public function opciones(): array
    {
        if (!self::disponible()) return [];
        $out = [];
        foreach (Campaign::selectable()->where('plan_medios', true)->with('perfil.paginas')->get() as $c) {
            $vivos = $this->tiposEnVivo($c);
            if (!$vivos) continue;
            $todas = $this->plan->subcampanasParaPublicar($c);
            $subs = $todas->map(fn($s) => ['id' => $s->id, 'nombre' => $s->nombreCompleto(), 'tipos' => $this->tiposDe($c, $s)])
                ->filter(fn($s) => $s['tipos'])->values()->all();
            if ($todas->isNotEmpty() && !$subs) continue; // ninguna subcampaña tiene en vivos
            $paginas = $this->campanas->paginasDe($c);
            $porId = $paginas->keyBy('id');
            $cupos = [];
            $claves = array_map(fn($t) => PlanMediosService::clave($t), $vivos);
            foreach ($this->plan->cuposParaPublicar($c) as $metaId => $fila) {
                $pg = $porId->get($metaId);
                if (!$pg) continue;
                foreach ($fila as $k => $v) if (in_array($k, $claves, true)) $cupos[(string) $pg->page_id][$k] = $v;
            }
            $out[] = [
                'id' => $c->id, 'nombre' => (string) $c->name,
                'paginas' => $paginas->pluck('page_id')->map(fn($v) => (string) $v)->values()->all(),
                'subs' => $subs, 'tipos' => $vivos, 'cupos' => (object) $cupos,
            ];
        }
        return $out;
    }

    /**
     * Revisa la campaña elegida al abrir (o programar) el estudio.
     * Devuelve [campos para la transmisión, null] o [null, mensaje de error]. Sin campaña: [[], null].
     */
    public function validar(array $d, Collection $paginas): array
    {
        if (empty($d['campaign_id'])) return [[], null];
        if (!self::disponible()) return [[], null];
        $c = Campaign::find((int) $d['campaign_id']);
        if (!$c || !$c->plan_medios || !$c->is_active) return [null, __('La campaña elegida no está activa o no tiene plan de medios.')];
        $subs = $this->plan->subcampanasParaPublicar($c);
        $sub = !empty($d['subcampana_id']) ? $subs->firstWhere('id', (int) $d['subcampana_id']) : null;
        if ($subs->isNotEmpty() && !$sub) return [null, __('Elige la subcampaña del en vivo.')];
        $tipos = $this->tiposDe($c, $sub);
        if (!$tipos) return [null, __('«:s» no tiene en vivos en el plan de medios.', ['s' => $sub ? $sub->nombreCompleto() : $c->name])];
        $tipo = null;
        foreach ($tipos as $t) if (PlanMediosService::clave($t) === PlanMediosService::clave($d['tipo_pieza'] ?? '')) $tipo = $t;
        if (!$tipo && count($tipos) === 1) $tipo = $tipos[0];
        if (!$tipo) return [null, __('Elige el tipo de en vivo.')];
        $excedidos = $this->plan->excedidos($c, $paginas->pluck('id')->all(), $tipo);
        if ($excedidos) {
            $lista = collect($excedidos)->map(fn($e) => __(':pagina (:usados de :cupo)', $e))->implode(', ');
            return [null, __('Estas páginas ya completaron su cupo de «:tipo»: :lista. Quítalas o aumenta el cupo.', ['tipo' => $tipo, 'lista' => $lista])];
        }
        return [['campaign_id' => $c->id, 'subcampana_id' => $sub?->id, 'tipo_pieza' => $tipo], null];
    }

    /** «Campaña · Subcampaña · Tipo» para mostrar en el estudio. */
    public static function etiqueta(TransmisionEnVivo $t): string
    {
        $c = Campaign::find($t->campaign_id);
        $s = $t->subcampana_id ? Subcampana::find($t->subcampana_id) : null;
        return implode(' · ', array_filter([$c?->name, $s?->nombreCompleto(), $t->tipo_pieza]));
    }

    /**
     * Registra el en vivo en la campaña: una publicación por página de Facebook (el video del en vivo),
     * todas en el mismo lote = una pieza del informe. Se llama al salir al aire y al terminar
     * (cuando Facebook ya dio el id definitivo del video). Devuelve cuántas publicaciones quedaron.
     */
    public function registrar(TransmisionEnVivo $t): int
    {
        if (!self::disponible() || !$t->campaign_id || !$t->iniciada_en) return 0;
        try {
            $c = Campaign::find($t->campaign_id);
            if (!$c) return 0;
            $sub = $t->subcampana_id ? Subcampana::find($t->subcampana_id) : null;
            $campos = $this->plan->camposDe($sub, $t->tipo_pieza);
            $userId = $t->user_id ?: ($c->created_by ?: User::whereHas('role', fn($q) => $q->where('slug', 'admin'))->orderBy('id')->value('id'));
            if (!$userId) return 0;
            $mensaje = trim($t->titulo . ($t->descripcion ? "\n\n" . $t->descripcion : ''));
            $n = 0;
            foreach ($t->destinosLista() as $d) {
                if (($d['red'] ?? 'facebook') !== 'facebook' || ($d['estado'] ?? '') !== 'ok' || empty($d['meta_page_id'])) continue;
                if (!MetaPage::whereKey((int) $d['meta_page_id'])->exists()) continue;
                $post = MetaPost::firstOrNew(['batch_uuid' => self::lote($t), 'meta_page_id' => (int) $d['meta_page_id']]);
                $post->fill(['user_id' => $post->user_id ?: $userId, 'campaign_id' => $c->id] + $campos + [
                    'type' => 'video', 'network' => 'facebook', 'message' => $mensaje,
                    'fb_post_id' => ($d['fb_video_id'] ?? null) ?: ($d['fb_live_id'] ?? null),
                    'fb_permalink_url' => ($d['permalink'] ?? null) ?: $post->fb_permalink_url,
                    'status' => 'success', 'published_at' => $post->published_at ?: $t->iniciada_en,
                ]);
                $post->save();
                $n++;
            }
            if ($n && ProductosService::disponible()) app(ProductosService::class)->asegurarPiezas($c);
            return $n;
        } catch (\Throwable $e) {
            // El en vivo no se detiene por esto; el registro se puede repetir al terminar
            Log::warning('[EN VIVO] no se pudo registrar en la campaña', ['transmision' => $t->id, 'err' => $e->getMessage()]);
            return 0;
        }
    }

    /** Lote (batch_uuid) de las publicaciones de un en vivo: es una columna uuid, así que se deriva del id. */
    public static function lote(TransmisionEnVivo $t): string
    {
        $h = md5('envivo-' . $t->id . '-' . $t->room);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
