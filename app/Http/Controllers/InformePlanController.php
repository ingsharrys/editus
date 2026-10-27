<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\InformeMetricasService;
use App\Services\ProductosService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Informes del plan de medios: informe de métricas (Word / PDF / vista previa) y Excel del control de productos. */
class InformePlanController extends Controller
{
    public function __construct(private InformeMetricasService $inf)
    {
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user()?->tienePermiso('campanas'), 403);
            return $next($request);
        });
    }

    public function index(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        return view('campaigns.plan.informes', [
            'c' => $campaign, 'programas' => $campaign->programas()->get(), 'subs' => $campaign->subcampanas()->get(),
            'tipos' => $campaign->tiposPublicacion()->get(), 'subElegida' => $request->integer('subcampana') ?: null,
            'membrete' => $this->inf->membrete($campaign),
        ]);
    }

    /** Membrete del informe: imagen de encabezado, imagen o texto de pie y título por defecto. */
    public function membrete(Request $request, Campaign $campaign)
    {
        $this->exigir($campaign);
        $d = $request->validate([
            'fondo' => ['nullable', 'image', 'max:12288'], 'encabezado' => ['nullable', 'image', 'max:8192'], 'pie' => ['nullable', 'image', 'max:8192'],
            'pie_texto' => ['nullable', 'string', 'max:500'], 'titulo' => ['nullable', 'string', 'max:255'],
            'quitar_fondo' => ['nullable', 'boolean'], 'quitar_encabezado' => ['nullable', 'boolean'], 'quitar_pie' => ['nullable', 'boolean'],
        ]);
        $m = (array) ($campaign->informe ?? []);
        $disco = Storage::disk(ProductosService::DISCO);
        foreach (['fondo', 'encabezado', 'pie'] as $k) {
            if (!empty($d['quitar_' . $k]) && !empty($m[$k . '_path'])) {
                $disco->delete($m[$k . '_path']);
                $m[$k . '_path'] = null;
            }
            if ($request->hasFile($k)) {
                if (!empty($m[$k . '_path'])) $disco->delete($m[$k . '_path']);
                $m[$k . '_path'] = $request->file($k)->storeAs('campanas/' . $campaign->id . '/informe', $k . '-' . Str::random(8) . '.' . ($request->file($k)->extension() ?: 'png'), ProductosService::DISCO);
            }
        }
        $m['pie_texto'] = trim((string) ($d['pie_texto'] ?? ''));
        $m['titulo'] = trim((string) ($d['titulo'] ?? ''));
        $campaign->forceFill(['informe' => $m])->save();
        return redirect()->route('campaigns.informes', $campaign)->with('success', __('Membrete guardado.'));
    }

    public function generar(Request $request, Campaign $campaign, string $formato)
    {
        $this->exigir($campaign);
        abort_unless(in_array($formato, ['word', 'pdf', 'vista'], true), 404);
        $f = $request->validate([
            'programa_id' => ['nullable', 'integer'], 'subcampana_ids' => ['nullable', 'array'], 'subcampana_ids.*' => ['integer'],
            'tipo_ids' => ['nullable', 'array'], 'tipo_ids.*' => ['integer'], 'agrupar' => ['nullable', 'in:programa,subcampana'],
            'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date'], 'corte' => ['nullable', 'date'],
            'titulo' => ['nullable', 'string', 'max:255'], 'resumen' => ['nullable', 'boolean'],
        ]);
        $f['resumen'] = $request->has('resumen') ? $request->boolean('resumen') : true;
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');
        $d = $this->inf->datos($campaign, $f);
        if ($formato === 'vista') return view('campaigns.plan.informe-vista', $d + ['c' => $campaign, 'inf' => $this->inf, 'filtros' => $f]);
        if ($formato === 'pdf') return $this->inf->pdf($d)->download($this->inf->nombreArchivo($d, 'pdf'));
        if (!class_exists(\PhpOffice\PhpWord\PhpWord::class)) abort(503, __('Falta instalar las librerías (composer install).'));
        $tmp = $this->inf->word($d);
        return response()->download($tmp, $this->inf->nombreArchivo($d, 'docx'))->deleteFileAfterSend(true);
    }

    public function excel(Campaign $campaign)
    {
        $this->exigir($campaign);
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) abort(503, __('Falta instalar las librerías (composer install).'));
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');
        $tmp = $this->inf->excelControl($campaign);
        return response()->download($tmp, 'control-de-productos-' . Str::slug($campaign->name) . '-' . now()->format('Ymd') . '.xlsx')->deleteFileAfterSend(true);
    }

    private function exigir(Campaign $c): void
    {
        abort_if($c->is_system, 403);
        abort_unless(ProductosService::disponible(), 503, __('Falta ejecutar las migraciones (php artisan migrate).'));
        abort_unless($c->plan_medios, 404, __('Esta campaña no es un plan de medios. Actívalo en Editar campaña.'));
    }
}
