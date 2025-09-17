<?php

namespace App\Http\Controllers;

use App\Models\MetaPost;
use App\Models\MetaPage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class InformeController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Filtros
        $q = trim((string) $request->query('q', ''));        // texto en el título/mensaje
        $pageId = $request->query('page_id');                     // página específica (opcional)

        // Base: solo posts "success"
        $base = MetaPost::query()->where('status', 'success');

        // Texto en el mensaje
        if ($q !== '') {
            $base->where('message', 'like', '%' . str_replace('%', '\%', $q) . '%');
        }

        // Filtrar por página (solo muestra publicaciones donde haya participado esa página)
        if (!empty($pageId)) {
            $base->where('meta_page_id', (int) $pageId);
        }

        // Totales sobre lo filtrado
        $totals = (clone $base)
            ->selectRaw('
            COUNT(*)                                       as total_posts,
            COALESCE(SUM(alcance), 0)                      as total_alcance,
            COALESCE(SUM(visualizaciones), 0)              as total_visualizaciones,
            COALESCE(SUM(interacciones), 0)                as total_interacciones
        ')
            ->first();

        // Agrupar publicaciones: batch_uuid o single-{id}
        $groups = (clone $base)
            ->selectRaw("
            CASE 
                WHEN (batch_uuid IS NULL OR batch_uuid = '') 
                    THEN CONCAT('single-', id)
                ELSE batch_uuid
            END                                            as group_key,
            MAX(COALESCE(published_at, created_at))       as effective_at,
            MIN(message)                                   as message_sample,
            COALESCE(SUM(alcance), 0)                      as alcance_sum,
            COALESCE(SUM(visualizaciones), 0)              as visualizaciones_sum,
            COALESCE(SUM(interacciones), 0)                as interacciones_sum,
            COUNT(DISTINCT meta_page_id)                   as posts_count,
            MIN(NULLIF(fb_permalink_url, ''))              as any_permalink
        ")
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
        ]);
    }
    public function show(string $key)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Si key es single-#, mostramos ese único post; si no, es un batch_uuid
        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $posts = MetaPost::with(['page:id,name,page_id'])
                ->where('status', 'success')
                ->where('id', $id)
                ->get();
        } else {
            $posts = MetaPost::with(['page:id,name,page_id'])
                ->where('status', 'success')
                ->where('batch_uuid', $key)
                ->get();
        }

        abort_if($posts->isEmpty(), 404);

        // Totales del grupo
        $summary = [
            'total_posts' => $posts->count(),
            'alcance_sum' => $posts->sum(fn($p) => (int) ($p->alcance ?? 0)),
            'visualizaciones_sum' => $posts->sum(fn($p) => (int) ($p->visualizaciones ?? 0)),
            'interacciones_sum' => $posts->sum(fn($p) => (int) ($p->interacciones ?? 0)),
            'message_sample' => (string) ($posts->first()->message ?? '—'),
            'effective_at' => $posts->max(fn($p) => optional($p->published_at ?? $p->created_at)),
            'any_permalink' => $posts->firstWhere('fb_permalink_url')?->fb_permalink_url,
        ];

        // Orden por métricas y nombre de página
        $byPage = $posts->sortBy(fn($p) => $p->page?->name ?? '')->values();

        return view('informe.show', compact('key', 'summary', 'byPage'));
    }


    public function pdf(string $key)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // === Traer posts ===
        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $posts = MetaPost::with(['page:id,name,page_id'])
                ->where('status', 'success')
                ->where('id', $id)
                ->get();
        } else {
            $posts = MetaPost::with(['page:id,name,page_id'])
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

        $rows = $posts->filter(function ($p) {
            $a = (int) ($p->alcance ?? 0);
            $v = (int) ($p->visualizaciones ?? 0);
            $i = (int) ($p->interacciones ?? 0);
            return ($a + $v + $i) > 0;
        })->map(function ($p) use ($disk, $cacheDir) {
            $evidenciaSrc = null;

            if ($p->evidencia_path) {
                try {
                    $abs = $disk->path($p->evidencia_path); // storage/app/public/...

                    if (file_exists($abs) && is_readable($abs)) {
                        // Lee imagen (sin orientate en v3) y reduce a máx 1000x1000 (rápido)
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

                        // A fondo blanco (por si PNG/WebP con alpha) y comprime a JPG
                        $canvas = Image::create($w, $h)->fill('#ffffff');
                        $canvas->place($img, 'top-left');
                        $binary = $canvas->toJpeg(78)->toString(); // calidad 78% ≈ buena/ligera

                        // Cachea archivo en public/pdf_cache para que DomPDF lo lea del disco
                        $hash = md5($abs . '|' . @filemtime($abs) . "|$w|$h|78");
                        $filename = $hash . '.jpg';
                        $out = $cacheDir . DIRECTORY_SEPARATOR . $filename;
                        if (!file_exists($out)) {
                            file_put_contents($out, $binary);
                        }

                        // Ruta relativa respecto a public/ (sin slash inicial)
                        $evidenciaSrc = 'pdf_cache/' . $filename;
                    } else {
                        // Último recurso: URL pública (más lento, intenta evitarse)
                        $evidenciaSrc = $disk->url($p->evidencia_path);
                    }
                } catch (\Throwable $e) {
                    // Si algo falla, al menos intenta con URL
                    $evidenciaSrc = $disk->url($p->evidencia_path);
                }
            }

            return [
                'pagina' => $p->page?->name ?? '—',
                'alcance' => (int) ($p->alcance ?? 0),
                'visualizaciones' => (int) ($p->visualizaciones ?? 0),
                'interacciones' => (int) ($p->interacciones ?? 0),
                'permalink' => $p->fb_permalink_url,
                'evidencia_src' => $evidenciaSrc, // "pdf_cache/xxx.jpg" o URL
            ];
        })->values();

        $summary = [
            'titulo' => (string) ($posts->first()->message ?? '—'),
            'effective_at' => $posts->max(fn($p) => ($p->published_at ?? $p->created_at)),
            'total_alcance' => $rows->sum('alcance'),
            'total_visualizaciones' => $rows->sum('visualizaciones'),
            'total_interacciones' => $rows->sum('interacciones'),
            'total_paginas' => $rows->count(),
        ];

        $pdf = Pdf::loadView('admin.informe.pdf', [
            'key' => $key,
            'summary' => $summary,
            'rows' => $rows,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,        // no queremos HTTP si no es necesario
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'chroot' => public_path() // permite leer "pdf_cache/..." del disco
            ]);

        $filename = 'reporte-editus-'
            . Str::slug(Str::limit($summary['titulo'], 60, ''))
            . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->stream($filename);
    }



}
