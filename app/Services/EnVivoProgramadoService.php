<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use App\Models\YoutubeCanal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * En vivo PROGRAMADO con cámaras (como StreamYard, con dos mejoras):
 *   1. Se programa: título, páginas / canales, fecha y hora, plantilla.
 *   2. Al programar se anuncia: publicación en la página de Facebook (Meta eliminó los en
 *      vivos programados de la API) y transmisión "próxima" en YouTube. El enlace de
 *      invitados sirve desde ya.
 *   3. 10 minutos antes se avisa a quien la programó (correo y aviso en la web).
 *   4. A la hora se abre el estudio y "Salir al aire": en YouTube usa la MISMA transmisión
 *      anunciada; en Facebook crea el en vivo en ese momento.
 *   5. Si nadie sale al aire en 30 minutos, se quita el anuncio (no queda un en vivo fantasma).
 */
class EnVivoProgramadoService
{
    public const DIAS_ANUNCIO = 365; // el anuncio se publica apenas se programa
    public const MINUTOS_AVISO = 10;
    public const TOLERANCIA_MIN = 30;

    public function __construct(private FacebookLiveService $facebook, private YouTubeLiveService $youtube, private NotificadorService $avisos)
    {
    }

    /** Crea la transmisión programada (sin sala todavía) y publica sus anuncios. */
    public function programar(?int $userId, array $d, $paginas, $canales, array $plantilla): TransmisionEnVivo
    {
        $principal = $paginas->first() ?: MetaPage::orderBy('id')->first();
        if (!$principal) throw new \RuntimeException('No hay páginas conectadas en editus');
        $t = TransmisionEnVivo::create([
            'meta_page_id' => $principal->id, 'user_id' => $userId, 'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'] ?? null,
            'room' => 'envivo-' . $principal->page_id . '-' . Str::lower(Str::random(6)),
            'estado' => 'programada', 'programada_en' => $d['programada_en'], 'miniatura' => $d['miniatura'] ?? null, 'usuario_app' => $d['usuario_app'] ?? null,
            'plantilla' => $plantilla, 'escena' => \App\Http\Controllers\Api\EnVivoController::escenaInicial(),
            'invitaciones' => [['codigo' => Str::lower(Str::random(10)), 'nombre' => null, 'modo' => 'camara', 'creada_en' => now()->toIso8601String()]],
            'destinos' => array_merge(
                $paginas->map(fn($p) => ['red' => 'facebook', 'meta_page_id' => $p->id, 'page_id' => (string) $p->page_id, 'pagina' => (string) $p->name, 'fb_live_id' => null, 'fb_video_id' => null,
                    'permalink' => null, 'stream_url' => null, 'estado' => 'pendiente', 'error' => null, 'anunciar' => (bool) ($d['anuncio_facebook'] ?? true)])->values()->all(),
                $canales->map(fn($c) => ['red' => 'youtube', 'canal_id' => $c->id, 'page_id' => 'yt:' . $c->channel_id, 'pagina' => (string) $c->titulo, 'fb_live_id' => null, 'fb_video_id' => null,
                    'permalink' => null, 'stream_url' => null, 'estado' => 'pendiente', 'error' => null])->values()->all(),
            ),
        ]);
        if ($t->programada_en->lte(now()->addDays(self::DIAS_ANUNCIO)->subMinutes(5))) $this->anunciar($t);
        return $t->refresh();
    }

    /** Publica el anuncio en cada destino. Si alguno falla, a la hora se crea en directo como siempre. */
    public function anunciar(TransmisionEnVivo $t): string
    {
        return $this->conCandado($t, function (TransmisionEnVivo $t) {
            if ($t->estado !== 'programada' || $t->anunciada_en) return 'ya anunciada';
            $this->usarTokensDe($t);
            $destinos = $t->destinosLista();
            $ok = 0;
            foreach ($destinos as &$d) {
                if (($d['estado'] ?? '') !== 'pendiente') continue;
                try {
                    if (($d['red'] ?? 'facebook') === 'youtube') {
                        $c = YoutubeCanal::find($d['canal_id'] ?? 0);
                        if (!$c) throw new \RuntimeException('El canal ya no existe en editus');
                        $r = $this->youtube->crear($c, $t->titulo, (string) $t->descripcion, 'public', $t->programada_en, false);
                        $notaMiniatura = $this->miniaturaYoutube($c, $r['id'], $t->miniatura);
                    } else {
                        // Facebook ya no deja programar en vivos por la API: se publica un anuncio en la
                        // página y el en vivo se crea a la hora, al salir al aire
                        if (array_key_exists('anunciar', $d) && !$d['anunciar']) continue;
                        $p = MetaPage::find($d['meta_page_id'] ?? 0);
                        if (!$p) throw new \RuntimeException('La página ya no existe en editus');
                        $r = $this->facebook->publicarAnuncio($p, FacebookLiveService::textoAnuncio($t->titulo, $t->descripcion, $t->programada_en), MiniaturaService::archivo($t->miniatura));
                        $d = array_merge($d, ['anuncio_post_id' => $r['id'], 'anuncio_permalink' => $r['permalink'] ?? null, 'error' => null]);
                        $ok++;
                        continue;
                    }
                    $d = array_merge($d, ['fb_live_id' => $r['id'], 'fb_video_id' => $r['video_id'] ?? null, 'permalink' => $r['permalink'] ?? null, 'stream_url' => $r['stream_url'] ?? null,
                        'programado' => true, 'estado' => 'anunciado', 'error' => $notaMiniatura]);
                    $ok++;
                } catch (\Throwable $e) {
                    $d['error'] = 'Anuncio: ' . Str::limit($e->getMessage(), 300, '');
                    Log::info('[en vivo programado] anuncio', ['id' => $t->id, 'red' => $d['red'] ?? '', 'err' => $e->getMessage()]);
                }
            }
            unset($d);
            $t->fill(['destinos' => $destinos, 'anunciada_en' => now()])->save();
            return "anunciada en {$ok} de " . count($destinos) . ' destino(s)';
        });
    }

    /** Sube la miniatura a YouTube; si no la acepta (canal sin verificar) el anuncio sigue y queda la nota. */
    public function miniaturaYoutube(YoutubeCanal $c, string $broadcastId, ?string $miniatura): ?string
    {
        $archivo = MiniaturaService::archivo($miniatura);
        if (!$archivo) return null;
        try {
            $this->youtube->miniatura($c, $broadcastId, $archivo);
            return null;
        } catch (\Throwable $e) {
            return 'Miniatura: ' . Str::limit($e->getMessage(), 200, '');
        }
    }

    /** Un paso de la tarea de cada minuto. */
    public function tick(): array
    {
        $log = [];
        $ahora = now();
        // Anunciar las que aún no tienen anuncio (se reintenta una vez por minuto si la primera vez falló del todo)
        foreach (TransmisionEnVivo::where('estado', 'programada')->whereNull('anunciada_en')
            ->where('programada_en', '<=', $ahora->copy()->addDays(self::DIAS_ANUNCIO)->subMinutes(5))
            ->where('programada_en', '>', $ahora->copy()->addMinutes(5))->limit(10)->get() as $t) {
            $log[] = "transmisión #{$t->id} " . $this->anunciar($t);
        }
        // Avisar 10 minutos antes (correo; la web lo muestra sola)
        foreach (TransmisionEnVivo::whereIn('estado', ['programada', 'sala'])->whereNull('avisada_en')->whereNotNull('programada_en')
            ->where('programada_en', '<=', $ahora->copy()->addMinutes(self::MINUTOS_AVISO))->where('programada_en', '>', $ahora->copy()->subMinutes(self::TOLERANCIA_MIN))->get() as $t) {
            $this->avisar($t);
            $log[] = "transmisión #{$t->id} aviso enviado";
        }
        // Nadie salió al aire: se quita el anuncio para que no quede un en vivo fantasma
        foreach (TransmisionEnVivo::whereIn('estado', ['programada', 'sala'])->whereNotNull('programada_en')
            ->where('programada_en', '<', $ahora->copy()->subMinutes(self::TOLERANCIA_MIN))->get() as $t) {
            $this->cancelar($t, 'Nadie salió al aire en los ' . self::TOLERANCIA_MIN . ' minutos siguientes a la hora: se quitó el anuncio de Facebook y YouTube.');
            $this->avisos->avisar($t->user_id ? (int) $t->user_id : null, $t->usuario_app, "Se canceló: {$t->titulo}",
                'Nadie salió al aire a tiempo, así que se quitó el anuncio de Facebook y YouTube.', ['texto' => 'Ir a En vivo', 'url' => route('en-vivo.web')], ['pantalla' => 'en-vivo']);
            $log[] = "transmisión #{$t->id} vencida y cancelada";
        }
        return $log;
    }

    /** Cancela: quita los anuncios (Facebook y YouTube) y cierra la sala si estaba abierta. */
    public function cancelar(TransmisionEnVivo $t, ?string $motivo = null): void
    {
        $this->conCandado($t, function (TransmisionEnVivo $t) use ($motivo) {
            if (!in_array($t->estado, ['programada', 'sala'], true) || !$t->programada_en) return;
            $this->usarTokensDe($t);
            $destinos = $t->destinosLista();
            foreach ($destinos as &$d) {
                if (!empty($d['anuncio_post_id']) && ($p = MetaPage::find($d['meta_page_id'] ?? 0))) {
                    $this->facebook->borrarAnuncio($p, $d['anuncio_post_id']);
                    $d['anuncio_post_id'] = null; $d['anuncio_permalink'] = null;
                    if (($d['estado'] ?? '') === 'pendiente') $d['estado'] = 'cancelado';
                }
                if (empty($d['programado']) || empty($d['fb_live_id'])) continue;
                try {
                    if (($d['red'] ?? 'facebook') === 'youtube' && ($c = YoutubeCanal::find($d['canal_id'] ?? 0))) $this->youtube->borrar($c, $d['fb_live_id']);
                    if (($d['red'] ?? 'facebook') === 'facebook' && ($p = MetaPage::find($d['meta_page_id'] ?? 0))) $this->facebook->cancelarProgramado($p, $d['fb_live_id']);
                } catch (\Throwable) {
                }
                $d['estado'] = 'cancelado'; $d['stream_url'] = null;
            }
            unset($d);
            if ($t->estado === 'sala' && $t->room) {
                try { app(LiveKitClient::class)->borrarSala($t->room); } catch (\Throwable) {}
            }
            $t->fill(['destinos' => $destinos, 'estado' => 'cancelada', 'error' => $motivo, 'stream_url' => null, 'terminada_en' => now()])->save();
        });
    }

    /** Aviso 10 minutos antes: notificación al celular (app) y correo (web) a quien la programó. */
    private function avisar(TransmisionEnVivo $t): void
    {
        $t->fill(['avisada_en' => now()])->save();
        $invitado = collect($t->invitaciones ?? [])->first();
        $this->avisos->avisar($t->user_id ? (int) $t->user_id : null, $t->usuario_app,
            "En {$this->minutos($t)} min: {$t->titulo}",
            'Tu en vivo empieza a las ' . $t->programada_en->translatedFormat('g:i a') . '. Abre el estudio, conecta las cámaras y sal al aire.',
            ['texto' => 'Abrir el estudio', 'url' => route('en-vivo.web')],
            ['pantalla' => 'en-vivo', 'transmision_id' => $t->id],
            array_filter([
                $invitado ? 'Enlace para tus invitados: ' . route('en-vivo.invitado', $invitado['codigo']) : null,
                'Si nadie sale al aire en los ' . self::TOLERANCIA_MIN . ' minutos siguientes, editus quita el anuncio de Facebook y YouTube.',
            ]));
    }

    private function minutos(TransmisionEnVivo $t): int
    {
        return max(1, (int) ceil(now()->diffInMinutes($t->programada_en, false)));
    }

    private function usarTokensDe(TransmisionEnVivo $t): void
    {
        MetaPageTokenResolver::preferirUsuarioApp($t->usuario_app);
        MetaPageTokenResolver::preferirUsuarioWeb($t->user_id ? (int) $t->user_id : null);
    }

    private function conCandado(TransmisionEnVivo $t, callable $fn)
    {
        $candado = Cache::lock('transmision.' . $t->id, 60);
        if (!$candado->block(15)) return 'ocupada';
        try {
            return $fn($t->refresh());
        } finally {
            $candado->release();
        }
    }
}
