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
        // Usuario asociado a un periodista de esnoticia: publica siempre con su firma
        $fijo = $request->user()->firmaEsnoticia();
        return view('redes.index', [
            'configurado' => $configurado,
            'autores' => $autores,
            'autorSugerido' => $fijo ?: ($configurado ? $this->redes->autorSugerido($request->user()) : null),
            'autorFijo' => $fijo,
            'nombreFijo' => $fijo ? (collect($autores)->firstWhere('id', $fijo)['nombre'] ?? null) : null,
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
        if ($error = $this->faltaContenido($request, true)) return $error;
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
        if ($error = $this->faltaContenido($request, false)) return $error;
        @set_time_limit(360);
        return $this->pasar($request, 'POST', 'publicar-video', 330);
    }

    /**
     * Cada medio marcado necesita su categoría (no hay una por defecto) y, en una publicación nueva,
     * su título y su texto (los suyos o, si no, los generales).
     */
    private function faltaContenido(Request $request, bool $conTexto): ?JsonResponse
    {
        $medios = $request->json('medios');
        if (!is_array($medios) || !$medios) {
            return response()->json(['success' => false, 'error' => __('Elige al menos un medio.')], 422);
        }
        $tituloGeneral = trim((string) $request->json('titulo', ''));
        $textoGeneral = trim((string) $request->json('texto', ''));
        foreach ($medios as $m) {
            $nombre = (string) ($m['sitio_nombre'] ?? $m['slug'] ?? '');
            if (trim((string) ($m['categoria'] ?? '')) === '') {
                return response()->json(['success' => false, 'error' => __('Elige la categoría de :medio.', ['medio' => $nombre])], 422);
            }
            if ($conTexto && trim((string) ($m['titulo'] ?? '')) === '' && $tituloGeneral === '') {
                return response()->json(['success' => false, 'error' => __('Escribe el título de la nota para :medio.', ['medio' => $nombre])], 422);
            }
            if ($conTexto && trim((string) ($m['texto'] ?? '')) === '' && $textoGeneral === '') {
                return response()->json(['success' => false, 'error' => __('Escribe el texto de la nota para :medio.', ['medio' => $nombre])], 422);
            }
        }
        return null;
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
        // Firma fija: se ignora lo que mande el navegador
        if ($fijo = $request->user()?->firmaEsnoticia()) return $fijo;
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
