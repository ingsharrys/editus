<?php

namespace App\Http\Controllers;

use App\Models\PagoSuscripcion;
use App\Services\CobrosService;
use App\Services\PlanService;
use App\Services\WompiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** «Mi suscripción»: planes, pago con Wompi, páginas del plan y licencia del plugin SharryStreem. */
class SuscripcionController extends Controller
{
    public function __construct(private PlanService $planes)
    {
    }

    public function index(WompiService $wompi): View
    {
        $user = Auth::user();
        $s = $this->planes->suscripcion($user);
        return view('suscripcion.index', [
            'user' => $user,
            'sinLimites' => $this->planes->sinLimites($user),
            'suscripcion' => $s,
            'planes' => $this->planes->planes(),
            'conectadas' => $this->planes->paginasConectadas($user),
            'permitidas' => $this->planes->paginasPermitidas($user)?->pluck('id')->map(fn($v) => (int) $v)->all() ?? [],
            'pagos' => PagoSuscripcion::where('user_id', $user->id)->latest()->limit(12)->get(),
            'wompiListo' => $wompi->configurado(),
            'licenciaNueva' => session('licencia_nueva'),
        ]);
    }

    /** Crea el pago pendiente y lleva al Web Checkout de Wompi. */
    public function pagar(Request $request, WompiService $wompi, CobrosService $cobros): RedirectResponse
    {
        $datos = $request->validate([
            'plan' => ['required', Rule::in(array_keys($this->planes->planes()))],
            'periodo' => ['required', Rule::in(PlanService::PERIODOS)],
        ]);
        if (!$wompi->configurado()) return back()->with('error', 'Los pagos en línea aún no están configurados. Escríbenos para activar tu plan.');
        return redirect()->away($cobros->iniciarPago(Auth::user(), $datos['plan'], $datos['periodo'], route('suscripcion.resultado')));
    }

    /** Retorno del checkout (?id=transacción): se verifica con la API de Wompi, nunca con lo que dice la URL. */
    public function resultado(Request $request, WompiService $wompi, CobrosService $cobros): RedirectResponse
    {
        $id = (string) $request->query('id', '');
        $tx = $id !== '' ? $wompi->transaccion($id) : null;
        if (!$tx) return redirect()->route('suscripcion.index')->with('error', 'No pudimos confirmar el pago con Wompi. Si se descontó el dinero, se activará en unos minutos.');
        $pago = $cobros->procesar($tx);
        if (!$pago || (int) $pago->user_id !== (int) Auth::id()) return redirect()->route('suscripcion.index')->with('error', 'El pago no corresponde a tu cuenta.');
        return match ($pago->estado) {
            'APPROVED' => redirect()->route('suscripcion.index')->with('success', '¡Pago aprobado! Tu plan está activo.'),
            'PENDING' => redirect()->route('suscripcion.index')->with('success', 'Tu pago está en proceso. Se activará apenas Wompi lo confirme.'),
            default => redirect()->route('suscripcion.index')->with('error', 'El pago no fue aprobado (' . $pago->estado . '). Puedes intentarlo de nuevo.'),
        };
    }

    public function paginas(Request $request): RedirectResponse
    {
        $datos = $request->validate(['paginas' => ['nullable', 'array'], 'paginas.*' => ['integer']]);
        $s = $this->planes->suscripcion(Auth::user());
        if (!$s) return back()->with('error', 'Primero activa un plan.');
        $max = (int) ($s->config()['paginas'] ?? 0);
        if (count($datos['paginas'] ?? []) > $max) return back()->with('error', "Tu plan permite {$max} página(s).");
        $this->planes->elegirPaginas(Auth::user(), $datos['paginas'] ?? []);
        return back()->with('success', 'Páginas del plan guardadas.');
    }

    /** Nueva llave para el plugin (la anterior deja de funcionar y se desvinculan los sitios). */
    public function regenerarLicencia(): RedirectResponse
    {
        $s = $this->planes->suscripcion(Auth::user());
        if (!$s || !$s->activa()) return back()->with('error', 'Necesitas un plan activo para tener licencia.');
        $this->planes->generarLicencia($s);
        return back()->with('success', 'Nueva llave creada. Cópiala ahora: por seguridad no se vuelve a mostrar completa.');
    }

    public function liberarSitio(Request $request): RedirectResponse
    {
        $sitio = (string) $request->validate(['sitio' => ['required', 'string', 'max:255']])['sitio'];
        $s = $this->planes->suscripcion(Auth::user());
        if ($s) $s->fill(['licencia_sitios' => array_values(array_filter((array) $s->licencia_sitios, fn($x) => ($x['url'] ?? '') !== $sitio))])->save();
        return back()->with('success', 'Sitio desvinculado de la licencia.');
    }
}
