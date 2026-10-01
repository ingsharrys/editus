<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\PlantillaEditor;
use App\Services\MetaMetricasService;
use App\Services\MetaVideosService;
use App\Services\SocialPhotoPublisher;
use App\Services\SocialVideoPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * API de integración para el backend de esnoticia (sección "Redes" de la
 * app). Protegida con X-Editus-Token (middleware editus.token).
 *
 *   GET  /api/paginas             → páginas conectadas con token activo y visibles en la app
 *   GET  /api/plantillas          → plantillas de imagen activas
 *   POST /api/publicaciones/foto  → foto + texto en Facebook (con enlace) / Instagram (texto_instagram, sin enlace)
 *   GET  /api/videos              → últimos videos publicados en cada página (para la web)
 *   POST /api/publicaciones/video → video mp4 (URL) en Facebook / Instagram (Reel)
 *   POST /api/publicaciones/metricas → alcance, interacciones y reproducciones de publicaciones
 */
class PublicacionesController extends Controller
{
    public function paginas(): JsonResponse
    {
        $paginas = MetaPage::query()
            ->whereHas('users', fn($q) => $q->where('meta_page_user.is_active', 1)->whereNotNull('meta_page_user.page_access_token'))
            ->when(Schema::hasColumn('meta_pages', 'visible_en_editor'), fn($q) => $q->where('visible_en_editor', 1))
            ->orderBy('name')
            ->get()
            ->map(fn(MetaPage $p) => [
                'id' => $p->id,
                'page_id' => (string) $p->page_id,
                'nombre' => (string) $p->name,
                'categoria' => $p->category,
                'foto' => $p->pictureUrl('small'),
                'instagram' => !empty($p->instagram_business_account_id),
                'instagram_id' => $p->instagram_business_account_id,
                'medio' => $p->medio_slug ?: null,
            ])
            ->values();

        return response()->json(['success' => true, 'paginas' => $paginas]);
    }

    /** Plantillas de imagen activas (Configuración → App del editor). */
    public function plantillas(): JsonResponse
    {
        if (!Schema::hasTable('plantillas_editor')) {
            return response()->json(['success' => true, 'plantillas' => []]);
        }
        $plantillas = PlantillaEditor::query()
            ->where('activa', 1)
            ->orderBy('orden')->orderBy('id')
            ->get()
            ->map(fn(PlantillaEditor $t) => $t->paraApi())
            ->values();

        return response()->json(['success' => true, 'plantillas' => $plantillas]);
    }

    public function foto(Request $request, SocialPhotoPublisher $publisher): JsonResponse
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:5000'],
            'texto_instagram' => ['nullable', 'string', 'max:2200'],
            'imagen_url' => ['required', 'url'],
            'paginas' => ['required', 'array', 'min:1'],
            'paginas.*.id' => ['nullable', 'integer'],
            'paginas.*.page_id' => ['nullable', 'string'],
            'paginas.*.facebook' => ['nullable', 'boolean'],
            'paginas.*.instagram' => ['nullable', 'boolean'],
            'paginas.*.enlace' => ['nullable', 'url'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        $batch = (string) Str::uuid();
        $resultados = [];
        $exitos = 0;

        foreach ($datos['paginas'] as $item) {
            $page = null;
            if (!empty($item['id'])) {
                $page = MetaPage::find($item['id']);
            }
            if (!$page && !empty($item['page_id'])) {
                $page = MetaPage::where('page_id', (string) $item['page_id'])->first();
            }

            $r = [
                'id' => $page?->id,
                'page_id' => (string) ($page?->page_id ?? ($item['page_id'] ?? '')),
                'pagina' => (string) ($page?->name ?? ''),
                'facebook' => null,
                'instagram' => null,
            ];

            if (!$page) {
                $r['facebook'] = ['ok' => false, 'error' => 'Página no encontrada en editus'];
                $resultados[] = $r;
                continue;
            }

            $facebook = array_key_exists('facebook', $item) ? (bool) $item['facebook'] : true;
            $instagram = array_key_exists('instagram', $item) ? (bool) $item['instagram'] : false;
            if (!$facebook && !$instagram) {
                $resultados[] = $r;
                continue;
            }

            $enlace = trim((string) ($item['enlace'] ?? ''));
            // Facebook: texto + enlace al final. Instagram: su propio texto (sin
            // enlace, porque allí no es clicable) o, si no llega, el mismo de Facebook.
            $mensaje = trim($datos['texto']);
            if ($enlace !== '' && !str_contains($mensaje, $enlace)) {
                $mensaje .= "\n\n" . $enlace;   // si el texto ya trae el enlace (p. ej. "Ver más: …"), no se repite
            }
            $captionIg = trim((string) ($datos['texto_instagram'] ?? ''));

            $res = $publisher->publicar($page, $datos['imagen_url'], $mensaje, $facebook, $instagram, $enlace ?: null, $batch, $captionIg !== '' ? $captionIg : null);
            $r['facebook'] = $res['facebook'];
            $r['instagram'] = $res['instagram'];
            if (($res['facebook']['ok'] ?? false) || ($res['instagram']['ok'] ?? false)) {
                $exitos++;
            }
            $resultados[] = $r;
        }

        return response()->json([
            'success' => $exitos > 0,
            'batch' => $batch,
            'publicadas' => $exitos,
            'resultados' => $resultados,
        ]);
    }

    /**
     * GET /api/videos?limite=15: últimos videos ya publicados en cada página
     * visible en la app (Facebook: videos y reels; Instagram: videos/reels),
     * para llevarlos a la web como embed.
     */
    public function videos(Request $request, MetaVideosService $servicio): JsonResponse
    {
        $limite = max(1, min(50, (int) $request->query('limite', 15)));
        $pageId = trim((string) $request->query('page_id', ''));
        @set_time_limit(120);

        $paginas = MetaPage::query()
            ->whereHas('users', fn($q) => $q->where('meta_page_user.is_active', 1)->whereNotNull('meta_page_user.page_access_token'))
            ->when(Schema::hasColumn('meta_pages', 'visible_en_editor'), fn($q) => $q->where('visible_en_editor', 1))
            ->when($pageId !== '', fn($q) => $q->where('page_id', $pageId))
            ->orderBy('name')
            ->get();

        $salida = [];
        foreach ($paginas as $p) {
            $r = $servicio->dePagina($p, $limite);
            $salida[] = [
                'id' => $p->id,
                'page_id' => (string) $p->page_id,
                'nombre' => (string) $p->name,
                'foto' => $p->pictureUrl('small'),
                'medio' => $p->medio_slug ?: null,
                'instagram' => !empty($p->instagram_business_account_id),
                'videos' => $r['videos'],
                'error' => $r['error'],
            ];
        }
        return response()->json(['success' => true, 'paginas' => $salida]);
    }

    /**
     * POST /api/publicaciones/video: video (URL pública mp4) + texto en
     * Facebook (video de página) e Instagram (Reel). Mismo contrato que foto.
     */
    public function video(Request $request, SocialVideoPublisher $publisher): JsonResponse
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:5000'],
            'texto_instagram' => ['nullable', 'string', 'max:2200'],
            'video_url' => ['required', 'url'],
            'imagen_url' => ['nullable', 'url'],
            'paginas' => ['required', 'array', 'min:1'],
            'paginas.*.id' => ['nullable', 'integer'],
            'paginas.*.page_id' => ['nullable', 'string'],
            'paginas.*.facebook' => ['nullable', 'boolean'],
            'paginas.*.instagram' => ['nullable', 'boolean'],
            'paginas.*.enlace' => ['nullable', 'url'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);
        @set_time_limit(600);

        $batch = (string) Str::uuid();
        $resultados = [];
        $exitos = 0;

        foreach ($datos['paginas'] as $item) {
            $page = !empty($item['id']) ? MetaPage::find($item['id']) : null;
            if (!$page && !empty($item['page_id'])) {
                $page = MetaPage::where('page_id', (string) $item['page_id'])->first();
            }
            $r = ['id' => $page?->id, 'page_id' => (string) ($page?->page_id ?? ($item['page_id'] ?? '')), 'pagina' => (string) ($page?->name ?? ''), 'facebook' => null, 'instagram' => null];
            if (!$page) {
                $r['facebook'] = ['ok' => false, 'error' => 'Página no encontrada en editus'];
                $resultados[] = $r;
                continue;
            }
            $facebook = array_key_exists('facebook', $item) ? (bool) $item['facebook'] : true;
            $instagram = array_key_exists('instagram', $item) ? (bool) $item['instagram'] : false;
            if (!$facebook && !$instagram) {
                $resultados[] = $r;
                continue;
            }
            $enlace = trim((string) ($item['enlace'] ?? ''));
            $mensaje = trim($datos['texto']);
            if ($enlace !== '' && !str_contains($mensaje, $enlace)) {
                $mensaje .= "\n\n" . $enlace;
            }
            $captionIg = trim((string) ($datos['texto_instagram'] ?? ''));

            $res = $publisher->publicarVideo($page, $datos['video_url'], $mensaje, $facebook, $instagram, $enlace ?: null, $batch, $captionIg !== '' ? $captionIg : null, $datos['imagen_url'] ?? null);
            $r['facebook'] = $res['facebook'];
            $r['instagram'] = $res['instagram'];
            if (($res['facebook']['ok'] ?? false) || ($res['instagram']['ok'] ?? false)) {
                $exitos++;
            }
            $resultados[] = $r;
        }

        return response()->json(['success' => $exitos > 0, 'batch' => $batch, 'publicadas' => $exitos, 'resultados' => $resultados]);
    }

    /**
     * POST /api/publicaciones/metricas: métricas en vivo de Meta para una
     * lista de publicaciones {red, post_id, page_id, media_id?, tipo?}.
     */
    public function metricas(Request $request, MetaMetricasService $servicio): JsonResponse
    {
        $datos = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.red' => ['nullable', 'string', 'in:facebook,instagram'],
            'items.*.post_id' => ['required', 'string'],
            'items.*.page_id' => ['required', 'string'],
            'items.*.media_id' => ['nullable', 'string'],
            'items.*.tipo' => ['nullable', 'string', 'in:foto,video'],
        ]);
        @set_time_limit(300);

        return response()->json(['success' => true, 'metricas' => $servicio->metricas($datos['items'])]);
    }
}
