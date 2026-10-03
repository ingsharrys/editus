<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\RecursoEnVivo;
use App\Models\TransmisionEnVivo;
use Illuminate\Support\Facades\Schema;
use App\Services\FacebookLiveService;
use App\Services\LiveKitClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Transmisiones en vivo desde la app del editor (API con token de integración):
 *
 *   POST /api/en-vivo/iniciar            → crea el Live en Facebook, la sala LiveKit y el egress (RTMP)
 *   POST /api/en-vivo/{id}/plantilla     → cambia la plantilla en tiempo real (metadata de la sala)
 *   POST /api/en-vivo/{id}/escena        → diseño y quién sale al aire (solo, dos, pip, cuadrícula)
 *   POST /api/en-vivo/{id}/invitacion    → enlace para una cámara remota (navegador)
 *   GET  /api/en-vivo/{id}/participantes → cámaras conectadas
 *   POST /api/en-vivo/{id}/participantes/{identity}/expulsar
 *   POST /api/en-vivo/{id}/terminar      → detiene el egress y cierra el Live
 *   GET  /api/en-vivo/{id}/estado        → estado, espectadores y token nuevo para reconectar
 *   GET  /api/en-vivo/activas            → transmisiones en vivo (por si la app se cerró)
 *
 * Flujo: la app publica su cámara en la sala; la escena (/en-vivo/escena,
 * plantilla HTML en tiempo real) la compone el egress y la envía al RTMP de Facebook.
 */
class EnVivoController extends Controller
{
    public function __construct(private LiveKitClient $livekit, private FacebookLiveService $facebook)
    {
    }

    public function iniciar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'page_id' => ['nullable', 'string'],
            'page_ids' => ['nullable', 'array', 'max:10'],
            'page_ids.*' => ['string'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'usuario' => ['nullable', 'string', 'max:60'],
            'plantilla' => ['nullable', 'array'],
        ]);
        if (!$this->livekit->configurado()) {
            return response()->json(['success' => false, 'error' => 'LiveKit no está configurado en editus (LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET). Ver infra/en-vivo/README.md'], 422);
        }
        $ids = array_values(array_unique(array_filter(array_merge([(string) ($datos['page_id'] ?? '')], (array) ($datos['page_ids'] ?? [])))));
        $pages = MetaPage::whereIn('page_id', $ids)->get()->sortBy(fn($p) => array_search($p->page_id, $ids, true))->values();
        if ($pages->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Página no encontrada en editus'], 422);
        }
        $page = $pages->first();
        @set_time_limit(180);

        $plantilla = self::normalizarPlantilla($datos['plantilla'] ?? [], $datos['titulo']);
        $room = 'envivo-' . $page->page_id . '-' . Str::lower(Str::random(6));
        $t = TransmisionEnVivo::create([
            'meta_page_id' => $page->id,
            'usuario_app' => $datos['usuario'] ?? null,
            'titulo' => $datos['titulo'],
            'descripcion' => $datos['descripcion'] ?? null,
            'room' => $room,
            'estado' => 'creada',
            'plantilla' => $plantilla,
        ]);

        try {
            // 1) Un Facebook Live por página (cada uno da su URL RTMP secreta)
            $destinos = [];
            $errores = [];
            foreach ($pages as $p) {
                try {
                    $live = $this->facebook->crear($p, $datos['titulo'], (string) ($datos['descripcion'] ?? ''));
                    $destinos[] = ['meta_page_id' => $p->id, 'page_id' => (string) $p->page_id, 'pagina' => (string) $p->name, 'fb_live_id' => $live['id'], 'fb_video_id' => $live['video_id'],
                        'permalink' => $live['permalink'], 'stream_url' => $live['stream_url'], 'estado' => 'ok', 'error' => null];
                } catch (\Throwable $e) {
                    $errores[] = "{$p->name}: " . $e->getMessage();
                    $destinos[] = ['meta_page_id' => $p->id, 'page_id' => (string) $p->page_id, 'pagina' => (string) $p->name, 'fb_live_id' => null, 'fb_video_id' => null,
                        'permalink' => null, 'stream_url' => null, 'estado' => 'error', 'error' => Str::limit($e->getMessage(), 300, '')];
                }
            }
            $ok = array_values(array_filter($destinos, fn($d) => $d['estado'] === 'ok'));
            if (!$ok) throw new \RuntimeException(implode(' | ', $errores));
            $t->fill(['destinos' => $destinos, 'meta_page_id' => $ok[0]['meta_page_id'], 'fb_live_id' => $ok[0]['fb_live_id'], 'stream_url' => $ok[0]['stream_url'], 'fb_permalink' => $ok[0]['permalink'], 'fb_video_id' => $ok[0]['fb_video_id']])->save();

            // 2) Sala LiveKit con la plantilla y la escena como metadata (la página de la escena las lee en tiempo real)
            $t->escena = ['layout' => 'solo', 'principal' => 'camara-principal', 'visibles' => []];
            $t->save();
            $this->livekit->crearSala($room, $t->metadataSala());

            // 3) Egress: la escena compuesta → RTMP de todas las páginas a la vez
            $egressId = $this->livekit->iniciarEgressRtmp($room, $this->escenaUrl(), array_map(fn($d) => $d['stream_url'], $ok));
            $t->fill(['egress_id' => $egressId, 'estado' => 'en_vivo', 'iniciada_en' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('[EN VIVO] no se pudo iniciar', ['room' => $room, 'err' => $e->getMessage()]);
            $this->limpiar($t);
            $t->fill(['estado' => 'error', 'error' => Str::limit($e->getMessage(), 500, '')])->save();
            return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 300, '')], 422);
        }

        return response()->json([
            'success' => true,
            'transmision' => $t->fresh()->paraApi(),
            'livekit' => ['url' => $this->livekit->wsUrl(), 'token' => $this->tokenCamara($t, 'camara-principal', 'Cámara principal')],
        ]);
    }

    public function plantilla(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $datos = $request->validate(['plantilla' => ['required', 'array']]);
        $plantilla = self::normalizarPlantilla(array_merge($transmision->plantilla ?? [], $datos['plantilla']), $transmision->titulo);
        $transmision->plantilla = $plantilla;
        $transmision->save();
        if ($transmision->estado === 'en_vivo') {
            try {
                $this->livekit->actualizarMetadata($transmision->room, $transmision->metadataSala());
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')], 422);
            }
        }
        return response()->json(['success' => true, 'plantilla' => $plantilla]);
    }

    /**
     * POST /api/en-vivo/{id}/escena {layout: solo|dos|pip|cuadricula, principal, visibles[]}:
     * quién sale al aire y con qué diseño (metadata de la sala → la escena lo aplica al instante).
     */
    public function escena(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $datos = $request->validate([
            'layout' => ['nullable', 'string', 'in:solo,dos,pip,cuadricula'],
            'principal' => ['nullable', 'string', 'max:80'],
            'visibles' => ['nullable', 'array', 'max:8'],
            'visibles.*' => ['string', 'max:80'],
            'recurso_id' => ['nullable', 'integer'],
            'quitar_recurso' => ['nullable', 'boolean'],
        ]);
        $escena = $transmision->escena ?? ['layout' => 'solo', 'principal' => 'camara-principal', 'visibles' => []];
        if (!empty($datos['layout'])) $escena['layout'] = $datos['layout'];
        if (array_key_exists('principal', $datos) && $datos['principal'] !== null && $datos['principal'] !== '') $escena['principal'] = $datos['principal'];
        if (array_key_exists('visibles', $datos) && is_array($datos['visibles'])) $escena['visibles'] = array_values(array_unique($datos['visibles']));
        // Recurso de producción (cortinilla, comercial, imagen) a pantalla completa
        if (!empty($datos['quitar_recurso'])) {
            unset($escena['recurso']);
        } elseif (!empty($datos['recurso_id'])) {
            $r = RecursoEnVivo::where('activo', true)->find((int) $datos['recurso_id']);
            if (!$r) return response()->json(['success' => false, 'error' => 'Recurso no encontrado'], 422);
            $escena['recurso'] = $r->paraEscena();
        }
        $transmision->escena = $escena;
        $transmision->save();
        if ($transmision->estado === 'en_vivo') {
            try {
                $this->livekit->actualizarMetadata($transmision->room, $transmision->metadataSala());
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')], 422);
            }
        }
        return response()->json(['success' => true, 'escena' => $escena]);
    }

    /** GET /api/en-vivo/recursos: cortinillas, comerciales e imágenes disponibles para sacar al aire. */
    public function recursos(): JsonResponse
    {
        $lista = Schema::hasTable('recursos_en_vivo') ? RecursoEnVivo::where('activo', true)->orderBy('orden')->orderBy('id')->get()->map(fn($r) => $r->paraApi())->values() : collect();
        return response()->json(['success' => true, 'recursos' => $lista]);
    }

    /** POST /api/en-vivo/{id}/invitacion {nombre?}: enlace para que alguien envíe su cámara desde el navegador. */
    public function invitacion(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $datos = $request->validate(['nombre' => ['nullable', 'string', 'max:60']]);
        $codigo = Str::lower(Str::random(10));
        $lista = $transmision->invitaciones ?? [];
        $lista[] = ['codigo' => $codigo, 'nombre' => $datos['nombre'] ?? null, 'creada_en' => now()->toIso8601String()];
        $transmision->invitaciones = $lista;
        $transmision->save();
        return response()->json(['success' => true, 'codigo' => $codigo, 'url' => route('en-vivo.invitado', $codigo)]);
    }

    /** GET /api/en-vivo/{id}/participantes: cámaras conectadas a la sala. */
    public function participantes(TransmisionEnVivo $transmision): JsonResponse
    {
        try {
            $lista = $transmision->estado === 'en_vivo' ? $this->livekit->participantes($transmision->room) : [];
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')], 422);
        }
        return response()->json(['success' => true, 'participantes' => $lista, 'escena' => $transmision->escena]);
    }

    /** POST /api/en-vivo/{id}/participantes/{identity}/expulsar */
    public function expulsar(TransmisionEnVivo $transmision, string $identity): JsonResponse
    {
        if ($identity === 'camara-principal') {
            return response()->json(['success' => false, 'error' => 'La cámara principal no se puede expulsar'], 422);
        }
        try {
            $this->livekit->expulsar($transmision->room, $identity);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')], 422);
        }
        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------- Invitados (web, sin token de integración)

    /** Página del invitado (navegador): valida el código y muestra la transmisión. */
    public function invitadoPagina(string $codigo)
    {
        [$t, $inv] = $this->buscarInvitacion($codigo);
        if (!$t) abort(404, 'Invitación no válida');
        return view('en-vivo.invitado', ['transmision' => $t, 'invitacion' => $inv, 'codigo' => $codigo, 'activa' => $t->estado === 'en_vivo']);
    }

    /** Token de LiveKit para el invitado (lo pide la página con su código). */
    public function invitadoToken(Request $request, string $codigo): JsonResponse
    {
        [$t, $inv] = $this->buscarInvitacion($codigo);
        if (!$t) return response()->json(['success' => false, 'error' => 'Invitación no válida'], 404);
        if ($t->estado !== 'en_vivo') return response()->json(['success' => false, 'error' => 'La transmisión no está en vivo en este momento'], 422);
        $nombre = Str::limit(trim((string) $request->input('nombre', $inv['nombre'] ?? 'Invitado')), 40, '') ?: 'Invitado';
        $identity = 'invitado-' . $codigo;
        return response()->json([
            'success' => true,
            'url' => $this->livekit->wsUrl(),
            'token' => $this->livekit->tokenParticipante($t->room, $identity, $nombre, true, 4 * 3600),
            'identity' => $identity,
            'titulo' => $t->titulo,
        ]);
    }

    private function buscarInvitacion(string $codigo): array
    {
        $codigo = Str::lower(preg_replace('/[^a-z0-9]/i', '', $codigo));
        if ($codigo === '') return [null, null];
        $t = TransmisionEnVivo::where('created_at', '>=', now()->subDays(2))->orderByDesc('id')->get()
            ->first(fn($x) => collect($x->invitaciones ?? [])->contains(fn($i) => ($i['codigo'] ?? '') === $codigo));
        if (!$t) return [null, null];
        $inv = collect($t->invitaciones)->first(fn($i) => ($i['codigo'] ?? '') === $codigo);
        return [$t, $inv];
    }

    public function terminar(TransmisionEnVivo $transmision): JsonResponse
    {
        $this->limpiar($transmision);
        if ($transmision->estado !== 'error') {
            $transmision->fill(['estado' => 'terminada', 'terminada_en' => now()])->save();
        }
        // Id del video resultante en cada página (para la web y las métricas)
        $destinos = $transmision->destinosLista();
        foreach ($destinos as $i => $d) {
            if (empty($d['fb_live_id']) || !empty($d['fb_video_id'])) continue;
            $p = MetaPage::find($d['meta_page_id'] ?? 0);
            if (!$p) continue;
            $st = $this->facebook->estado($p, $d['fb_live_id']);
            if (!empty($st['video_id'])) { $destinos[$i]['fb_video_id'] = $st['video_id']; $destinos[$i]['permalink'] = $st['permalink'] ?? $d['permalink']; }
        }
        $principal = collect($destinos)->first(fn($d) => ($d['estado'] ?? '') === 'ok');
        $transmision->fill(['destinos' => $destinos, 'fb_video_id' => $principal['fb_video_id'] ?? $transmision->fb_video_id, 'fb_permalink' => $principal['permalink'] ?? $transmision->fb_permalink])->save();
        return response()->json(['success' => true, 'transmision' => $transmision->fresh()->paraApi()]);
    }

    public function estado(TransmisionEnVivo $transmision): JsonResponse
    {
        // Espectadores: suma de todas las páginas
        $porPagina = [];
        $total = null;
        $fb = [];
        foreach ($transmision->destinosLista() as $d) {
            if (empty($d['fb_live_id'])) continue;
            $p = MetaPage::find($d['meta_page_id'] ?? 0);
            $st = $p ? $this->facebook->estado($p, $d['fb_live_id']) : [];
            if (!$fb) $fb = $st;
            $porPagina[] = ['pagina' => $d['pagina'] ?? '', 'page_id' => $d['page_id'] ?? '', 'status' => $st['status'] ?? null, 'espectadores' => $st['espectadores'] ?? null, 'permalink' => $d['permalink'] ?? null];
            if (($st['espectadores'] ?? null) !== null) $total = ($total ?? 0) + (int) $st['espectadores'];
        }
        $info = $transmision->egress_id ? $this->livekit->infoEgress($transmision->egress_id) : [];
        $egress = $info['status'] ?? null;
        if ($transmision->estado === 'en_vivo' && in_array($egress, ['EGRESS_FAILED', 'EGRESS_ABORTED'], true)) {
            $motivo = trim(($info['error'] ?? '') ?: ($info['stream_error'] ?? ''));
            $transmision->fill(['estado' => 'error', 'error' => Str::limit('La salida a Facebook se cortó (' . $egress . ($motivo !== '' ? ': ' . $motivo : '') . ')', 400, '')])->save();
        }
        return response()->json([
            'success' => true,
            'transmision' => $transmision->fresh()->paraApi(),
            'facebook' => ['status' => $fb['status'] ?? null, 'espectadores' => $total, 'por_pagina' => $porPagina],
            'egress' => $egress,
            'livekit' => $transmision->estado === 'en_vivo'
                ? ['url' => $this->livekit->wsUrl(), 'token' => $this->tokenCamara($transmision, 'camara-principal', 'Cámara principal')]
                : null,
        ]);
    }

    public function activas(Request $request): JsonResponse
    {
        $q = TransmisionEnVivo::with('page')->where('estado', 'en_vivo')->orderByDesc('id');
        if ($request->filled('usuario')) $q->where('usuario_app', (string) $request->query('usuario'));
        return response()->json(['success' => true, 'transmisiones' => $q->get()->map(fn($t) => $t->paraApi())->values()]);
    }

    // ------------------------------------------------------------------

    private function tokenCamara(TransmisionEnVivo $t, string $identity, string $nombre): string
    {
        return $this->livekit->tokenParticipante($t->room, $identity, $nombre, true);
    }

    private function escenaUrl(): string
    {
        $url = (string) config('services.livekit.escena_url');
        return $url !== '' ? $url : route('en-vivo.escena');
    }

    private function limpiar(TransmisionEnVivo $t): void
    {
        if ($t->egress_id) $this->livekit->detenerEgress($t->egress_id);
        foreach ($t->destinosLista() as $d) {
            if (empty($d['fb_live_id'])) continue;
            $p = MetaPage::find($d['meta_page_id'] ?? 0);
            if ($p) $this->facebook->terminar($p, $d['fb_live_id']);
        }
        if ($t->room) $this->livekit->borrarSala($t->room);
    }

    /** Plantilla en tiempo real (misma idea que las piezas de Redes). */
    public static function normalizarPlantilla(array $p, string $titulo): array
    {
        $color = function ($v, string $def): string {
            $v = strtoupper(trim((string) $v));
            return preg_match('/^#[0-9A-F]{6}$/', $v) ? $v : $def;
        };
        return [
            'mostrar' => !array_key_exists('mostrar', $p) || (bool) $p['mostrar'],
            'titulo' => Str::limit(trim((string) ($p['titulo'] ?? $titulo)), 140, ''),
            'etiqueta' => Str::upper(Str::limit(trim((string) ($p['etiqueta'] ?? 'EN VIVO')), 30, '')),
            'logo_texto' => Str::limit(trim((string) ($p['logo_texto'] ?? '')), 40, ''),
            'pie' => Str::limit(trim((string) ($p['pie'] ?? '')), 60, ''),
            'hashtag' => Str::limit(trim((string) ($p['hashtag'] ?? '')), 40, ''),
            'color_logo' => $color($p['color_logo'] ?? null, '#FFFFFF'),
            'color_titulo' => $color($p['color_titulo'] ?? null, '#FFFFFF'),
            'color_etiqueta' => $color($p['color_etiqueta'] ?? null, '#C8102E'),
            'en_vivo' => !array_key_exists('en_vivo', $p) || (bool) $p['en_vivo'],
            // Producción: rótulo del presentador o periodista y logo en imagen
            'rotulo_nombre' => Str::limit(trim((string) ($p['rotulo_nombre'] ?? '')), 60, ''),
            'rotulo_cargo' => Str::limit(trim((string) ($p['rotulo_cargo'] ?? '')), 60, ''),
            'rotulo_mostrar' => (bool) ($p['rotulo_mostrar'] ?? false),
            'logo_url' => preg_match('#^https?://#i', trim((string) ($p['logo_url'] ?? ''))) ? Str::limit(trim((string) $p['logo_url']), 500, '') : '',
        ];
    }
}
