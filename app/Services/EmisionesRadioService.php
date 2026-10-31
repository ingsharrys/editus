<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\EmisionRadio;
use App\Models\Subcampana;
use App\Models\TipoPublicacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Radio: las cuñas y espacios no se publican desde editus. Cada emisora se inscribe con el enlace de la
 * campaña, firma su certificado y luego reporta (y firma) lo que emitió: subcampaña, tipo, cuántas, fechas,
 * programa / franja y el soporte (certificación de la emisora, audio o pauta). Eso cuenta como emitido
 * en el avance del plan de medios y en los informes.
 */
class EmisionesRadioService
{
    public const DISCO = 'local';

    public static function disponible(): bool
    {
        return Schema::hasTable('campaign_emisiones');
    }

    /** Subcampañas con tipos de radio y la cantidad planeada de cada uno: [['sub' => Subcampana, 'tipos' => [[TipoPublicacion, planeadas]]]]. */
    public function opciones(Campaign $c): Collection
    {
        if (!self::disponible()) return collect();
        return Subcampana::where('campaign_id', $c->id)->where('is_active', true)->orderBy('orden')->orderBy('id')
            ->with(['tipos' => fn($q) => $q->where('campaign_tipos.canal', 'radio')] + (Subcampana::anidadas() ? ['parent'] : []))->get()
            ->filter(fn($s) => $s->tipos->isNotEmpty())
            ->map(fn($s) => ['sub' => $s, 'tipos' => $s->tipos->map(fn($t) => [$t, $t->pivot->cantidad])->values()])->values();
    }

    public function tieneRadio(Campaign $c): bool
    {
        return self::disponible() && TipoPublicacion::where('campaign_id', $c->id)->where('canal', 'radio')->exists();
    }

    /** Registra y firma un reporte de emisiones de la emisora. */
    public function guardar(CampaignParticipante $p, array $d, ?string $firmaDataUrl, ?UploadedFile $soporte, ?string $ip): EmisionRadio
    {
        $c = $p->campaign;
        $sub = Subcampana::where('campaign_id', $c->id)->findOrFail((int) $d['subcampana_id']);
        $tipo = $sub->tipos()->where('campaign_tipos.canal', 'radio')->where('campaign_tipos.id', (int) $d['tipo_id'])->first();
        if (!$tipo) throw new \InvalidArgumentException(__('Ese tipo de cuña no está en la subcampaña elegida.'));

        // Firma: la que dibuja ahora o, si no, la de su inscripción
        [$firma, $hash] = [null, null];
        if ($firmaDataUrl && preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $firmaDataUrl, $m)) {
            $png = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
            if ($png !== false && strlen($png) > 200 && strlen($png) < 1_500_000 && (@getimagesizefromstring($png)[2] ?? null) === IMAGETYPE_PNG) {
                $firma = 'emisiones/' . $c->id . '/firma-' . $p->id . '-' . Str::random(10) . '.png';
                Storage::disk(self::DISCO)->put($firma, $png);
                $hash = hash('sha256', $png);
            }
        }
        if (!$firma && $p->firmado()) [$firma, $hash] = [$p->firma_path, $p->firma_hash];
        if (!$firma) throw new \InvalidArgumentException(__('Dibuja tu firma en el recuadro.'));

        $rutaSoporte = $soporte
            ? $soporte->storeAs('emisiones/' . $c->id, 'soporte-' . $p->id . '-' . Str::random(10) . '.' . strtolower($soporte->getClientOriginalExtension() ?: 'pdf'), self::DISCO)
            : null;

        return EmisionRadio::create([
            'campaign_id' => $c->id, 'participante_id' => $p->id, 'subcampana_id' => $sub->id, 'tipo_id' => $tipo->id,
            'cantidad' => (int) $d['cantidad'], 'fecha_desde' => $d['fecha_desde'], 'fecha_hasta' => ($d['fecha_hasta'] ?? null) ?: null,
            'programa' => trim((string) ($d['programa'] ?? '')) ?: null, 'horario' => trim((string) ($d['horario'] ?? '')) ?: null,
            'observaciones' => trim((string) ($d['observaciones'] ?? '')) ?: null, 'soporte_path' => $rutaSoporte,
            'firma_path' => $firma, 'firma_hash' => $hash, 'firmado_at' => now(), 'ip' => $ip,
        ]);
    }

    public function borrar(EmisionRadio $e): void
    {
        if ($e->soporte_path) Storage::disk(self::DISCO)->delete($e->soporte_path);
        $p = $e->participante;
        if ($e->firma_path && (!$p || $e->firma_path !== $p->firma_path)) Storage::disk(self::DISCO)->delete($e->firma_path);
        $e->delete();
    }

    /** Emitidas por subcampaña y tipo: [sub_id => [tipo_id => n]]. */
    public function totales(Campaign $c): array
    {
        if (!self::disponible()) return [];
        $out = [];
        foreach (EmisionRadio::where('campaign_id', $c->id)->selectRaw('subcampana_id, tipo_id, SUM(cantidad) as n')->groupBy('subcampana_id', 'tipo_id')->get() as $r) {
            $out[(int) $r->subcampana_id][(int) $r->tipo_id] = (int) $r->n;
        }
        return $out;
    }

    /** Emitido por emisora (para la subcampaña o toda la campaña): [['emisora', 'tipo', 'cantidad', 'reportes', 'desde', 'hasta']]. */
    public function porEmisora(Campaign $c, ?int $subId = null): Collection
    {
        if (!self::disponible()) return collect();
        return EmisionRadio::with(['participante', 'tipo'])->where('campaign_id', $c->id)
            ->when($subId !== null, fn($q) => $q->whereIn('subcampana_id', $subId ? (Subcampana::find($subId)?->idsConHijas() ?? [$subId]) : [0]))->get()
            ->groupBy(fn($e) => $e->participante_id . '|' . $e->tipo_id)
            ->map(fn($g) => [
                'emisora' => $g->first()->participante?->medio_nombre ?: '—',
                'participante' => $g->first()->participante,
                'tipo' => $g->first()->tipo?->nombre ?: '—',
                'cantidad' => (int) $g->sum('cantidad'),
                'reportes' => $g->count(),
                'desde' => $g->min('fecha_desde'),
                'hasta' => $g->max(fn($x) => $x->fecha_hasta ?? $x->fecha_desde),
            ])->sortBy('emisora')->values();
    }

    /** Filas para el certificado de la emisora (agrupadas por programa, subcampaña y tipo). */
    public function paraCertificado(CampaignParticipante $p): Collection
    {
        if (!self::disponible()) return collect();
        return EmisionRadio::where('participante_id', $p->id)->with(['subcampana', 'tipo'])->orderBy('fecha_desde')->get()
            ->groupBy(fn($e) => $e->subcampana_id . '|' . $e->tipo_id)
            ->map(function ($g) {
                $e = $g->first();
                $desde = $g->min('fecha_desde'); $hasta = $g->max(fn($x) => $x->fecha_hasta ?? $x->fecha_desde);
                return [
                    'programa' => $e->subcampana?->programa ?: '—',
                    'subcampana' => $e->subcampana?->nombre ?: '—',
                    'tipo' => $e->tipo?->nombre ?: '—',
                    'cantidad' => (int) $g->sum('cantidad'),
                    'fechas' => $desde?->format('d/m/Y') . ($hasta && $hasta->ne($desde) ? ' – ' . $hasta->format('d/m/Y') : ''),
                ];
            })->values();
    }
}
