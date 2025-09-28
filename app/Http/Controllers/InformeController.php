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
        $q = trim((string) $request->query('q', '')); // texto en mensaje
        $pageId = $request->query('page_id');              // página específica

        // Base: solo posts "success"
        $base = MetaPost::query()
            ->from('meta_posts as p')
            ->where('p.status', 'success');

        // Texto en el mensaje (y link, por si quieres ubicar por URL)
        if ($q !== '') {
            $like = '%' . str_replace('%', '\%', $q) . '%';
            $base->where(function ($qb) use ($like) {
                $qb->where('p.message', 'like', $like)
                    ->orWhere('p.link', 'like', $like);
            });
        }

        // Filtrar por página
        if (!empty($pageId)) {
            $base->where('p.meta_page_id', (int) $pageId);
        }

        // Totales sobre lo filtrado
        $totals = (clone $base)
            ->selectRaw('
            COUNT(*)                                       as total_posts,
            SUM(COALESCE(p.alcance, 0))                    as total_alcance,
            SUM(COALESCE(p.visualizaciones, 0))            as total_visualizaciones,
            SUM(COALESCE(p.interacciones, 0))              as total_interacciones
        ')
            ->first();

        // Grupos: batch_uuid o single-{id}
        $groups = (clone $base)
            ->selectRaw("
            CASE 
                WHEN (p.batch_uuid IS NULL OR p.batch_uuid = '') 
                    THEN CONCAT('single-', p.id)
                ELSE p.batch_uuid
            END as group_key
        ")
            ->selectRaw('
            MAX(COALESCE(p.published_at, p.created_at))    as effective_at,
            MIN(p.message)                                  as message_sample,
            COUNT(*)                                        as posts_count,
            MIN(NULLIF(p.fb_permalink_url, ""))             as any_permalink,
            SUM(COALESCE(p.alcance, 0))                     as alcance_sum,
            SUM(COALESCE(p.visualizaciones, 0))             as visualizaciones_sum,
            SUM(COALESCE(p.interacciones, 0))               as interacciones_sum
        ')
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
    public function show(string $key, Request $request)
    {
        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // Traer posts del grupo (single-{id} o batch_uuid)
        $postsQ = MetaPost::query()
            ->with(['page:id,name']) // solo info básica de la página
            ->where('status', 'success');

        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $postsQ->where('id', $id);
        } else {
            $postsQ->where('batch_uuid', $key);
        }

        // Orden cronológico ascendente (útil para gráficos/timeline)
        $posts = $postsQ
            ->orderByRaw('COALESCE(published_at, created_at)')
            ->get();

        abort_if($posts->isEmpty(), 404);

        // --- Summary básico del grupo (todo directo desde meta_posts) ---
        $alcanceSum = (int) $posts->sum(fn($p) => (int) ($p->alcance ?? 0));
        $visSum = (int) $posts->sum(fn($p) => (int) ($p->visualizaciones ?? 0));
        $intSum = (int) $posts->sum(fn($p) => (int) ($p->interacciones ?? 0));

        // Máxima fecha efectiva (published_at o created_at)
        $effectiveAt = $posts->map(function ($p) {
            return $p->published_at ?? $p->created_at;
        })->filter()->max();

        $summary = [
            'total_posts' => $posts->count(),
            'alcance_sum' => $alcanceSum,
            'visualizaciones_sum' => $visSum,
            'interacciones_sum' => $intSum,
            'message_sample' => (string) ($posts->first()->message ?? '—'),
            'effective_at' => $effectiveAt, // Carbon|nullable
            'any_permalink' => optional($posts->firstWhere('fb_permalink_url'))?->fb_permalink_url,
        ];

        // --- Datos para gráficas ---

        // 1) Barras por PÁGINA (suma de métricas por página)
        $byPage = $posts->groupBy(fn($p) => $p->page->name ?? '—')->map(function ($group) {
            return [
                'alcance' => (int) $group->sum(fn($p) => (int) ($p->alcance ?? 0)),
                'visualizaciones' => (int) $group->sum(fn($p) => (int) ($p->visualizaciones ?? 0)),
                'interacciones' => (int) $group->sum(fn($p) => (int) ($p->interacciones ?? 0)),
            ];
        })->sortKeys();

        $chartByPage = [
            'labels' => $byPage->keys()->values(),
            'datasets' => [
                'Alcance' => $byPage->pluck('alcance')->values(),
                'Visualizaciones' => $byPage->pluck('visualizaciones')->values(),
                'Interacciones' => $byPage->pluck('interacciones')->values(),
            ],
        ];

        // 2) Barras por POST (en el orden cronológico)
        $chartByPost = [
            'labels' => $posts->map(function ($p) {
                $label = $p->page->name ?? 'Post ' . $p->id;
                $msg = trim((string) $p->message);
                // Etiqueta cortica: "Página • texto..."
                $short = $msg !== '' ? (Str::limit($msg, 24)) : ('#' . $p->id);
                return $label . ' • ' . $short;
            })->values(),
            'datasets' => [
                'Alcance' => $posts->map(fn($p) => (int) ($p->alcance ?? 0))->values(),
                'Visualizaciones' => $posts->map(fn($p) => (int) ($p->visualizaciones ?? 0))->values(),
                'Interacciones' => $posts->map(fn($p) => (int) ($p->interacciones ?? 0))->values(),
            ],
        ];

        return view('informe.show', compact('key', 'posts', 'summary', 'chartByPage', 'chartByPost'));
    }


    public function pdf(string $key)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $user = Auth::user();
        abort_unless($user->isAdmin(), 403);

        // === Traer posts (solo meta_posts) ===
        $query = MetaPost::query()
            ->with(['page:id,name'])  // info básica
            ->where('status', 'success');

        if (str_starts_with($key, 'single-')) {
            $id = (int) str_replace('single-', '', $key);
            $query->where('id', $id);
        } else {
            $query->where('batch_uuid', $key);
        }

        $posts = $query->get();
        abort_if($posts->isEmpty(), 404);

        $disk = Storage::disk('public');

        // Carpeta local para cachear imágenes que DomPDF leerá desde disco
        $cacheDir = public_path('pdf_cache');
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        // Construcción de filas (una por post); usa métricas directas de meta_posts
        $rows = $posts->filter(function ($p) {
            $a = (int) ($p->alcance ?? 0);
            $v = (int) ($p->visualizaciones ?? 0);
            $i = (int) ($p->interacciones ?? 0);
            return ($a + $v + $i) > 0;
        })
            ->map(function ($p) use ($disk, $cacheDir) {
                $evidenciaSrc = null;

                // Si tu tabla meta_posts tiene evidencia_path, lo aprovechamos.
                $evidPath = data_get($p, 'evidencia_path');
                if (!empty($evidPath)) {
                    try {
                        $abs = $disk->path($evidPath); // storage/app/public/...
    
                        if (file_exists($abs) && is_readable($abs)) {
                            // Redimensionar/normalizar a JPG en cache local (Intervention Image v3)
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

                            $canvas = Image::create($w, $h)->fill('#ffffff'); // fondo blanco por si hay alpha
                            $canvas->place($img, 'top-left');
                            $binary = $canvas->toJpeg(78)->toString(); // calidad 78%
    
                            $hash = md5($abs . '|' . @filemtime($abs) . "|$w|$h|78");
                            $filename = $hash . '.jpg';
                            $out = $cacheDir . DIRECTORY_SEPARATOR . $filename;
                            if (!file_exists($out)) {
                                file_put_contents($out, $binary);
                            }

                            // Dompdf leerá desde public/pdf_cache/...
                            $evidenciaSrc = 'pdf_cache/' . $filename;
                        } else {
                            // Último recurso: URL pública (requiere isRemoteEnabled=true)
                            $evidenciaSrc = $disk->url($evidPath);
                        }
                    } catch (\Throwable $e) {
                        $evidenciaSrc = $disk->url($evidPath);
                    }
                }

                return [
                    'pagina' => $p->page?->name ?? '—',
                    'alcance' => (int) ($p->alcance ?? 0),
                    'visualizaciones' => (int) ($p->visualizaciones ?? 0),
                    'interacciones' => (int) ($p->interacciones ?? 0),
                    'permalink' => $p->fb_permalink_url ?: $p->link,
                    'evidencia_src' => $evidenciaSrc, // "pdf_cache/xxx.jpg" o URL pública o null
                ];
            })
            ->values();

        $rawTitle = (string) ($posts->first()->message ?? '—');

        // NUEVO: contar páginas únicas con datos
        $totalPaginas = $rows->pluck('pagina')
            ->filter(fn($n) => !empty($n) && $n !== '—')
            ->unique()
            ->count();

        $summary = [
            'titulo' => $this->makePdfTitle($rawTitle, 120),
            'effective_at' => $posts->map(fn($p) => $p->published_at ?? $p->created_at)->filter()->max(),
            'total_alcance' => $rows->sum('alcance'),
            'total_visualizaciones' => $rows->sum('visualizaciones'),
            'total_interacciones' => $rows->sum('interacciones'),
            'total_posts' => $rows->count(),
            'total_paginas' => $totalPaginas, // ← agregado
        ];

        $pdf = Pdf::loadView('admin.informe.pdf', [
            'key' => $key,
            'summary' => $summary,
            'rows' => $rows,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => true,   // por si alguna evidencia queda como URL
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
