<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\CobrosService;
use App\Services\PlanService;
use App\Services\RegionService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Landing comercial de editus (dominio principal editus.online) y suscripción en un paso:
 * el cliente elige plan y periodo, crea su cuenta (o entra con la suya) y paga:
 * en Colombia en pesos con Wompi, desde el exterior en dólares con Square.
 */
class LandingController extends Controller
{
    public function __construct(private PlanService $planes, private RegionService $region)
    {
    }

    public function index(Request $request): View
    {
        return view('landing.index', [
            'planes' => $this->planes->planes(),
            'moneda' => $this->region->moneda($request, Auth::user()),
            'email' => (string) config('planes.contacto_email'),
            'whatsapp' => preg_replace('/\D/', '', (string) config('planes.contacto_whatsapp')),
        ]);
    }

    public function formulario(Request $request, CobrosService $cobros): View
    {
        $planes = $this->planes->planes();
        $plan = array_key_exists((string) $request->query('plan'), $planes) ? (string) $request->query('plan') : 'full';
        $periodo = in_array($request->query('periodo'), PlanService::PERIODOS, true) ? (string) $request->query('periodo') : 'mensual';
        $user = Auth::user();
        $moneda = $this->region->moneda($request, $user);
        // Si inicia sesión desde aquí, vuelve a este mismo plan
        if (!$user) $request->session()->put('url.intended', $request->fullUrl());
        return view('landing.suscribirse', [
            'planes' => $planes, 'plan' => old('plan', $plan), 'periodo' => old('periodo', $periodo),
            'user' => $user, 'sinLimites' => $user && $this->planes->sinLimites($user),
            'suscripcion' => $user ? $this->planes->suscripcion($user) : null,
            'moneda' => $moneda,
            'pagosListos' => $cobros->disponible($moneda),
        ]);
    }

    /** Crea la cuenta (si no hay sesión) y lleva al pago (Wompi o Square según la moneda). */
    public function suscribir(Request $request, CobrosService $cobros): RedirectResponse
    {
        // Campo trampa para bots: los humanos no lo ven
        if (filled($request->input('sitio_web'))) return redirect()->route('landing.suscribirse');
        $reglas = [
            'plan' => ['required', Rule::in(array_keys($this->planes->planes()))],
            'periodo' => ['required', Rule::in(PlanService::PERIODOS)],
        ];
        if (!Auth::check()) {
            $reglas += [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
                'password' => ['required', 'confirmed', Password::defaults()],
                'acepto' => ['accepted'],
            ];
        }
        $datos = $request->validate($reglas, [
            'email.unique' => __('Ya hay una cuenta con ese correo: inicia sesión para continuar.'),
            'acepto.accepted' => __('Debes aceptar la política de privacidad.'),
        ]);
        $moneda = $this->region->moneda($request, Auth::user());
        if (!$cobros->disponible($moneda)) {
            return back()->withInput($request->except('password', 'password_confirmation'))->with('error', __('Los pagos en línea aún no están activos. Escríbenos y activamos tu plan.'));
        }

        $user = Auth::user();
        if (!$user) {
            $user = User::create(['name' => $datos['name'], 'email' => $datos['email'], 'password' => Hash::make($datos['password'])]);
            if ($rol = Role::where('slug', 'user')->value('id')) $user->forceFill(['role_id' => $rol])->save();
            if (Schema::hasColumn('users', 'idioma')) $user->forceFill(['idioma' => app()->getLocale(), 'pais' => $this->region->pais($request)])->save();
            event(new Registered($user));
            Auth::login($user);
            $request->session()->regenerate();
        }
        if ($this->planes->sinLimites($user)) return redirect()->route('suscripcion.index');
        try {
            return redirect()->away($cobros->iniciarPago($user, $datos['plan'], $datos['periodo'], route('suscripcion.resultado'), $moneda));
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('suscripcion.index')->with('error', __('No pudimos abrir el pago. Inténtalo de nuevo en unos minutos.'));
        }
    }
}
