<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\RedesWebService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Redes (web): lo mismo que la sección Redes de la app del editor. Nota en la web de cada
 * medio + imagen (con plantilla) o video en sus páginas de Facebook e Instagram. Todo va a
 * la campaña de sistema Esnoticia. Las llamadas pasan al backend de esnoticia.
 */
class RedesController extends Controller
{
    public function __construct(private RedesWebService $redes) {}

    public function index(Request $request): View
    {
        $configurado = $this->redes->configurado();
        $autores = $configurado ? $this->redes->autores() : [];
        return view('redes.index', [
            'configurado' => $configurado,
            'autores' => $autores,
            'autorSugerido' => $configurado ? $this->redes->autorSugerido($request->user()) : null,
        ]);
    }

    /** Medios, páginas de cada medio y plantillas (lo que la app carga al abrir Redes). */
    public function catalogos(Request $request): JsonResponse
    {
        $autor = $this->autor($request);
        if (!$autor) return $this->sinAutor();

        $salida = ['success' => true, 'medios' => [], 'paginas' => [], 'plantillas' => [], 'categoria' => 'Opas', 'errores' => []];
        try {
            $salida['medios'] = $this->redes->medios();
        } catch (\Throwable $e) {
            $salida['errores']['medios'] = $e->getMessage();
        }
        [, $p] = $this->redes->llamar('GET', 'paginas', $autor);
        if (!empty($p['success'])) {
            $salida['paginas'] = $p['paginas'] ?? [];
            $salida['categoria'] = $p['categoria'] ?? 'Opas';
            if (!empty($p['error'])) $salida['errores']['paginas'] = $p['error'];
        } else {
            $salida['errores']['paginas'] = $p['error'] ?? __('No se pudieron cargar las páginas conectadas');
        }
        [, $t] = $this->redes->llamar('GET', 'plantillas', $autor);
        if (!empty($t['success'])) {
            $salida['plantillas'] = $t['plantillas'] ?? [];
            if (!empty($t['error'])) $salida['errores']['plantillas'] = $t['error'];
            if (($t['disponible'] ?? true) === false) $salida['errores']['plantillas'] = __('El servidor no puede componer plantillas (falta GD con FreeType en PHP).');
        } else {
            $salida['errores']['plantillas'] = $t['error'] ?? __('No se pudieron cargar las plantillas');
        }
        return response()->json($salida);
    }

    public function previsualizar(Request $request): JsonResponse
    {
        return $this->pasar($request, 'POST', 'previsualizar', 60);
    }

    public function publicar(Request $request): JsonResponse
    {
        @set_time_limit(360);
        return $this->pasar($request, 'POST', 'publicar', 330);
    }

    public function historial(Request $request): JsonResponse
    {
        return $this->pasar($request, 'GET', 'historial');
    }

    public function autorizarVideo(Request $request): JsonResponse
    {
        return $this->pasar($request, 'GET', 'video/autorizar');
    }

    public function videos(Request $request): JsonResponse
    {
        return $this->pasar($request, 'GET', 'videos', 60, ['limite' => 15]);
    }

    public function publicarVideo(Request $request): JsonResponse
    {
        @set_time_limit(360);
        return $this->pasar($request, 'POST', 'publicar-video', 330);
    }

    /** Reenvía la petición al backend en nombre del periodista elegido. */
    private function pasar(Request $request, string $metodo, string $ruta, int $timeout = 60, array $query = []): JsonResponse
    {
        $autor = $this->autor($request);
        if (!$autor) return $this->sinAutor();
        $datos = $metodo === 'GET' ? $query : collect($request->json()->all())->except(['autor', 'campaign_id'])->all();
        [$status, $json] = $this->redes->llamar($metodo, $ruta, $autor, $datos, $timeout);
        return response()->json($json, $status >= 200 && $status < 600 ? $status : 502);
    }

    private function autor(Request $request): ?int
    {
        if (!$this->redes->configurado()) return null;
        return $this->redes->autorValido($request->header('X-Autor', $request->input('autor')));
    }

    private function sinAutor(): JsonResponse
    {
        $msg = $this->redes->configurado()
            ? __('Elige el periodista que firma la nota.')
            : __('Falta conectar editus con el backend de esnoticia (ESNOTICIA_URL y EDITUS_INGEST_TOKEN en el .env).');
        return response()->json(['success' => false, 'error' => $msg], 422);
    }
}
