<?php

namespace App\Http\Controllers;

use App\Models\CampaignParticipante;
use App\Models\Producto;
use App\Models\ProductoPublicacion;
use App\Services\ContratosService;
use App\Services\ProductosService;
use App\Services\ReportesMedioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** El medio reporta desde su portal los pantallazos de TikTok (con el alcance) y las capturas de sus banners. */
class ReportesMedioController extends Controller
{
    public function __construct(private ReportesMedioService $reportes)
    {
    }

    public function guardar(Request $request, CampaignParticipante $participante, string $clase)
    {
        abort_unless((int) $participante->user_id === (int) $request->user()->id, 403);
        abort_unless(isset(ReportesMedioService::CLASES[$clase]), 404);
        if (app(ContratosService::class)->requiereContrato($participante)) {
            return redirect()->route('contratos.formulario', $participante->campaign_id)->with('aviso', __('Primero firma el contrato.'));
        }
        abort_unless(app(ContratosService::class)->tieneCanal($participante, ReportesMedioService::CANAL[$clase]), 403,
            $clase === 'tiktok' ? __('Tu medio no tiene TikTok entre sus canales.') : __('Tu medio no tiene página web entre sus canales.'));
        $d = $request->validate([
            'subcampana_id' => ['nullable', 'integer'],
            'tipo_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha'],
            'url' => [$clase === 'banner' ? 'required' : 'nullable', 'url', 'max:1000'],
            'alcance' => [$clase === 'tiktok' ? 'required' : 'nullable', 'integer', 'min:0'],
            'visualizaciones' => ['nullable', 'integer', 'min:0'],
            'interacciones' => ['nullable', 'integer', 'min:0'],
            'descripcion' => ['nullable', 'string', 'max:120'],
            'captura' => ['required', 'image', 'max:8192'],
        ], [
            'tipo_id.required' => __('Elige el tipo de publicación.'),
            'alcance.required' => __('Escribe el alcance que muestra el pantallazo.'),
            'url.required' => __('Escribe la dirección de la página donde está el banner.'),
            'url.url' => __('La dirección debe ser completa (https://…).'),
            'captura.required' => $clase === 'tiktok' ? __('Sube el pantallazo de TikTok con el alcance.') : __('Sube la captura del banner publicado.'),
        ]);
        $this->reportes->guardar($participante->load('campaign'), $clase, $d, $request->file('captura'));
        return redirect()->to(route('certificados.ver', $participante) . '#' . $clase)
            ->with('success', $clase === 'tiktok' ? __('Pantallazo de TikTok guardado: ya cuenta en el plan de medios.') : __('Banner guardado: ya cuenta en el plan de medios.'));
    }

    public function borrar(Request $request, Producto $producto)
    {
        $p = $producto->participante_id ? CampaignParticipante::find($producto->participante_id) : null;
        abort_unless($p && (int) $p->user_id === (int) $request->user()->id, 403);
        $this->reportes->borrar($producto);
        return back()->with('success', __('Reporte borrado.'));
    }

    /** Captura de un reporte (el medio que lo hizo y el administrador). */
    public function captura(Request $request, ProductoPublicacion $publicacion)
    {
        $prod = $publicacion->producto;
        $p = $prod?->participante_id ? CampaignParticipante::find($prod->participante_id) : null;
        $u = $request->user();
        abort_unless($u && (($p && (int) $p->user_id === (int) $u->id) || $u->tienePermiso('campanas')), 403);
        abort_unless($publicacion->evidencia_path && Storage::disk(ProductosService::DISCO)->exists($publicacion->evidencia_path), 404);
        return Storage::disk(ProductosService::DISCO)->response($publicacion->evidencia_path);
    }
}
