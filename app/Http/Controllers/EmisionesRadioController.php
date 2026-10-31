<?php

namespace App\Http\Controllers;

use App\Models\CampaignParticipante;
use App\Models\EmisionRadio;
use App\Services\EmisionesRadioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** La emisora inscrita reporta y firma sus cuñas emitidas (desde su certificado). */
class EmisionesRadioController extends Controller
{
    public function __construct(private EmisionesRadioService $emisiones) {}

    public function guardar(Request $request, CampaignParticipante $participante)
    {
        abort_unless(EmisionesRadioService::disponible(), 503, __('Falta ejecutar las migraciones (php artisan migrate).'));
        $this->autorizar($request, $participante, true);
        $d = $request->validate([
            'subcampana_id' => ['required', 'integer'],
            'tipo_id' => ['required', 'integer'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:100000'],
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'programa' => ['nullable', 'string', 'max:190'],
            'horario' => ['nullable', 'string', 'max:120'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'soporte' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,mp3,wav,m4a,ogg,xlsx,xls,doc,docx', 'max:30720'],
            'firma' => ['nullable', 'string', 'max:2000000'],
            'acepto' => ['accepted'],
        ], [
            'acepto.accepted' => __('Confirma que la información es cierta para firmar el reporte.'),
            'soporte.mimes' => __('El soporte debe ser PDF, imagen, audio, Excel o Word.'),
        ]);
        try {
            $this->emisiones->guardar($participante->loadMissing('campaign'), $d, $d['firma'] ?? null, $request->file('soporte'), $request->ip());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['emision' => $e->getMessage()]);
        }
        return redirect(route('certificados.ver', $participante) . '#radio')->with('success', __('Reporte de emisiones firmado y guardado.'));
    }

    public function borrar(Request $request, EmisionRadio $emision)
    {
        $this->autorizar($request, $emision->participante, false);
        $p = $emision->participante;
        $this->emisiones->borrar($emision);
        return redirect(route('certificados.ver', $p) . '#radio')->with('success', __('Reporte de emisiones borrado.'));
    }

    public function soporte(Request $request, EmisionRadio $emision)
    {
        $this->autorizar($request, $emision->participante, false);
        abort_unless($emision->soporte_path && Storage::disk(EmisionesRadioService::DISCO)->exists($emision->soporte_path), 404);
        return Storage::disk(EmisionesRadioService::DISCO)->download($emision->soporte_path, 'soporte-emisiones-' . $emision->id . '.' . pathinfo($emision->soporte_path, PATHINFO_EXTENSION));
    }

    /** La emisora dueña del reporte o quien administra campañas. Reportar solo lo hace la emisora. */
    private function autorizar(Request $request, ?CampaignParticipante $p, bool $soloLaEmisora): void
    {
        $u = $request->user();
        abort_unless($p && $u, 404);
        $esDuena = (int) $p->user_id === (int) $u->id;
        abort_unless($esDuena || (!$soloLaEmisora && $u->tienePermiso('campanas')), 403);
    }
}
