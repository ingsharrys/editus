<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\RecursoEnVivo;
use App\Models\TransmisionEnVivo;
use Illuminate\Support\Facades\Schema;
use App\Models\YoutubeCanal;
use App\Services\FacebookLiveService;
use App\Services\LiveKitClient;
use App\Services\YouTubeLiveService;
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
    public function __construct(private LiveKitClient $livekit, private FacebookLiveService $facebook, private YouTubeLiveService $youtube)
    {
    }

    /**
     * POST /api/en-vivo/preparar: crea la SALA (LiveKit) sin salir todavía a Facebook.
     * El equipo entra, invita cámaras, ajusta plantilla y nombres, y luego llama a /{id}/iniciar.
     */
    public function preparar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'page_id' => ['nullable', 'string'],
            'page_ids' => ['nullable', 'array', 'max:10'],
            'page_ids.*' => ['string'],
            'youtube_canal_ids' => ['nullable', 'array', 'max:5'],
            'youtube_canal_ids.*' => ['integer'],
            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'usuario' => ['nullable', 'string', 'max:60'],
            'usuario_nombre' => ['nullable', 'string', 'max:100'],
            'plantilla' => ['nullable', 'array'],
        ]);
        if (!$this->livekit->configurado()) {
            return response()->json(['success' => false, 'error' => 'LiveKit no está configurado en editus (LIVEKIT_URL, LIVEKIT_API_KEY, LIVEKIT_API_SECRET). Ver infra/en-vivo/README.md'], 422);
        }
        $ids = array_values(array_unique(array_filter(array_merge([(string) ($datos['page_id'] ?? '')], (array) ($datos['page_ids'] ?? [])))));
        $pages = MetaPage::whereIn('page_id', $ids)->get()->sortBy(fn($p) => array_search($p->page_id, $ids, true))->values();
        $canalIds = array_map('intval', (array) ($datos['youtube_canal_ids'] ?? []));
        $canales = Schema::hasTable('youtube_canales') ? YoutubeCanal::whereIn('id', $canalIds)->get() : collect();
        if ($pages->isEmpty() && $canales->isEmpty()) {
            return response()->json(['success' => false, 'error' => 'Elige al menos una página de Facebook o un canal de YouTube'], 422);
        }
        // Cada usuario de la app solo transmite a sus páginas / canales (o a los de la organización)
        $usuarioApp = trim((string) ($datos['usuario'] ?? '')) ?: null;
        $usuarioNombre = trim((string) ($datos['usuario_nombre'] ?? '')) ?: null;
        $cuentas = app(\App\Services\CuentasAppService::class);
        if (!$cuentas->puedeUsarPaginas($usuarioApp, $pages->pluck('page_id')->all(), $usuarioNombre) || !$cuentas->puedeUsarCanales($usuarioApp, $canales->pluck('id')->all(), $usuarioNombre)) {
            return response()->json(['success' => false, 'error' => 'Alguna de las páginas o canales elegidos no está conectada a tu cuenta. Revisa "Mis cuentas" en la app.'], 422);
        }
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp($usuarioApp);
        // La sala se nombra por la primera página (o el primer canal); meta_page_id es obligatorio en la tabla
        $page = $pages->first() ?: MetaPage::orderBy('id')->first();
        if (!$page) {
            return response()->json(['success' => false, 'error' => 'No hay páginas conectadas en editus'], 422);
        }
        $plantilla = self::normalizarPlantilla($datos['plantilla'] ?? [], $datos['titulo']);
        $room = 'envivo-' . $page->page_id . '-' . Str::lower(Str::random(6));
        $t = TransmisionEnVivo::create([
            'meta_page_id' => $page->id,
            'usuario_app' => $datos['usuario'] ?? null,
            'titulo' => $datos['titulo'],
            'descripcion' => $datos['descripcion'] ?? null,
            'room' => $room,
            'estado' => 'sala',
            'plantilla' => $plantilla,
            'escena' => self::escenaInicial(),
            'destinos' => array_merge(
                $pages->map(fn($p) => ['red' => 'facebook', 'meta_page_id' => $p->id, 'page_id' => (string) $p->page_id, 'pagina' => (string) $p->name, 'fb_live_id' => null, 'fb_video_id' => null,
                    'permalink' => null, 'stream_url' => null, 'estado' => 'pendiente', 'error' => null])->values()->all(),
                $canales->map(fn($c) => ['red' => 'youtube', 'canal_id' => $c->id, 'page_id' => 'yt:' . $c->channel_id, 'pagina' => (string) $c->titulo, 'fb_live_id' => null, 'fb_video_id' => null,
                    'permalink' => null, 'stream_url' => null, 'estado' => 'pendiente', 'error' => null])->values()->all(),
            ),
        ]);
        try {
            $this->livekit->crearSala($room, $t->metadataSala(), 900);
        } catch (\Throwable $e) {
            $t->fill(['estado' => 'error', 'error' => Str::limit($e->getMessage(), 500, '')])->save();
            return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 300, '')], 422);
        }
        return response()->json([
            'success' => true,
            'transmision' => $t->fresh()->paraApi(),
            'livekit' => ['url' => $this->livekit->wsUrl(), 'token' => $this->tokenCamara($t, 'camara-principal', 'Cámara principal')],
            'monitor' => $this->monitor($t),
        ]);
    }

    /**
     * POST /api/en-vivo/{id}/iniciar {intro_recurso_id?}: sale al aire. Crea un Live por página,
     * pone la intro (si la hay) y arranca el mezclador hacia todas las páginas.
     */
    public function salirAlAire(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp($transmision->usuario_app);
        $datos = $request->validate(['intro_recurso_id' => ['nullable', 'integer']]);
        if ($transmision->estado !== 'sala') {
            return response()->json(['success' => false, 'error' => 'La transmisión no está en la sala de espera (estado: ' . $transmision->estado . ')'], 422);
        }
        @set_time_limit(180);
        $t = $transmision;
        $room = $t->room;
        try {
            // 1) Un Live por destino: páginas de Facebook y canales de YouTube (cada uno da su URL RTMP secreta)
            $destinos = [];
            $errores = [];
            foreach ($t->destinosLista() as $d) {
                $red = $d['red'] ?? 'facebook';
                try {
                    if ($red === 'youtube') {
                        $c = YoutubeCanal::find($d['canal_id'] ?? 0);
                        if (!$c) continue;
                        $live = $this->youtube->crear($c, $t->titulo, (string) ($t->descripcion ?? ''));
                    } else {
                        $p = MetaPage::find($d['meta_page_id'] ?? 0);
                        if (!$p) continue;
                        $live = $this->facebook->crear($p, $t->titulo, (string) ($t->descripcion ?? ''));
                    }
                    $destinos[] = array_merge($d, ['red' => $red, 'fb_live_id' => $live['id'], 'fb_video_id' => $live['video_id'], 'permalink' => $live['permalink'], 'stream_url' => $live['stream_url'], 'estado' => 'ok', 'error' => null]);
                } catch (\Throwable $e) {
                    $errores[] = ($d['pagina'] ?? $red) . ': ' . $e->getMessage();
                    $destinos[] = array_merge($d, ['red' => $red, 'fb_live_id' => null, 'fb_video_id' => null, 'permalink' => null, 'stream_url' => null, 'estado' => 'error', 'error' => Str::limit($e->getMessage(), 300, '')]);
                }
            }
            $ok = array_values(array_filter($destinos, fn($d) => $d['estado'] === 'ok'));
            if (!$ok) throw new \RuntimeException(implode(' | ', $errores));
            $principalFb = collect($ok)->first(fn($d) => ($d['red'] ?? 'facebook') === 'facebook') ?: $ok[0];
            $t->fill(['destinos' => $destinos, 'fb_live_id' => $principalFb['fb_live_id'], 'stream_url' => $principalFb['stream_url'], 'fb_permalink' => $principalFb['permalink'], 'fb_video_id' => $principalFb['fb_video_id']])->save();
            if (!empty($principalFb['meta_page_id'])) $t->fill(['meta_page_id' => $principalFb['meta_page_id']])->save();

            // 2) Intro (video o imagen) al aire desde el primer segundo
            $escena = $t->escena ?? self::escenaInicial();
            unset($escena['recurso']); // lo que se probó en la sala no sale al aire
            if (!empty($datos['intro_recurso_id'])) {
                $intro = RecursoEnVivo::where('activo', true)->find((int) $datos['intro_recurso_id']);
                if ($intro) $escena['recurso'] = $intro->paraEscena();
            }
            $t->escena = $escena;
            $t->save();
            $this->livekit->actualizarMetadata($room, $t->metadataSala());

            // 3) Egress: la escena compuesta → RTMP de todas las páginas a la vez
            $egressId = $this->livekit->iniciarEgressRtmp($room, $this->escenaUrl(), array_map(fn($d) => $d['stream_url'], $ok));
            $t->fill(['egress_id' => $egressId, 'estado' => 'en_vivo', 'iniciada_en' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('[EN VIVO] no se pudo salir al aire', ['room' => $room, 'err' => $e->getMessage()]);
            // Se cierran los lives que alcanzaron a crearse; la sala sigue viva para reintentar
            $this->cerrarLives($t);
            $t->fill(['destinos' => array_map(fn($d) => $d + ['fb_live_id' => null, 'stream_url' => null, 'estado' => 'pendiente'], $t->destinosLista()), 'fb_live_id' => null, 'stream_url' => null, 'error' => Str::limit($e->getMessage(), 500, '')])->save();
            return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 300, '')], 422);
        }

        return response()->json(['success' => true, 'transmision' => $t->fresh()->paraApi()]);
    }

    /** POST /api/en-vivo/iniciar: sala + al aire en un solo paso (flujo antiguo de la app). */
    public function iniciar(Request $request): JsonResponse
    {
        $r = $this->preparar($request);
        $datos = $r->getData(true);
        if (empty($datos['success'])) return $r;
        $t = TransmisionEnVivo::find($datos['transmision']['id']);
        $aire = $this->salirAlAire(new Request(), $t);
        $resultado = $aire->getData(true);
        if (empty($resultado['success'])) {
            $this->limpiar($t);
            $t->fill(['estado' => 'error'])->save();
            return $aire;
        }
        return response()->json(['success' => true, 'transmision' => $t->fresh()->paraApi(), 'livekit' => $datos['livekit']]);
    }

    public static function escenaInicial(): array
    {
        return ['layout' => 'solo', 'principal' => 'camara-principal', 'visibles' => [], 'nombres' => (object) [], 'rotulo_de' => null, 'rotulo_auto' => false];
    }

    public function plantilla(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $datos = $request->validate(['plantilla' => ['required', 'array']]);
        $plantilla = self::normalizarPlantilla(array_merge($transmision->plantilla ?? [], $datos['plantilla']), $transmision->titulo);
        $transmision->plantilla = $plantilla;
        $transmision->save();
        if (in_array($transmision->estado, ['sala', 'en_vivo'], true)) {
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
            'formato' => ['nullable', 'string', 'in:completa,mitad'],
            'quitar_recurso' => ['nullable', 'boolean'],
            'nombres' => ['nullable', 'array', 'max:20'],
            'nombres.*.personas' => ['nullable', 'array', 'max:12'],
            'nombres.*.personas.*.nombre' => ['nullable', 'string', 'max:60'],
            'nombres.*.personas.*.cargo' => ['nullable', 'string', 'max:60'],
            'nombres.*.activa' => ['nullable', 'integer', 'min:0'],
            'nombres.*.nombre' => ['nullable', 'string', 'max:60'],
            'nombres.*.cargo' => ['nullable', 'string', 'max:60'],
            'rotulo_de' => ['nullable', 'string', 'max:90'],
            'quitar_rotulo' => ['nullable', 'boolean'],
            'rotulo_auto' => ['nullable', 'boolean'],
        ]);
        $escena = ($transmision->escena ?? []) + self::escenaInicial();
        // Personas por cámara (una cámara puede enfocar a varias; "activa" es la que está en cuadro)
        // y qué rótulo se muestra (uno fijo o el de quien habla)
        if (array_key_exists('nombres', $datos) && is_array($datos['nombres'])) {
            $nombres = [];
            foreach ($datos['nombres'] as $identity => $n) {
                $identity = preg_replace('/[^A-Za-z0-9_\-#]/', '', (string) $identity);
                if ($identity === '' || !is_array($n)) continue;
                $personas = [];
                $lista = isset($n['personas']) && is_array($n['personas']) ? $n['personas'] : [['nombre' => $n['nombre'] ?? '', 'cargo' => $n['cargo'] ?? '']];
                foreach ($lista as $p) {
                    if (!is_array($p)) continue;
                    $nombre = Str::limit(trim((string) ($p['nombre'] ?? '')), 60, '');
                    if ($nombre === '') continue;
                    $personas[] = ['nombre' => $nombre, 'cargo' => Str::limit(trim((string) ($p['cargo'] ?? '')), 60, '')];
                }
                if (!$personas) continue;
                $activa = max(0, min(count($personas) - 1, (int) ($n['activa'] ?? 0)));
                $nombres[$identity] = ['personas' => $personas, 'activa' => $activa];
            }
            $escena['nombres'] = (object) $nombres;
        }
        if (!empty($datos['quitar_rotulo'])) $escena['rotulo_de'] = null;
        elseif (array_key_exists('rotulo_de', $datos) && $datos['rotulo_de'] !== null && $datos['rotulo_de'] !== '') $escena['rotulo_de'] = (string) $datos['rotulo_de'];
        if (array_key_exists('rotulo_auto', $datos) && $datos['rotulo_auto'] !== null) $escena['rotulo_auto'] = (bool) $datos['rotulo_auto'];
        if (!empty($datos['layout'])) $escena['layout'] = $datos['layout'];
        if (array_key_exists('principal', $datos) && $datos['principal'] !== null && $datos['principal'] !== '') $escena['principal'] = $datos['principal'];
        if (array_key_exists('visibles', $datos) && is_array($datos['visibles'])) $escena['visibles'] = array_values(array_unique($datos['visibles']));
        // Recurso de producción (cortinilla, comercial, imagen) a pantalla completa
        if (!empty($datos['quitar_recurso'])) {
            unset($escena['recurso']);
        } elseif (!empty($datos['recurso_id'])) {
            $r = RecursoEnVivo::where('activo', true)->find((int) $datos['recurso_id']);
            if (!$r) return response()->json(['success' => false, 'error' => 'Recurso no encontrado'], 422);
            $escena['recurso'] = $r->paraEscena($datos['formato'] ?? 'completa');
        }
        $transmision->escena = $escena;
        $transmision->save();
        if (in_array($transmision->estado, ['sala', 'en_vivo'], true)) {
            try {
                $this->livekit->actualizarMetadata($transmision->room, $transmision->metadataSala());
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => Str::limit($e->getMessage(), 200, '')], 422);
            }
        }
        return response()->json(['success' => true, 'escena' => $transmision->fresh()->escena]);
    }

    /** GET /api/en-vivo/recursos: cortinillas, comerciales e imágenes disponibles para sacar al aire. */
    public function recursos(): JsonResponse
    {
        $lista = Schema::hasTable('recursos_en_vivo') ? RecursoEnVivo::where('activo', true)->orderBy('orden')->orderBy('id')->get()->map(fn($r) => $r->paraApi())->values() : collect();
        return response()->json(['success' => true, 'recursos' => $lista]);
    }

    /** POST /api/en-vivo/recursos/{id}/borrar */
    public function recursoBorrar(RecursoEnVivo $recurso): JsonResponse
    {
        \Illuminate\Support\Facades\Storage::disk('public')->delete($recurso->archivo);
        $recurso->delete();
        return response()->json(['success' => true]);
    }

    /** POST /api/en-vivo/{id}/invitacion {nombre?}: enlace para que alguien envíe su cámara desde el navegador. */
    public function invitacion(Request $request, TransmisionEnVivo $transmision): JsonResponse
    {
        $datos = $request->validate(['nombre' => ['nullable', 'string', 'max:60'], 'modo' => ['nullable', 'string', 'in:camara,pantalla']]);
        $codigo = Str::lower(Str::random(10));
        $modo = $datos['modo'] ?? 'camara';
        $lista = $transmision->invitaciones ?? [];
        $lista[] = ['codigo' => $codigo, 'nombre' => $datos['nombre'] ?? null, 'modo' => $modo, 'creada_en' => now()->toIso8601String()];
        $transmision->invitaciones = $lista;
        $transmision->save();
        return response()->json(['success' => true, 'codigo' => $codigo, 'modo' => $modo, 'url' => route('en-vivo.invitado', $codigo)]);
    }

    /** GET /api/en-vivo/{id}/participantes: cámaras conectadas a la sala. */
    public function participantes(TransmisionEnVivo $transmision): JsonResponse
    {
        try {
            $lista = in_array($transmision->estado, ['sala', 'en_vivo'], true) ? $this->livekit->participantes($transmision->room) : [];
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
        return view('en-vivo.invitado', ['transmision' => $t, 'invitacion' => $inv, 'codigo' => $codigo, 'activa' => in_array($t->estado, ['sala', 'en_vivo'], true), 'modo' => $inv['modo'] ?? 'camara']);
    }

    /** Token de LiveKit para el invitado (lo pide la página con su código). */
    public function invitadoToken(Request $request, string $codigo): JsonResponse
    {
        [$t, $inv] = $this->buscarInvitacion($codigo);
        if (!$t) return response()->json(['success' => false, 'error' => 'Invitación no válida'], 404);
        if (!in_array($t->estado, ['sala', 'en_vivo'], true)) return response()->json(['success' => false, 'error' => 'La transmisión no está en vivo en este momento'], 422);
        $nombre = Str::limit(trim((string) $request->input('nombre', $inv['nombre'] ?? (($inv['modo'] ?? '') === 'pantalla' ? 'Pantalla' : 'Invitado'))), 40, '') ?: 'Invitado';
        $identity = 'invitado-' . $codigo;
        // Límite de cámaras del plan de quien transmite (la cámara principal cuenta)
        $max = app(\App\Services\PlanService::class)->camarasDe($t);
        if ($max !== null) {
            try { $conectados = collect($this->livekit->participantes($t->room))->pluck('identity'); } catch (\Throwable) { $conectados = collect(); }
            if (!$conectados->contains($identity) && $conectados->count() >= $max) {
                return response()->json(['success' => false, 'error' => "Esta transmisión ya tiene el máximo de cámaras de su plan ({$max})."], 422);
            }
        }
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
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp($transmision->usuario_app);
        $this->limpiar($transmision);
        if ($transmision->estado !== 'error') {
            $transmision->fill(['estado' => 'terminada', 'terminada_en' => now()])->save();
        }
        // Id del video resultante en cada página (para la web y las métricas)
        $destinos = $transmision->destinosLista();
        foreach ($destinos as $i => $d) {
            if (empty($d['fb_live_id']) || !empty($d['fb_video_id'])) continue;
            $st = $this->estadoDestino($d);
            if (!empty($st['video_id'])) { $destinos[$i]['fb_video_id'] = $st['video_id']; $destinos[$i]['permalink'] = $st['permalink'] ?? $d['permalink']; }
        }
        $principal = collect($destinos)->first(fn($d) => ($d['estado'] ?? '') === 'ok' && ($d['red'] ?? 'facebook') === 'facebook') ?: collect($destinos)->first(fn($d) => ($d['estado'] ?? '') === 'ok');
        $transmision->fill(['destinos' => $destinos, 'fb_video_id' => $principal['fb_video_id'] ?? $transmision->fb_video_id, 'fb_permalink' => $principal['permalink'] ?? $transmision->fb_permalink])->save();
        return response()->json(['success' => true, 'transmision' => $transmision->fresh()->paraApi()]);
    }

    public function estado(TransmisionEnVivo $transmision): JsonResponse
    {
        \App\Services\MetaPageTokenResolver::preferirUsuarioApp($transmision->usuario_app);
        // Espectadores: suma de todas las páginas
        $porPagina = [];
        $total = null;
        $fb = [];
        foreach ($transmision->destinosLista() as $d) {
            if (empty($d['fb_live_id'])) continue;
            $st = $this->estadoDestino($d);
            if (!$fb) $fb = $st;
            $porPagina[] = ['red' => $d['red'] ?? 'facebook', 'pagina' => $d['pagina'] ?? '', 'page_id' => $d['page_id'] ?? '', 'status' => $st['status'] ?? null, 'espectadores' => $st['espectadores'] ?? null, 'permalink' => $d['permalink'] ?? null];
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
            'livekit' => in_array($transmision->estado, ['sala', 'en_vivo'], true)
                ? ['url' => $this->livekit->wsUrl(), 'token' => $this->tokenCamara($transmision, 'camara-principal', 'Cámara principal')]
                : null,
            'monitor' => in_array($transmision->estado, ['sala', 'en_vivo'], true) ? $this->monitor($transmision) : null,
        ]);
    }

    public function activas(Request $request): JsonResponse
    {
        $q = TransmisionEnVivo::with('page')->whereIn('estado', ['sala', 'en_vivo'])->orderByDesc('id');
        if ($request->filled('usuario')) $q->where('usuario_app', (string) $request->query('usuario'));
        return response()->json(['success' => true, 'transmisiones' => $q->get()->map(fn($t) => $t->paraApi())->values()]);
    }

    // ------------------------------------------------------------------

    private function tokenCamara(TransmisionEnVivo $t, string $identity, string $nombre): string
    {
        return $this->livekit->tokenParticipante($t->room, $identity, $nombre, true);
    }

    /** Monitor de programa: la misma escena que ve Facebook, abierta en la app (solo mira, oculto para los demás). */
    private function monitor(TransmisionEnVivo $t): array
    {
        $identity = 'monitor-' . Str::lower(Str::random(6));
        $token = $this->livekit->tokenParticipante($t->room, $identity, 'Monitor', false, 6 * 3600, ['hidden' => true]);
        $url = $this->escenaUrl() . (str_contains($this->escenaUrl(), '?') ? '&' : '?') . http_build_query(['url' => $this->livekit->wsUrl(), 'token' => $token, 'layout' => 'escena', 'monitor' => 1]);
        return ['url' => $url];
    }

    private function escenaUrl(): string
    {
        $url = (string) config('services.livekit.escena_url');
        return $url !== '' ? $url : route('en-vivo.escena');
    }

    private function limpiar(TransmisionEnVivo $t): void
    {
        if ($t->egress_id) $this->livekit->detenerEgress($t->egress_id);
        $this->cerrarLives($t);
        if ($t->room) $this->livekit->borrarSala($t->room);
    }

    /** Cierra el Live de cada destino (Facebook o YouTube) que alcanzó a crearse. */
    private function cerrarLives(TransmisionEnVivo $t): void
    {
        foreach ($t->destinosLista() as $d) {
            if (empty($d['fb_live_id'])) continue;
            if (($d['red'] ?? 'facebook') === 'youtube') {
                $c = YoutubeCanal::find($d['canal_id'] ?? 0);
                if ($c) $this->youtube->terminar($c, $d['fb_live_id']);
            } else {
                $p = MetaPage::find($d['meta_page_id'] ?? 0);
                if ($p) $this->facebook->terminar($p, $d['fb_live_id']);
            }
        }
    }

    /** Estado (espectadores, video resultante) de un destino según su red. */
    private function estadoDestino(array $d): array
    {
        if (($d['red'] ?? 'facebook') === 'youtube') {
            $c = YoutubeCanal::find($d['canal_id'] ?? 0);
            return $c ? $this->youtube->estado($c, $d['fb_live_id']) : [];
        }
        $p = MetaPage::find($d['meta_page_id'] ?? 0);
        return $p ? $this->facebook->estado($p, $d['fb_live_id']) : [];
    }

    /** GET /api/en-vivo/youtube: canales de YouTube disponibles para la app. */
    public function youtubeCanales(Request $request): JsonResponse
    {
        // Con ?usuario=: los canales que ese usuario conectó desde la app + los de la organización visibles
        $usuario = trim((string) $request->query('usuario', '')) ?: null;
        $nombre = trim((string) $request->query('usuario_nombre', '')) ?: null;
        $lista = app(\App\Services\CuentasAppService::class)->canalesDe($usuario, $nombre)->map(fn($c) => $c->paraApi($usuario))->values();
        return response()->json(['success' => true, 'canales' => $lista, 'configurado' => (string) config('services.google.client_id') !== '']);
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
            // Marco: PNG con transparencia (1920×1080) que va sobre el video
            'marco_url' => preg_match('#^https?://#i', trim((string) ($p['marco_url'] ?? ''))) ? Str::limit(trim((string) $p['marco_url']), 500, '') : '',
        ];
    }
}
