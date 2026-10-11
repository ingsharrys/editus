<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use App\Models\YoutubeCanal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * En vivo PROGRAMADO con cámaras (como StreamYard, con dos mejoras):
 *   1. Se programa: título, páginas / canales, fecha y hora, plantilla.
 *   2. Hasta 7 días antes se anuncia: en vivo programado en Facebook ("Recordarme") y
 *      transmisión "próxima" en YouTube. El enlace de invitados sirve desde ya.
 *   3. 10 minutos antes se avisa a quien la programó (correo y aviso en la web).
 *   4. A la hora se abre el estudio y "Salir al aire" usa el MISMO en vivo anunciado.
 *   5. Si nadie sale al aire en 30 minutos, se quita el anuncio (no queda un en vivo fantasma).
 */
class EnVivoProgramadoService
{
    public const DIAS_ANUNCIO = 7;
    public const MINUTOS_AVISO = 10;
    public const TOLERANCIA_MIN = 30;

    public function __construct(private FacebookLiveService $facebook, private YouTubeLiveService $youtube)
    {
    }

    /** Crea la transmisión programada (sin sala todavía) y la anuncia si ya está dentro de los 7 días. */
    public function programar(int $userId, array $d, $paginas, $canales, array $plantilla): TransmisionEnVivo
    {
        $principal = $paginas->first() ?: MetaPage::orderBy('id')->first();
        if (!$principal) throw new \RuntimeException('No hay páginas conectadas en editus');
        $t = TransmisionEnVivo::create([
            'meta_page_id' => $principal->id, 'user_id' => $userId, 'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'] ?? null,
            'room' => 'envivo-' . $principal->page_id . '-' . Str::lower(Str::random(6)),
            'estado' => 'programada', 'programada_en' => $d['programada_en'],
            'plantilla' => $plantilla, 'escena' => \App\Http\Controllers\Api\EnVivoController::escenaInicial(),
            'invitaciones' => [['codigo' => Str::lower(Str::random(10)), 'nombre' => null, 'modo' => 'camara', 'creada_en' => now()->toIso8601String()]],
            'destinos' => array_merge(
                $paginas->map(fn($p) => ['red' => 'facebook', 'meta_page_id' => $p->id, 'page_id' => (string) $p->page_id, 'pagina' => (string) $p->name, 'fb_live_id' => null, 'fb_video_id' => null,
                    'permalink' => null, 'stream_url' => null, 'estado' => 'pendiente', 'error' => null])->values()->all(),
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
                    } else {
                        $p = MetaPage::find($d['meta_page_id'] ?? 0);
                        if (!$p) throw new \RuntimeException('La página ya no existe en editus');
                        $r = $this->facebook->crear($p, $t->titulo, (string) $t->descripcion, $t->programada_en);
                    }
                    $d = array_merge($d, ['fb_live_id' => $r['id'], 'fb_video_id' => $r['video_id'] ?? null, 'permalink' => $r['permalink'] ?? null, 'stream_url' => $r['stream_url'] ?? null,
                        'programado' => true, 'estado' => 'anunciado', 'error' => null]);
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

    /** Un paso de la tarea de cada minuto. */
    public function tick(): array
    {
        $log = [];
        $ahora = now();
        // Anunciar las que entran en la ventana de 7 días
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

    /** Correo a quien la programó (si el correo está configurado); la web muestra el aviso por su cuenta. */
    private function avisar(TransmisionEnVivo $t): void
    {
        $t->fill(['avisada_en' => now()])->save();
        $user = $t->user_id ? User::find($t->user_id) : null;
        if (!$user?->email || in_array(config('mail.default'), ['log', 'array', null], true)) return;
        $hora = $t->programada_en->translatedFormat('g:i a');
        $invitado = collect($t->invitaciones ?? [])->first();
        $texto = "Hola {$user->name}:\n\nTu en vivo «{$t->titulo}» empieza a las {$hora}.\n\n"
            . "Abre el estudio y sal al aire: " . route('en-vivo.web') . "\n"
            . ($invitado ? 'Enlace para tus invitados: ' . route('en-vivo.invitado', $invitado['codigo']) . "\n" : '')
            . "\nSi nadie sale al aire en los " . self::TOLERANCIA_MIN . " minutos siguientes, editus quita el anuncio de Facebook y YouTube.\n\neditus";
        try {
            Mail::raw($texto, fn($m) => $m->to($user->email)->subject("En {$this->minutos($t)} min: {$t->titulo}"));
        } catch (\Throwable $e) {
            Log::info('[en vivo programado] correo', ['id' => $t->id, 'err' => $e->getMessage()]);
        }
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
