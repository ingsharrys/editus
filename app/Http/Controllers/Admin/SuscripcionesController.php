<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PagoSuscripcion;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administración de suscripciones: clientes, pagos, activación manual y equipo exento. */
class SuscripcionesController extends Controller
{
    public function __construct(private PlanService $planes)
    {
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $usuarios = User::with(['suscripcion', 'role'])
            ->when($q !== '', fn($w) => $w->where(fn($o) => $o->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $ahora = now();
        return view('admin.suscripciones.index', [
            'usuarios' => $usuarios, 'q' => $q, 'planes' => $this->planes->planes(),
            'resumen' => [
                'activas' => Suscripcion::where('vence_en', '>', $ahora)->count(),
                'basico' => Suscripcion::where('vence_en', '>', $ahora)->where('plan', 'basico')->count(),
                'full' => Suscripcion::where('vence_en', '>', $ahora)->where('plan', 'full')->count(),
                'ingresos_mes' => (int) PagoSuscripcion::where('estado', 'APPROVED')->where('created_at', '>=', $ahora->copy()->startOfMonth())->sum('monto_centavos') / 100,
                'vencen_7' => Suscripcion::whereBetween('vence_en', [$ahora, $ahora->copy()->addDays(7)])->count(),
            ],
            'pagos' => PagoSuscripcion::with('user')->latest()->limit(15)->get(),
        ]);
    }

    /** Activa o extiende un plan a mano (pago por transferencia, cortesía…): queda como pago manual. */
    public function activar(Request $request, User $user): RedirectResponse
    {
        $datos = $request->validate([
            'plan' => ['required', Rule::in(array_keys($this->planes->planes()))],
            'periodo' => ['required', Rule::in(PlanService::PERIODOS)],
            'cobrado' => ['nullable', 'boolean'],
        ]);
        $pago = PagoSuscripcion::create([
            'user_id' => $user->id, 'plan' => $datos['plan'], 'periodo' => $datos['periodo'], 'origen' => 'manual', 'estado' => 'APPROVED',
            'referencia' => 'MAN-' . $user->id . '-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(4)),
            'monto_centavos' => !empty($datos['cobrado']) ? $this->planes->precio($datos['plan'], $datos['periodo']) * 100 : 0,
            'moneda' => (string) config('planes.moneda', 'COP'), 'metodo' => 'manual',
            'respuesta' => ['por' => auth()->user()->name],
        ]);
        $s = $this->planes->aplicarPago($pago);
        return back()->with('success', "Plan {$s->nombrePlan()} activo para {$user->name} hasta el {$s->vence_en->format('d/m/Y')}.");
    }

    public function cancelar(User $user): RedirectResponse
    {
        $s = $this->planes->suscripcion($user);
        if ($s) $s->fill(['vence_en' => now(), 'nota' => trim(($s->nota ? $s->nota . "\n" : '') . 'Cancelada por ' . auth()->user()->name . ' el ' . now()->format('d/m/Y H:i'))])->save();
        return back()->with('success', "Suscripción de {$user->name} cancelada; la licencia del plugin dejó de funcionar.");
    }

    public function exento(User $user): RedirectResponse
    {
        $user->forceFill(['exento_planes' => !$user->exento_planes])->save();
        return back()->with('success', $user->exento_planes ? "{$user->name} queda sin límites (equipo interno)." : "{$user->name} ahora usa los planes.");
    }
}
