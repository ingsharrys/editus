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
 *   POST /api/publicaciones/video → video (URL o video_id temporal) en Facebook / Instagram (Reel)
 *   POST /api/publicaciones/video/descripcion → agrega el enlace a la descripción del video en Facebook
 *   POST /api/publicaciones/metricas → alcance, interacciones y reproducciones de publicaciones
 */
class PublicacionesController extends Controller
{
    public function paginas(Request $request, \App\Services\CuentasAppService $cuentas): JsonResponse
    {
        // Con ?usuario= (id del usuario de la app): sus páginas conectadas desde la app + las de la organización
        $usuario = trim((string) $request->query('usuario', '')) ?: null;
        $nombre = trim((string) $request->query('usuario_nombre', '')) ?: null;
        $paginas = $cuentas->paginasDe($usuario, $nombre)
            ->map(fn(MetaPage $p) => [
                'id' => $p->id,
                'page_id' => (string) $p->page_id,
                'nombre' => (string) $p->name,
                'categoria' => $p->category,
                'foto' => $p->pictureUrl('small'),
                'instagram' => !empty($p->instagram_business_account_id),
                'instagram_id' => $p->instagram_business_account_id,
                'medio' => $p->medio_slug ?: null,
                'propia' => (bool) $p->getAttribute('propia'),
            ])
            ->values();

        return response()->json(['success' => true, 'paginas' => $paginas]);
    }

    /**
     * GET /api/campanas: campañas que se pueden elegir al publicar desde la app (activas, no de sistema),
     * con sus medios y las páginas que incluyen (para marcar los medios al elegirla).
     */
    public function campanas(\App\Services\CampanasService $servicio): JsonResponse
    {
        if (!Schema::hasTable('campaigns')) return response()->json(['success' => true, 'campanas' => []]);
        $lista = \App\Models\Campaign::selectable()->with('perfil.paginas')->get()->map(fn($c) => [
            'id' => $c->id,
            'nombre' => $c->name,
            'tipo' => $c->tipo,
            'descripcion' => $c->description,
            'medios' => array_values((array) $c->medios),
            'medios_nombres' => $c->nombresMedios(),
            'page_ids' => $servicio->paginasDe($c)->pluck('page_id')->map(fn($v) => (string) $v)->values()->all(),
        ])->values();
        return response()->json(['success' => true, 'campanas' => $lista]);
    }

    /** La campaña indicada si existe, está activa y no es de sistema. */
    private function campanaValida($id): ?int
    {
        if (!$id || !Schema::hasTable('campaigns')) return null;
        return \App\Models\Campaign::selectable()->whereKey((int) $id)->value('id');
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
            'usuario' => ['nullable', 'string', 'max:60'],
            'campaign_id' => ['nullable', 'integer'],
        ]);
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp($datos['usuario'] ?? null);
        $publisher->campaignId = $this->campanaValida($datos['campaign_id'] ?? null);

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
            'video_url' => ['nullable', 'url', 'required_without:video_id'],
            'video_id' => ['nullable', 'string', 'required_without:video_url'],
            'imagen_url' => ['nullable', 'url'],
            'paginas' => ['required', 'array', 'min:1'],
            'paginas.*.id' => ['nullable', 'integer'],
            'paginas.*.page_id' => ['nullable', 'string'],
            'paginas.*.facebook' => ['nullable', 'boolean'],
            'paginas.*.instagram' => ['nullable', 'boolean'],
            'paginas.*.enlace' => ['nullable', 'url'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'usuario' => ['nullable', 'string', 'max:60'],
            'campaign_id' => ['nullable', 'integer'],
        ]);
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp($datos['usuario'] ?? null);
        $publisher->campaignId = $this->campanaValida($datos['campaign_id'] ?? null);
        @set_time_limit(600);

        // Video subido temporalmente desde la app (SubidasController): se usa
        // su URL pública y se borra al terminar
        $videoId = trim((string) ($datos['video_id'] ?? ''));
        $videoUrl = (string) ($datos['video_url'] ?? '');
        if ($videoId !== '') {
            $ruta = SubidasController::rutaDe($videoId);
            if (!$ruta) {
                return response()->json(['success' => false, 'error' => 'El video temporal ya no existe (venció o ya se publicó): súbelo de nuevo'], 422);
            }
            $videoUrl = url(\Illuminate\Support\Facades\Storage::disk('public')->url($ruta));
        }

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

            $res = $publisher->publicarVideo($page, $videoUrl, $mensaje, $facebook, $instagram, $enlace ?: null, $batch, $captionIg !== '' ? $captionIg : null, $datos['imagen_url'] ?? null);
            $r['facebook'] = $res['facebook'];
            $r['instagram'] = $res['instagram'];
            if (($res['facebook']['ok'] ?? false) || ($res['instagram']['ok'] ?? false)) {
                $exitos++;
            }
            $resultados[] = $r;
        }

        if ($videoId !== '') {
            SubidasController::borrar($videoId);
        }

        return response()->json(['success' => $exitos > 0, 'batch' => $batch, 'publicadas' => $exitos, 'resultados' => $resultados]);
    }

    /**
     * POST /api/publicaciones/video/descripcion: cambia la descripción de un
     * video ya publicado en Facebook (para agregarle el enlace de la web,
     * que se conoce después de publicar el video).
     */
    public function descripcionVideo(Request $request, \App\Services\MetaPageTokenResolver $tokens): JsonResponse
    {
        $datos = $request->validate([
            'page_id' => ['required', 'string'],
            'video_id' => ['required', 'string'],
            'descripcion' => ['required', 'string', 'max:5000'],
        ]);
        $token = $tokens->forPage($datos['page_id']);
        if (!$token) {
            return response()->json(['success' => false, 'error' => 'La página no tiene un token activo en editus'], 422);
        }
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph($datos['video_id']), [
                'description' => $datos['descripcion'],
                'access_token' => $token,
            ]);
            if (!$r->ok()) {
                return response()->json(['success' => false, 'error' => (string) (data_get($r->json(), 'error.message') ?: 'Facebook no aceptó la descripción')]);
            }
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')]);
        }
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
