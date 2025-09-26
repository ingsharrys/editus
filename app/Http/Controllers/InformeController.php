<?php

namespace App\Http\Controllers;

use App\Models\MetaPost;
use App\Models\MetaPage;
use App\Models\MetaPostMetric;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\DB;


class InformeController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Filtros
        $q = trim((string) $request->query('q', ''));     // texto en título/mensaje
        $pageId = $request->query('page_id');                  // página específica
        $round = (int) $request->query('round', 0);           // ?round=1|2; 0 => último

        // Subconsulta de métricas: por round fijo o por último round por post
        if ($round > 0) {
            $metricSub = DB::table('meta_post_metrics as m')
                ->select('m.meta_post_id', 'm.round', 'm.alcance', 'm.visualizaciones', 'm.interacciones')
                ->where('m.round', $round);
        } else {
            // último round por meta_post_id
            $metricSub = DB::table('meta_post_metrics as m')
                ->select('m.meta_post_id', 'm.round', 'm.alcance', 'm.visualizaciones', 'm.interacciones')
                ->join(
                    DB::raw('(SELECT meta_post_id, MAX(round) AS max_round FROM meta_post_metrics GROUP BY meta_post_id) mx'),
                    function ($join) {
                        $join->on('m.meta_post_id', '=', 'mx.meta_post_id')
                            ->on('m.round', '=', 'mx.max_round');
                    }
                );
        }

        // Base: solo posts "success"
        $base = MetaPost::query()->from('meta_posts as p')->where('p.status', 'success');

        // Texto en el mensaje
        if ($q !== '') {
            $base->where('p.message', 'like', '%' . str_replace('%', '\%', $q) . '%');
        }

        // Filtrar por página
        if (!empty($pageId)) {
            $base->where('p.meta_page_id', (int) $pageId);
        }

        // Expresiones CASE para elegir métrica (preferir metricSub; si no hay, legacy; si metric=0 pero legacy>0, usar legacy)
        $sumAlcExpr = "
        SUM(
            CASE 
                WHEN mm.meta_post_id IS NULL THEN COALESCE(p.alcance, 0)
                WHEN (COALESCE(mm.alcance,0)+COALESCE(mm.visualizaciones,0)+COALESCE(mm.interacciones,0)) = 0
                     AND (COALESCE(p.alcance,0)+COALESCE(p.visualizaciones,0)+COALESCE(p.interacciones,0)) > 0
                THEN COALESCE(p.alcance, 0)
                ELSE COALESCE(mm.alcance, 0)
            END
        ) as alcance_sum
    ";

        $sumVisExpr = "
        SUM(
            CASE 
                WHEN mm.meta_post_id IS NULL THEN COALESCE(p.visualizaciones, 0)
                WHEN (COALESCE(mm.alcance,0)+COALESCE(mm.visualizaciones,0)+COALESCE(mm.interacciones,0)) = 0
                     AND (COALESCE(p.alcance,0)+COALESCE(p.visualizaciones,0)+COALESCE(p.interacciones,0)) > 0
                THEN COALESCE(p.visualizaciones, 0)
                ELSE COALESCE(mm.visualizaciones, 0)
            END
        ) as visualizaciones_sum
    ";

        $sumIntExpr = "
        SUM(
            CASE 
                WHEN mm.meta_post_id IS NULL THEN COALESCE(p.interacciones, 0)
                WHEN (COALESCE(mm.alcance,0)+COALESCE(mm.visualizaciones,0)+COALESCE(mm.interacciones,0)) = 0
                     AND (COALESCE(p.alcance,0)+COALESCE(p.visualizaciones,0)+COALESCE(p.interacciones,0)) > 0
                THEN COALESCE(p.interacciones, 0)
                ELSE COALESCE(mm.interacciones, 0)
            END
        ) as interacciones_sum
    ";

        // Totales sobre lo filtrado (mismo CASE pero con alias total_*)
        $totAlcExpr = str_replace('as alcance_sum', 'as total_alcance', $sumAlcExpr);
        $totVisExpr = str_replace('as visualizaciones_sum', 'as total_visualizaciones', $sumVisExpr);
        $totIntExpr = str_replace('as interacciones_sum', 'as total_interacciones', $sumIntExpr);

        $totals = (clone $base)
            ->leftJoinSub($metricSub, 'mm', function ($join) {
                $join->on('mm.meta_post_id', '=', 'p.id');
            })
            ->selectRaw('
            COUNT(*) as total_posts
        ')
            ->selectRaw($totAlcExpr)
            ->selectRaw($totVisExpr)
            ->selectRaw($totIntExpr)
            ->first();

        // Agrupar publicaciones: batch_uuid o single-{id}
        $groups = (clone $base)
            ->leftJoinSub($metricSub, 'mm', function ($join) {
                $join->on('mm.meta_post_id', '=', 'p.id');
            })
            ->selectRaw("
            CASE 
                WHEN (p.batch_uuid IS NULL OR p.batch_uuid = '') 
                    THEN CONCAT('single-', p.id)
                ELSE p.batch_uuid
            END as group_key
        ")
            ->selectRaw('
            MAX(COALESCE(p.published_at, p.created_at)) as effective_at,
            MIN(p.message)                               as message_sample,
            COUNT(DISTINCT p.meta_page_id)               as posts_count,
            MIN(NULLIF(p.fb_permalink_url, ""))          as any_permalink
        ')
            ->selectRaw($sumAlcExpr)
            ->selectRaw($sumVisExpr)
            ->selectRaw($sumIntExpr)
            ->groupBy('group_key')
            ->orderByDesc('effective_at')
            ->paginate(12)
            ->withQueryString();

        // Páginas para el <select>
        $pages = MetaPage::orderBy('name')->get(['id', 'name']);

        return view('informe.index', [
            'totals' => $totals,
            'groups' => $groups,
            'pages' => $pages,
            'q' => $q,
            'pageId' => $pageId,
            'round' => $round ?: null, // por si quieres mostrar/seleccionar round en la vista
        ]);
    }

    public function show(string $key)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Elegir round: ?round=1|2 (0 o vacío => usar el último disponible)
        $round = (int) request('round', 0);

        // Traer posts + metrics
        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $posts = MetaPost::with([
                'page:id,name,page_id',
                'metrics:id,meta_post_id,round,alcance,visualizaciones,interacciones,evidencia_path',
            ])
                ->where('status', 'success')
                ->where('id', $id)
                ->get();
        } else {
            $posts = MetaPost::with([
                'page:id,name,page_id',
                'metrics:id,meta_post_id,round,alcance,visualizaciones,interacciones,evidencia_path',
            ])
                ->where('status', 'success')
                ->where('batch_uuid', $key)
                ->get();
        }

        abort_if($posts->isEmpty(), 404);

        // Métrica virtual (fallback) cuando NO hay registros en meta_post_metrics
        $makeVirtualMetric = function ($p) {
            return new MetaPostMetric([
                'meta_post_id' => $p->id,
                'round' => 0, // fallback
                'alcance' => (int) ($p->alcance ?? 0),
                'visualizaciones' => (int) ($p->visualizaciones ?? 0),
                'interacciones' => (int) ($p->interacciones ?? 0),
                'evidencia_path' => $p->evidencia_path,
            ]);
        };

        // Inyectar fallback SOLO si no hay métricas
        $posts->each(function ($p) use ($makeVirtualMetric) {
            $metrics = $p->metrics ?? collect();
            if ($metrics->isEmpty()) {
                $p->setRelation('metrics', collect([$makeVirtualMetric($p)]));
            }
        });

        // Helper para escoger la métrica a usar por post
        $pickMetric = function ($post) use ($round, $makeVirtualMetric) {
            // 1) elegir candidata por round o último
            $candidate = null;
            if ($round > 0) {
                $candidate = $post->metrics->firstWhere('round', $round);
            }
            if (!$candidate) {
                $candidate = $post->metrics->sortByDesc('round')->first();
            }

            // 2) si no hay candidata -> virtual
            if (!$candidate)
                return $makeVirtualMetric($post);

            // 3) si candidata suma 0 pero el MetaPost legacy sí tiene datos -> usar virtual
            $sumCand = (int) ($candidate->alcance ?? 0)
                + (int) ($candidate->visualizaciones ?? 0)
                + (int) ($candidate->interacciones ?? 0);
            $sumLegacy = (int) ($post->alcance ?? 0)
                + (int) ($post->visualizaciones ?? 0)
                + (int) ($post->interacciones ?? 0);

            if ($sumCand === 0 && $sumLegacy > 0) {
                return $makeVirtualMetric($post);
            }

            return $candidate;
        };


        // Totales
        $alcanceSum = 0;
        $visSum = 0;
        $intSum = 0;

        foreach ($posts as $p) {
            $m = $pickMetric($p);
            $alcanceSum += (int) ($m->alcance ?? 0);
            $visSum += (int) ($m->visualizaciones ?? 0);
            $intSum += (int) ($m->interacciones ?? 0);
        }

        $summary = [
            'total_posts' => $posts->count(),
            'alcance_sum' => $alcanceSum,
            'visualizaciones_sum' => $visSum,
            'interacciones_sum' => $intSum,
            'message_sample' => (string) ($posts->first()->message ?? '—'),
            'effective_at' => $posts->max(fn($p) => optional($p->published_at ?? $p->created_at)),
            'any_permalink' => $posts->firstWhere('fb_permalink_url')?->fb_permalink_url,
            'round' => $round ?: null, // para que la vista sepa qué round se está mostrando
        ];

        // Orden por nombre de página
        $byPage = $posts->sortBy(fn($p) => $p->page?->name ?? '')->values();

        return view('informe.show', compact('key', 'summary', 'byPage'));
    }



    public function pdf(string $key)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Round solicitado (?round=1|2). Si 0 o vacío => usar el último round disponible por post
        $roundReq = (int) request('round', 0);

        // === Traer posts + métricas ===
        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $posts = MetaPost::with([
                'page:id,name,page_id',
                'metrics:id,meta_post_id,round,alcance,visualizaciones,interacciones,evidencia_path',
            ])
                ->where('status', 'success')
                ->where('id', $id)
                ->get();
        } else {
            $posts = MetaPost::with([
                'page:id,name,page_id',
                'metrics:id,meta_post_id,round,alcance,visualizaciones,interacciones,evidencia_path',
            ])
                ->where('status', 'success')
                ->where('batch_uuid', $key)
                ->get();
        }
        abort_if($posts->isEmpty(), 404);

        $disk = Storage::disk('public');

        // Carpeta de cache para imágenes que DomPDF leerá desde disco
        $cacheDir = public_path('pdf_cache');
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        // Armado de filas usando la métrica del round pedido o, si no hay, el último round
        $rows = $posts->filter(function ($p) use ($roundReq) {
            $metrics = $p->metrics;
            $metric = $roundReq > 0
                ? $metrics->firstWhere('round', $roundReq)
                : $metrics->sortByDesc('round')->first();

            $a = (int) ($metric->alcance ?? 0);
            $v = (int) ($metric->visualizaciones ?? 0);
            $i = (int) ($metric->interacciones ?? 0);
            return ($a + $v + $i) > 0;
        })->map(function ($p) use ($disk, $cacheDir, $roundReq) {
            $metrics = $p->metrics;
            $metric = $roundReq > 0
                ? $metrics->firstWhere('round', $roundReq)
                : $metrics->sortByDesc('round')->first();

            $evidenciaSrc = null;

            if ($metric?->evidencia_path) {
                try {
                    $abs = $disk->path($metric->evidencia_path); // storage/app/public/...

                    if (file_exists($abs) && is_readable($abs)) {
                        // Lee imagen y reduce a máx 1000x1000 (Intervention Image v3)
                        $img = Image::read($abs);
                        $w = $img->width();
                        $h = $img->height();
                        $maxW = 1000;
                        $maxH = 1000;
                        $ratio = min($maxW / $w, $maxH / $h, 1);
                        if ($ratio < 1) {
                            $img = $img->scale((int) round($w * $ratio), (int) round($h * $ratio));
                            $w = $img->width();
                            $h = $img->height();
                        }

                        // Fondo blanco (por si PNG/WebP con alpha) y comprime a JPG
                        $canvas = Image::create($w, $h)->fill('#ffffff');
                        $canvas->place($img, 'top-left');
                        $binary = $canvas->toJpeg(78)->toString(); // calidad 78% ≈ buena/ligera

                        // Cachear archivo en public/pdf_cache para que DomPDF lo lea desde disco
                        $hash = md5($abs . '|' . @filemtime($abs) . "|$w|$h|78");
                        $filename = $hash . '.jpg';
                        $out = $cacheDir . DIRECTORY_SEPARATOR . $filename;
                        if (!file_exists($out)) {
                            file_put_contents($out, $binary);
                        }

                        // Ruta relativa respecto a public/ (sin slash inicial)
                        $evidenciaSrc = 'pdf_cache/' . $filename;
                    } else {
                        // Último recurso: URL pública (ideal si tienes storage:link)
                        $evidenciaSrc = $disk->url($metric->evidencia_path);
                    }
                } catch (\Throwable $e) {
                    // Si algo falla, al menos intenta con URL
                    $evidenciaSrc = $disk->url($metric->evidencia_path);
                }
            }

            return [
                'pagina' => $p->page?->name ?? '—',
                'alcance' => (int) ($metric->alcance ?? 0),
                'visualizaciones' => (int) ($metric->visualizaciones ?? 0),
                'interacciones' => (int) ($metric->interacciones ?? 0),
                'permalink' => $p->fb_permalink_url,
                'evidencia_src' => $evidenciaSrc, // "pdf_cache/xxx.jpg" o URL pública
            ];
        })->values();

        $rawTitle = (string) ($posts->first()->message ?? '—');

        $summary = [
            'titulo' => $this->makePdfTitle($rawTitle, 120),
            'effective_at' => $posts->max(fn($p) => ($p->published_at ?? $p->created_at)),
            'total_alcance' => $rows->sum('alcance'),
            'total_visualizaciones' => $rows->sum('visualizaciones'),
            'total_interacciones' => $rows->sum('interacciones'),
            'total_paginas' => $rows->count(),
            'round' => $roundReq ?: null, // para mostrar en el PDF si quieres
        ];

        $pdf = Pdf::loadView('admin.informe.pdf', [
            'key' => $key,
            'summary' => $summary,
            'rows' => $rows,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,       // evita HTTP si no es necesario
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'chroot' => public_path(), // permite leer "pdf_cache/..." del disco
            ]);

        $filename = 'reporte-editus-'
            . Str::slug(Str::limit($summary['titulo'], 60, ''))
            . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->stream($filename);
    }


    private function makePdfTitle(?string $text, int $max = 120): string
    {
        $text = trim((string) $text);

        // Colapsar saltos de línea y espacios múltiples
        $text = preg_replace('/\s+/u', ' ', $text);

        // Quitar VARIATION SELECTOR-16 y ZWJ/géneros que arman emojis
        $text = preg_replace('/[\x{FE0F}\x{200D}\x{2640}\x{2642}]/u', '', $text);

        // Quitar emojis y pictogramas comunes (rangos amplios)
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2600}-\x{27BF}]/u', '', $text);

        // Quitar cualquier caracter fuera del BMP (más raro)
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

        $text = trim($text);

        // Si hay un punto, tomar solo la primera oración
        if (preg_match('/^(.+?\.)\s*/u', $text, $m)) {
            $text = $m[1];
        }

        // Corte duro a N caracteres con “…” si es necesario
        $text = Str::limit($text !== '' ? $text : '—', $max, '…');

        return $text;
    }


}
