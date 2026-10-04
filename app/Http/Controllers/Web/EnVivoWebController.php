<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\EnVivoController;
use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\RecursoEnVivo;
use App\Models\TransmisionEnVivo;
use App\Services\CuentasAppService;
use App\Services\LiveKitClient;
use App\Services\MetaPageTokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Estudio de transmisión en vivo en la web de editus: lo mismo que la app del editor
 * (sala LiveKit, escena con plantilla, cámaras invitadas, recursos, Facebook y YouTube a la vez),
 * desde el navegador. Cada usuario transmite a SUS páginas (el administrador, a todas).
 * Las acciones reutilizan Api\EnVivoController, así web y app se comportan igual.
 */
class EnVivoWebController extends Controller
{
    public function __construct(private EnVivoController $api)
    {
    }

    public function index(LiveKitClient $livekit, CuentasAppService $cuentas): View
    {
        $user = Auth::user();
        $conUsuario = Schema::hasColumn('transmisiones_en_vivo', 'user_id');
        $mias = TransmisionEnVivo::with('page')->when($conUsuario && !$user->isAdmin(), fn($q) => $q->where('user_id', $user->id))->orderByDesc('id');
        return view('en-vivo.estudio', [
            'paginas' => $this->paginasPermitidas()->map(fn(MetaPage $p) => [
                'page_id' => (string) $p->page_id, 'nombre' => (string) $p->name, 'foto' => $p->picture_url ?: $p->pictureUrl('small'),
            ])->values(),
            'canales' => $cuentas->canalesDe(null)->map(fn($c) => ['id' => $c->id, 'titulo' => (string) $c->titulo])->values(),
            'recursos' => Schema::hasTable('recursos_en_vivo') ? RecursoEnVivo::where('activo', true)->orderBy('orden')->orderBy('id')->get()->map(fn($r) => $r->paraApi())->values() : collect(),
            'activa' => (clone $mias)->whereIn('estado', ['sala', 'en_vivo'])->first()?->paraApi(),
            'historial' => (clone $mias)->whereIn('estado', ['terminada', 'error'])->limit(8)->get()->map(fn($t) => $t->paraApi() + ['fecha' => $t->created_at?->format('d/m/Y H:i')]),
            'configurado' => $livekit->configurado(),
        ]);
    }

    public function preparar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'page_ids' => ['nullable', 'array', 'max:10'], 'page_ids.*' => ['string'],
            'youtube_canal_ids' => ['nullable', 'array', 'max:5'], 'youtube_canal_ids.*' => ['integer'],
            'titulo' => ['required', 'string', 'max:200'], 'descripcion' => ['nullable', 'string', 'max:5000'],
            'plantilla' => ['nullable', 'array'],
        ]);
        $permitidas = $this->paginasPermitidas()->pluck('page_id')->map(fn($v) => (string) $v)->flip();
        foreach ((array) ($datos['page_ids'] ?? []) as $id) {
            if (!$permitidas->has((string) $id)) return response()->json(['success' => false, 'error' => 'Alguna de las páginas elegidas no está conectada a tu cuenta de editus.'], 422);
        }
        $this->preferir();
        $r = $this->api->preparar(new Request($datos + ['usuario' => null]));
        $json = $r->getData(true);
        if (!empty($json['success']) && Schema::hasColumn('transmisiones_en_vivo', 'user_id')) {
            TransmisionEnVivo::whereKey($json['transmision']['id'])->update(['user_id' => Auth::id()]);
        }
        return $r;
    }

    public function aire(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        $this->preferir();
        return $this->api->salirAlAire(new Request($request->only('intro_recurso_id')), $transmision);
    }

    public function escena(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        return $this->api->escena(new Request($request->all()), $transmision);
    }

    public function plantilla(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        return $this->api->plantilla(new Request($request->all()), $transmision);
    }

    public function invitacion(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        return $this->api->invitacion(new Request($request->only('nombre', 'modo')), $transmision);
    }

    public function participantes(TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        return $this->api->participantes($transmision);
    }

    public function expulsar(TransmisionEnVivo $transmision, string $identity): JsonResponse
    {
        $this->propia($transmision);
        return $this->api->expulsar($transmision, $identity);
    }

    public function estado(TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        $this->preferir();
        return $this->api->estado($transmision);
    }

    public function terminar(TransmisionEnVivo $transmision): JsonResponse
    {
        $this->propia($transmision);
        $this->preferir();
        return $this->api->terminar($transmision);
    }

    // ------------------------------------------------------------------

    /** Páginas a las que puede transmitir: las que el usuario conectó (el administrador, todas las que tienen token). */
    private function paginasPermitidas(): Collection
    {
        $user = Auth::user();
        return MetaPage::query()
            ->whereHas('vinculos', function ($q) use ($user) {
                $q->where('is_active', 1)->whereNotNull('page_access_token');
                if (!$user->isAdmin()) $q->where('user_id', $user->id);
            })
            ->orderBy('name')->get();
    }

    private function propia(TransmisionEnVivo $t): void
    {
        $user = Auth::user();
        abort_unless($user->isAdmin() || (int) ($t->user_id ?? 0) === (int) $user->id, 403);
    }

    /** Los Lives se crean con el token del usuario que transmite (si conectó la página). */
    private function preferir(): void
    {
        MetaPageTokenResolver::preferirUsuarioApp(null);
        MetaPageTokenResolver::preferirUsuarioWeb((int) Auth::id());
    }
}
