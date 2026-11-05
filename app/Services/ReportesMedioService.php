<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipante;
use App\Models\Producto;
use App\Models\ProductoPublicacion;
use App\Models\TipoPublicacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lo que el propio medio reporta desde su portal: pantallazos de TikTok con el alcance y capturas de los banners
 * publicados en su web. Cada reporte queda como una pieza del plan de medios a nombre del medio (cuenta en el
 * avance de la subcampaña y sale en el informe con su captura).
 */
class ReportesMedioService
{
    public const CLASES = ['tiktok' => 'TikTok', 'banner' => 'Banner'];

    public static function disponible(): bool
    {
        return ProductosService::disponible() && Schema::hasColumn('campaign_productos', 'participante_id');
    }

    /** ¿El tipo de publicación es de esa clase? (TikTok por su canal; banner por su clase «web» o su nombre) */
    public function esDe(TipoPublicacion $t, string $clase): bool
    {
        $n = mb_strtolower($t->nombre . ' ' . $t->etiquetaSingular());
        return $clase === 'tiktok'
            ? ($t->canal === 'tiktok' || str_contains($n, 'tiktok') || str_contains($n, 'tik tok'))
            : ($t->clase === 'web' || str_contains($n, 'banner'));
    }

    /** Subcampañas (o la campaña, si no tiene) con sus tipos de esa clase: [['sub' => ?Subcampana, 'tipos' => Collection]] */
    public function opciones(Campaign $c, string $clase): Collection
    {
        if (!self::disponible()) return collect();
        $tipos = TipoPublicacion::where('campaign_id', $c->id)->where('is_active', true)->orderBy('orden')->orderBy('id')->get()
            ->filter(fn($t) => $this->esDe($t, $clase))->values();
        if ($tipos->isEmpty()) return collect();
        $subs = app(PlanMediosService::class)->subcampanasParaPublicar($c);
        if ($subs->isEmpty()) return collect([['sub' => null, 'tipos' => $tipos]]);
        return $subs->map(function ($s) use ($tipos) {
            $suyos = $s->tipos()->pluck('campaign_tipos.id');
            return ['sub' => $s, 'tipos' => ($suyos->isEmpty() ? $tipos : $tipos->whereIn('id', $suyos->all()))->values()];
        })->filter(fn($o) => $o['tipos']->isNotEmpty())->values();
    }

    /** Reportes que ha hecho el medio en la campaña. */
    public function delMedio(CampaignParticipante $p): Collection
    {
        if (!self::disponible()) return collect();
        return Producto::with(['tipo', 'subcampana', 'publicaciones'])->where('campaign_id', $p->campaign_id)->where('participante_id', $p->id)
            ->latest('fecha_publicacion')->latest('id')->get()
            ->map(fn($x) => $x->setAttribute('clase_reporte', $x->tipo && $this->esDe($x->tipo, 'tiktok') ? 'tiktok' : 'banner'));
    }

    /**
     * Guarda un reporte. $d: subcampana_id, tipo_id, fecha, fecha_fin?, url?, alcance?, visualizaciones?, interacciones?, descripcion?
     */
    public function guardar(CampaignParticipante $p, string $clase, array $d, UploadedFile $captura): Producto
    {
        $p->loadMissing('campaign');
        $c = $p->campaign;
        $o = $this->opciones($c, $clase)->first(fn($o) => (int) ($o['sub']?->id ?? 0) === (int) ($d['subcampana_id'] ?? 0));
        if (!$o) throw ValidationException::withMessages(['subcampana_id' => __('Elige la subcampaña.')]);
        $tipo = $o['tipos']->firstWhere('id', (int) ($d['tipo_id'] ?? 0));
        if (!$tipo) throw ValidationException::withMessages(['tipo_id' => __('Elige el tipo de publicación.')]);

        $medio = trim((string) $p->medio_nombre) ?: __('Medio');
        $titulo = trim((string) ($d['descripcion'] ?? ''));
        $prod = app(ProductosService::class)->guardar($c, [
            'tipo_id' => $tipo->id, 'subcampana_id' => $o['sub']?->id,
            'nombre' => self::CLASES[$clase] . ' · ' . $medio . ($titulo !== '' ? ' · ' . $titulo : ''),
            'estado' => 'Publicado / Entregado', 'fecha_publicacion' => $d['fecha'] ?? null, 'fecha_fin' => $d['fecha_fin'] ?? null,
            'responsable' => $medio,
        ]);
        $prod->forceFill(['participante_id' => $p->id])->save();
        $ruta = $captura->storeAs('campanas/' . $c->id . '/evidencias', $clase . '-' . $prod->id . '-' . Str::random(10) . '.' . ($captura->extension() ?: 'jpg'), ProductosService::DISCO);
        $num = fn($k) => isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null ? (int) $d[$k] : null;
        ProductoPublicacion::create([
            'producto_id' => $prod->id, 'medio' => $clase === 'tiktok' ? $medio . ' · TikTok' : $medio,
            'url' => ($d['url'] ?? null) ?: null, 'fecha' => $d['fecha'] ?? null,
            'alcance' => $num('alcance'), 'visualizaciones' => $num('visualizaciones'), 'interacciones' => $num('interacciones'),
            'evidencia_path' => $ruta, 'orden' => 1,
        ]);
        return $prod;
    }

    public function borrar(Producto $prod): void
    {
        foreach ($prod->publicaciones as $pub) {
            if ($pub->evidencia_path) Storage::disk(ProductosService::DISCO)->delete($pub->evidencia_path);
            $pub->delete();
        }
        $prod->delete();
    }
}
