<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Services\SocialPhotoPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API de integración para el backend de esnoticia (sección "Redes" de la
 * app). Protegida con X-Editus-Token (middleware editus.token).
 *
 *   GET  /api/paginas             → páginas conectadas con token activo
 *   POST /api/publicaciones/foto  → foto + texto en Facebook / Instagram
 */
class PublicacionesController extends Controller
{
    public function paginas(): JsonResponse
    {
        $paginas = MetaPage::query()
            ->whereHas('users', fn($q) => $q->where('meta_page_user.is_active', 1)->whereNotNull('meta_page_user.page_access_token'))
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
            ])
            ->values();

        return response()->json(['success' => true, 'paginas' => $paginas]);
    }

    public function foto(Request $request, SocialPhotoPublisher $publisher): JsonResponse
    {
        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:5000'],
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
            $mensaje = trim($datos['texto']) . ($enlace !== '' ? "\n\n" . $enlace : '');

            $res = $publisher->publicar($page, $datos['imagen_url'], $mensaje, $facebook, $instagram, $enlace ?: null, $batch);
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
}
