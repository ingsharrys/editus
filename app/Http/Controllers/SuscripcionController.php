<?php

namespace App\Http\Controllers;

use App\Models\PagoSuscripcion;
use App\Services\CobrosService;
use App\Services\PlanService;
use App\Services\RegionService;
use App\Services\WompiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «Mi suscripción»: planes, pago (Wompi en COP desde Colombia, PayPal o Square en USD desde el exterior),
 * páginas del plan y licencia del plugin SharryStreem.
 */
class SuscripcionController extends Controller
{
    public function __construct(private PlanService $planes, private RegionService $region)
    {
    }

    public function index(Request $request, CobrosService $cobros): View
    {
        $user = Auth::user();
        $s = $this->planes->suscripcion($user);
        $moneda = $this->region->moneda($request, $user);
        return view('suscripcion.index', [
            'user' => $user,
            'sinLimites' => $this->planes->sinLimites($user),
            'suscripcion' => $s,
            'planes' => $this->planes->planes(),
            'conectadas' => $this->planes->paginasConectadas($user),
            'permitidas' => $this->planes->paginasPermitidas($user)?->pluck('id')->map(fn($v) => (int) $v)->all() ?? [],
            'pagos' => PagoSuscripcion::where('user_id', $user->id)->latest()->limit(12)->get(),
            'moneda' => $moneda,
            'pasarela' => $cobros->pasarelaDe($moneda),
            'pagosListos' => $cobros->disponible($moneda),
            'licenciaNueva' => session('licencia_nueva'),
        ]);
    }

    /** Crea el pago pendiente y lleva a la pasarela de la moneda del cliente (Wompi, PayPal o Square). */
    public function pagar(Request $request, CobrosService $cobros): RedirectResponse
    {
        $datos = $request->validate([
            'plan' => ['required', Rule::in(array_keys($this->planes->planes()))],
            'periodo' => ['required', Rule::in(PlanService::PERIODOS)],
        ]);
        $moneda = $this->region->moneda($request, Auth::user());
        if (!$cobros->disponible($moneda)) return back()->with('error', __('Los pagos en línea aún no están configurados. Escríbenos para activar tu plan.'));
        try {
            return redirect()->away($cobros->iniciarPago(Auth::user(), $datos['plan'], $datos['periodo'], route('suscripcion.resultado'), $moneda));
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', __('No pudimos abrir el pago. Inténtalo de nuevo en unos minutos.'));
        }
    }

    /** Retorno del checkout (?id=transacción): se verifica con la API de Wompi, nunca con lo que dice la URL. */
    public function resultado(Request $request, WompiService $wompi, CobrosService $cobros): RedirectResponse
    {
        $id = (string) $request->query('id', '');
        $tx = $id !== '' ? $wompi->transaccion($id) : null;
        if (!$tx) return redirect()->route('suscripcion.index')->with('error', __('No pudimos confirmar el pago con Wompi. Si se descontó el dinero, se activará en unos minutos.'));
        $pago = $cobros->procesar($tx);
        if (!$pago || (int) $pago->user_id !== (int) Auth::id()) return redirect()->route('suscripcion.index')->with('error', __('El pago no corresponde a tu cuenta.'));
        return $this->mensajeResultado($pago, 'Wompi');
    }

    /** Retorno de Square (?ref=referencia de editus): se consulta la orden en la API de Square. */
    public function resultadoSquare(Request $request, CobrosService $cobros): RedirectResponse
    {
        $pago = PagoSuscripcion::where('referencia', (string) $request->query('ref', ''))->where('pasarela', 'square')->first();
        if (!$pago || (int) $pago->user_id !== (int) Auth::id()) return redirect()->route('suscripcion.index')->with('error', __('El pago no corresponde a tu cuenta.'));
        return $this->mensajeResultado($cobros->procesarSquare($pago), 'Square');
    }

    /** Retorno de PayPal (?ref=referencia de editus&token=orden): se consulta y se captura la orden en la API de PayPal. */
    public function resultadoPaypal(Request $request, CobrosService $cobros): RedirectResponse
    {
        $pago = PagoSuscripcion::where('referencia', (string) $request->query('ref', ''))->where('pasarela', 'paypal')->first();
        if (!$pago || (int) $pago->user_id !== (int) Auth::id()) return redirect()->route('suscripcion.index')->with('error', __('El pago no corresponde a tu cuenta.'));
        try {
            $pago = $cobros->procesarPaypal($pago);
        } catch (\Throwable $e) {
            report($e);
        }
        return $this->mensajeResultado($pago, 'PayPal');
    }

    /** La persona canceló en PayPal: el pago queda anulado (puede volver a intentarlo). */
    public function cancelarPaypal(Request $request): RedirectResponse
    {
        $pago = PagoSuscripcion::where('referencia', (string) $request->query('ref', ''))->where('pasarela', 'paypal')->where('user_id', Auth::id())->first();
        if ($pago && $pago->estado === 'PENDING') $pago->fill(['estado' => 'VOIDED'])->save();
        return redirect()->route('suscripcion.index')->with('error', __('Cancelaste el pago en PayPal. Puedes intentarlo de nuevo cuando quieras.'));
    }

    private function mensajeResultado(PagoSuscripcion $pago, string $pasarela): RedirectResponse
    {
        return match ($pago->estado) {
            'APPROVED' => redirect()->route('suscripcion.index')->with('success', __('¡Pago aprobado! Tu plan está activo.')),
            'PENDING' => redirect()->route('suscripcion.index')->with('success', __('Tu pago está en proceso. Se activará apenas :pasarela lo confirme.', ['pasarela' => $pasarela])),
            default => redirect()->route('suscripcion.index')->with('error', __('El pago no fue aprobado (:estado). Puedes intentarlo de nuevo.', ['estado' => $pago->estado])),
        };
    }

    public function paginas(Request $request): RedirectResponse
    {
        $datos = $request->validate(['paginas' => ['nullable', 'array'], 'paginas.*' => ['integer']]);
        $s = $this->planes->suscripcion(Auth::user());
        if (!$s) return back()->with('error', __('Primero activa un plan.'));
        $max = (int) ($s->config()['paginas'] ?? 0);
        if (count($datos['paginas'] ?? []) > $max) return back()->with('error', __('Tu plan permite :n página(s).', ['n' => $max]));
        $this->planes->elegirPaginas(Auth::user(), $datos['paginas'] ?? []);
        return back()->with('success', __('Páginas del plan guardadas.'));
    }

    /** Nueva llave para el plugin (la anterior deja de funcionar y se desvinculan los sitios). */
    public function regenerarLicencia(): RedirectResponse
    {
        $s = $this->planes->suscripcion(Auth::user());
        if (!$s || !$s->activa()) return back()->with('error', __('Necesitas un plan activo para tener licencia.'));
        $this->planes->generarLicencia($s);
        return back()->with('success', __('Nueva llave creada. Cópiala ahora: por seguridad no se vuelve a mostrar completa.'));
    }

    public function liberarSitio(Request $request): RedirectResponse
    {
        $sitio = (string) $request->validate(['sitio' => ['required', 'string', 'max:255']])['sitio'];
        $s = $this->planes->suscripcion(Auth::user());
        if ($s) $s->fill(['licencia_sitios' => array_values(array_filter((array) $s->licencia_sitios, fn($x) => ($x['url'] ?? '') !== $sitio))])->save();
        return back()->with('success', __('Sitio desvinculado de la licencia.'));
    }
}
