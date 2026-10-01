<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\TransmisionEnVivo;
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
            'page_id' => ['required', 'string'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'usuario' => ['nullable', 'string', 'max:60'],
            'plantilla' => ['nullable', 'array'],
        ]);
        if (!$this->livekit->configurado()) {
            return response()->json(['success' => false, 'error' => 'LiveKit no está configurado en editus (LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET). Ver infra/en-vivo/README.md'], 422);
        }
        $page = MetaPage::where('page_id', $datos['page_id'])->first();
        if (!$page) {
            return response()->json(['success' => false, 'error' => 'Página no encontrada en editus'], 422);
        }
        @set_time_limit(120);

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
            // 1) Facebook Live (da la URL RTMP secreta)
            $live = $this->facebook->crear($page, $datos['titulo'], (string) ($datos['descripcion'] ?? ''));
            $t->fill(['fb_live_id' => $live['id'], 'stream_url' => $live['stream_url'], 'fb_permalink' => $live['permalink'], 'fb_video_id' => $live['video_id']])->save();

            // 2) Sala LiveKit con la plantilla como metadata (la escena la lee en tiempo real)
            $this->livekit->crearSala($room, $plantilla);

            // 3) Egress: la escena compuesta → RTMP de Facebook
            $egressId = $this->livekit->iniciarEgressRtmp($room, $this->escenaUrl(), [$live['stream_url']]);
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
                $this->livekit->actualizarMetadata($transmision->room, $plantilla);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')], 422);
            }
        }
        return response()->json(['success' => true, 'plantilla' => $plantilla]);
    }

    public function terminar(TransmisionEnVivo $transmision): JsonResponse
    {
        $this->limpiar($transmision);
        if ($transmision->estado !== 'error') {
            $transmision->fill(['estado' => 'terminada', 'terminada_en' => now()])->save();
        }
        // Id del video resultante (para la web y las métricas)
        if ($transmision->fb_live_id && !$transmision->fb_video_id) {
            $st = $this->facebook->estado($transmision->page, $transmision->fb_live_id);
            if (!empty($st['video_id'])) $transmision->fill(['fb_video_id' => $st['video_id'], 'fb_permalink' => $st['permalink'] ?? $transmision->fb_permalink])->save();
        }
        return response()->json(['success' => true, 'transmision' => $transmision->fresh()->paraApi()]);
    }

    public function estado(TransmisionEnVivo $transmision): JsonResponse
    {
        $fb = $transmision->fb_live_id ? $this->facebook->estado($transmision->page, $transmision->fb_live_id) : [];
        $egress = $transmision->egress_id ? $this->livekit->estadoEgress($transmision->egress_id) : null;
        if ($transmision->estado === 'en_vivo' && in_array($egress, ['EGRESS_FAILED', 'EGRESS_ABORTED'], true)) {
            $transmision->fill(['estado' => 'error', 'error' => 'La salida a Facebook se cortó (' . $egress . ')'])->save();
        }
        return response()->json([
            'success' => true,
            'transmision' => $transmision->fresh()->paraApi(),
            'facebook' => ['status' => $fb['status'] ?? null, 'espectadores' => $fb['espectadores'] ?? null],
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
        if ($t->fb_live_id && $t->page) $this->facebook->terminar($t->page, $t->fb_live_id);
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
        ];
    }
}
