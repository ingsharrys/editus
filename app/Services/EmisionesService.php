<?php

namespace App\Services;

use App\Models\EmisionProgramada;
use App\Models\MetaPage;
use App\Models\YoutubeCanal;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Videos pregrabados que salen en vivo a una hora programada.
 *
 *   subiendo   el navegador sube el video por partes directo al emisor del VPS
 *   procesando el emisor lo prepara (formato de transmisión, logo, marco, cuenta regresiva)
 *   listo      programado: hasta 7 días antes se crea el en vivo programado en Facebook
 *              (anuncio con "Recordarme") y la transmisión "próxima" en YouTube
 *   al_aire    un minuto antes el emisor envía la cuenta regresiva y, a la hora, el video
 *   terminado  al acabar el video se cierran los en vivo; el archivo se borra días después
 *
 * La tarea editus:emisiones (cada minuto) mueve los estados; los avisos del emisor
 * (/webhooks/emisor) los adelantan sin esperar al siguiente minuto.
 */
class EmisionesService
{
    public const DIAS_ANUNCIO = 7;        // Facebook acepta en vivos programados hasta 7 días antes
    public const SEGUNDOS_ARRANQUE = 75;  // se avisa al emisor en el minuto anterior; él espera y cuadra la cuenta regresiva
    public const TOLERANCIA_MIN = 30;     // si por algo no arrancó a tiempo, se intenta hasta 30 min después

    public function __construct(private EmisorClient $emisor, private FacebookLiveService $facebook, private YouTubeLiveService $youtube, private PlanService $planes)
    {
    }

    // ------------------------------------------------------------ creación

    /** Crea la programación (estado subiendo) con sus destinos. */
    public function crear(int $userId, array $d, $paginas, $canales): EmisionProgramada
    {
        $destinos = [];
        foreach ($paginas as $p) $destinos[] = ['red' => 'facebook', 'meta_page_id' => $p->id, 'nombre' => (string) $p->name, 'live_id' => null, 'stream_url' => null, 'permalink' => null, 'estado' => 'pendiente', 'error' => null];
        foreach ($canales as $c) $destinos[] = ['red' => 'youtube', 'canal_id' => $c->id, 'nombre' => (string) $c->titulo, 'live_id' => null, 'stream_url' => null, 'permalink' => null, 'estado' => 'pendiente', 'error' => null];
        return EmisionProgramada::create([
            'user_id' => $userId, 'emisor_id' => 'em-' . Str::lower(Str::random(24)), 'titulo' => $d['titulo'], 'descripcion' => $d['descripcion'] ?? null,
            'programada_en' => $d['programada_en'], 'estado' => 'subiendo', 'progreso' => 0,
            'archivo_nombre' => Str::limit((string) ($d['archivo_nombre'] ?? ''), 250, ''), 'archivo_bytes' => (int) ($d['archivo_bytes'] ?? 0),
            'plantilla' => $d['plantilla'] ?? [], 'destinos' => $destinos,
        ]);
    }

    /** El video terminó de subir: el emisor lo prepara con la plantilla y el límite de duración del plan. */
    public function procesar(EmisionProgramada $e): void
    {
        if ($e->estado !== 'subiendo') return;
        $p = (array) $e->plantilla;
        $lim = $this->planes->limites($e->user);
        $horas = (float) ($lim['pregrabado_horas'] ?? 2);
        $cuenta = !empty($p['cuenta']) ? (int) config('services.emisor.cuenta_regresiva', 60) : 0;
        $this->emisor->procesar($e->emisor_id, [
            'alto' => ($p['calidad'] ?? '') === '720' ? 720 : 1080,
            'max_segundos' => (int) round($horas * 3600),
            'marco_url' => $p['marco_url'] ?? null, 'logo_url' => $p['logo_url'] ?? null,
            'etiqueta' => !empty($p['etiqueta']) ? 'PREGRABADO' : null,
            'cuenta' => ['segundos' => $cuenta, 'titulo' => $e->titulo, 'subtitulo' => 'Comenzamos en', 'color' => $p['color'] ?? '#00024f'],
        ]);
        $e->fill(['estado' => 'procesando', 'progreso' => 0, 'error' => null])->save();
    }

    // ------------------------------------------------------------- tarea

    /** Un paso de la tarea programada. Devuelve líneas para el registro. */
    public function tick(): array
    {
        $log = [];
        $ahora = now();

        // 1) Sincronizar con el emisor lo que está en curso (por si se perdió algún aviso)
        foreach (EmisionProgramada::whereIn('estado', ['subiendo', 'procesando', 'al_aire'])->orderBy('programada_en')->limit(30)->get() as $e) {
            if ($e->estado === 'subiendo' && $e->updated_at->gt($ahora->copy()->subMinutes(2))) continue; // subida activa: lo informa el navegador
            try {
                $v = $this->emisor->estado($e->emisor_id);
                if ($v) $this->aplicar($e, $v);
            } catch (\Throwable $ex) {
                $log[] = "#{$e->id} sin respuesta del emisor: " . $ex->getMessage();
            }
        }

        // 2) Arrancar las que llegan a su hora (cuenta regresiva en el minuto anterior)
        $listas = EmisionProgramada::where('estado', 'listo')
            ->where('programada_en', '<=', $ahora->copy()->addSeconds(self::SEGUNDOS_ARRANQUE))
            ->where('programada_en', '>=', $ahora->copy()->subMinutes(self::TOLERANCIA_MIN))->get();
        foreach ($listas as $e) $log[] = "#{$e->id} " . $this->arrancar($e);

        // 3) Anunciar en Facebook / YouTube las que ya están dentro de la ventana de 7 días
        $porAnunciar = EmisionProgramada::where('estado', 'listo')->whereNull('anunciada_en')
            ->where('programada_en', '<=', $ahora->copy()->addDays(self::DIAS_ANUNCIO)->subMinutes(5))
            ->where('programada_en', '>', $ahora->copy()->addMinutes(5))->limit(10)->get();
        foreach ($porAnunciar as $e) $log[] = "#{$e->id} " . $this->anunciar($e);

        // 4) Vencidas: no estaban listas a tiempo
        foreach (EmisionProgramada::whereIn('estado', ['subiendo', 'procesando', 'listo'])->where('programada_en', '<', $ahora->copy()->subMinutes(self::TOLERANCIA_MIN))->get() as $e) {
            $motivo = match ($e->estado) {
                'subiendo' => 'El video no terminó de subirse antes de la hora programada.',
                'procesando' => 'El video no terminó de prepararse antes de la hora programada. Prográmalo con más margen.',
                default => 'No se pudo salir al aire a la hora programada.',
            };
            $this->cancelarAnuncios($e);
            $e->fill(['estado' => 'error', 'error' => $motivo])->save();
            $log[] = "#{$e->id} vencida: {$motivo}";
        }

        // 5) Subidas abandonadas (más de un día sin terminar)
        foreach (EmisionProgramada::where('estado', 'subiendo')->where('updated_at', '<', $ahora->copy()->subDay())->get() as $e) {
            $this->borrarArchivo($e);
            $e->fill(['estado' => 'cancelado', 'error' => 'La subida no se terminó.'])->save();
        }

        // 6) Limpieza del VPS: el archivo se borra días después de emitido (o de cancelado / con error)
        $dias = max(0, (int) config('services.emisor.dias_borrado', 7));
        foreach (EmisionProgramada::whereIn('estado', ['terminado', 'error', 'cancelado'])->whereNull('archivo_borrado_en')->where('updated_at', '<', $ahora->copy()->subDays($dias))->limit(20)->get() as $e) {
            if ($this->borrarArchivo($e)) $log[] = "#{$e->id} archivo borrado del VPS";
        }
        return $log;
    }

    // ------------------------------------------------------- transiciones

    /** Aplica el estado que informa el emisor (por aviso o por consulta). */
    public function aplicar(EmisionProgramada $e, array $v, ?string $evento = null): void
    {
        $this->conCandado($e, function (EmisionProgramada $e) use ($v) {
            $estado = (string) ($v['estado'] ?? '');
            switch ($estado) {
                case 'subiendo':
                    if ($e->estado === 'subiendo' && ($v['total'] ?? 0) > 0) $e->progreso = (int) min(99, floor(100 * ($v['recibido'] ?? 0) / $v['total']));
                    $e->save();
                    break;
                case 'subido':
                    if ($e->estado === 'subiendo') $this->procesar($e);
                    break;
                case 'en_cola':
                case 'procesando':
                    if (in_array($e->estado, ['subiendo', 'procesando'], true)) $e->fill(['estado' => 'procesando', 'progreso' => (int) ($v['progreso'] ?? 0)])->save();
                    break;
                case 'listo':
                    if (in_array($e->estado, ['subiendo', 'procesando'], true)) $e->fill(['estado' => 'listo', 'progreso' => 100, 'duracion' => $v['duracion'] ?? null, 'error' => null])->save();
                    break;
                case 'emitiendo':
                    if ($e->estado === 'al_aire') $this->copiarDestinos($e, $v);
                    break;
                case 'emitido':
                    if ($e->estado === 'al_aire') { $this->copiarDestinos($e, $v); $this->finalizar($e, $v['error'] ?? null, true); }
                    break;
                case 'error':
                    if (in_array($e->estado, ['subiendo', 'procesando'], true)) $e->fill(['estado' => 'error', 'error' => (string) ($v['error'] ?? 'El emisor no pudo preparar el video.')])->save();
                    if ($e->estado === 'al_aire') { $this->copiarDestinos($e, $v); $this->finalizar($e, (string) ($v['error'] ?? 'La transmisión se cortó.'), false); }
                    break;
            }
        });
    }

    /** Crea los en vivo programados (anuncio) en cada destino. Si falla, se reintenta y a la hora se crea en directo. */
    public function anunciar(EmisionProgramada $e): string
    {
        return $this->conCandado($e, function (EmisionProgramada $e) {
            if ($e->estado !== 'listo' || $e->anunciada_en) return 'ya anunciada';
            $this->usarTokensDe($e);
            $destinos = (array) $e->destinos;
            $ok = 0;
            foreach ($destinos as &$d) {
                if (($d['estado'] ?? '') !== 'pendiente') continue;
                try {
                    if ($d['red'] === 'facebook') {
                        $page = MetaPage::find($d['meta_page_id']);
                        if (!$page) throw new \RuntimeException('La página ya no existe en editus');
                        $r = $this->facebook->crear($page, $e->titulo, (string) $e->descripcion, $e->programada_en);
                    } else {
                        $canal = YoutubeCanal::find($d['canal_id']);
                        if (!$canal) throw new \RuntimeException('El canal ya no existe en editus');
                        $r = $this->youtube->crear($canal, $e->titulo, (string) $e->descripcion, 'public', $e->programada_en, false);
                    }
                    $d = array_merge($d, ['live_id' => $r['id'], 'stream_url' => $r['stream_url'] ?? null, 'permalink' => $r['permalink'] ?? null, 'estado' => 'anunciado', 'error' => null]);
                    $ok++;
                } catch (\Throwable $ex) {
                    $d['error'] = 'Anuncio: ' . Str::limit($ex->getMessage(), 300, '');
                    Log::info('[emisiones] anuncio', ['id' => $e->id, 'red' => $d['red'], 'err' => $ex->getMessage()]);
                }
            }
            unset($d);
            $e->destinos = $destinos;
            $e->anunciada_en = now(); // no se reintenta en bucle: los que fallaron se crean en directo a la hora
            $e->save();
            return "anunciada en {$ok} de " . count($destinos) . ' destino(s)';
        });
    }

    /** A la hora: obtiene la URL de transmisión de cada destino y le pide al emisor que salga al aire. */
    public function arrancar(EmisionProgramada $e): string
    {
        return $this->conCandado($e, function (EmisionProgramada $e) {
            if ($e->estado !== 'listo') return 'no está lista (' . $e->estado . ')';
            $this->usarTokensDe($e);
            $destinos = (array) $e->destinos;
            $urls = [];
            foreach ($destinos as $i => &$d) {
                try {
                    if ($d['red'] === 'facebook') {
                        if (empty($d['stream_url'])) {
                            $page = MetaPage::find($d['meta_page_id']);
                            if (!$page) throw new \RuntimeException('La página ya no existe en editus');
                            $r = $this->facebook->crear($page, $e->titulo, (string) $e->descripcion);
                            $d = array_merge($d, ['live_id' => $r['id'], 'stream_url' => $r['stream_url'], 'permalink' => $r['permalink'] ?? $d['permalink']]);
                        }
                    } else {
                        $canal = YoutubeCanal::find($d['canal_id']);
                        if (!$canal) throw new \RuntimeException('El canal ya no existe en editus');
                        if (empty($d['live_id'])) {
                            $r = $this->youtube->crear($canal, $e->titulo, (string) $e->descripcion);
                            $d = array_merge($d, ['live_id' => $r['id'], 'stream_url' => $r['stream_url'], 'permalink' => $r['permalink']]);
                        } else {
                            $d['stream_url'] = $this->youtube->enlazar($canal, $d['live_id']);
                        }
                    }
                    $d['estado'] = 'emitiendo'; $d['error'] = null; $d['indice'] = count($urls);
                    $urls[] = $d['stream_url'];
                } catch (\Throwable $ex) {
                    $d['estado'] = 'error'; $d['indice'] = null;
                    $d['error'] = Str::limit($ex->getMessage(), 300, '');
                }
            }
            unset($d);
            $e->destinos = $destinos;
            if (!$urls) {
                $e->fill(['estado' => 'error', 'error' => 'Ningún destino aceptó la transmisión: ' . collect($destinos)->pluck('error')->filter()->implode(' · ')])->save();
                return 'error: ningún destino';
            }
            // El emisor cuadra la cuenta regresiva para que el video empiece justo a la hora
            $faltan = now()->diffInSeconds($e->programada_en, false);
            $cuenta = !empty(((array) $e->plantilla)['cuenta']) && $faltan > 1;
            try {
                $this->emisor->emitir($e->emisor_id, $urls, $cuenta, $e->programada_en->getTimestamp());
            } catch (\Throwable $ex) {
                $e->save();
                $this->cerrarLives($e); // los Lives creados en directo no deben quedar abiertos
                $e->fill(['estado' => 'error', 'error' => Str::limit($ex->getMessage(), 500, '')])->save();
                return 'error: ' . $ex->getMessage();
            }
            $e->fill(['estado' => 'al_aire', 'inicio_real' => now(), 'error' => null])->save();
            return 'al aire en ' . count($urls) . ' destino(s)' . ($cuenta ? ' con cuenta regresiva' : '');
        });
    }

    /** Cancela: detiene la emisión, quita los anuncios y borra el archivo del VPS. */
    public function cancelar(EmisionProgramada $e): void
    {
        $this->conCandado($e, function (EmisionProgramada $e) {
            if (!$e->pendiente()) return;
            if ($e->estado === 'al_aire') {
                try { $this->emisor->detener($e->emisor_id); } catch (\Throwable) {}
                $this->cerrarLives($e);
            } else {
                $this->cancelarAnuncios($e);
            }
            $this->borrarArchivo($e);
            $e->fill(['estado' => 'cancelado', 'fin_real' => $e->estado === 'al_aire' ? now() : null])->save();
        });
    }

    // ------------------------------------------------------------- ayudas

    private function finalizar(EmisionProgramada $e, ?string $error, bool $huboEmision): void
    {
        $this->cerrarLives($e);
        $e->fill(['estado' => $huboEmision ? 'terminado' : 'error', 'fin_real' => now(), 'error' => $error ? Str::limit($error, 1000, '') : null])->save();
    }

    /** Cierra los Lives en Facebook y YouTube (el video queda publicado en la página / canal). */
    private function cerrarLives(EmisionProgramada $e): void
    {
        $this->usarTokensDe($e);
        $destinos = (array) $e->destinos;
        foreach ($destinos as &$d) {
            if (empty($d['live_id']) || in_array($d['estado'] ?? '', ['pendiente', 'anunciado'], true) && empty($d['stream_url'])) continue;
            try {
                if ($d['red'] === 'facebook' && ($page = MetaPage::find($d['meta_page_id']))) $this->facebook->terminar($page, $d['live_id']);
                if ($d['red'] === 'youtube' && ($canal = YoutubeCanal::find($d['canal_id']))) $this->youtube->terminar($canal, $d['live_id']);
            } catch (\Throwable) {
                // las plataformas cierran solas al dejar de recibir señal
            }
            if (($d['estado'] ?? '') === 'emitiendo') $d['estado'] = 'terminado';
            $d['stream_url'] = null; // ya no se necesita la clave
        }
        unset($d);
        $e->destinos = $destinos;
        $e->save();
    }

    /** Quita los anuncios de en vivos programados que no llegaron a salir. */
    private function cancelarAnuncios(EmisionProgramada $e): void
    {
        $this->usarTokensDe($e);
        $destinos = (array) $e->destinos;
        foreach ($destinos as &$d) {
            if (($d['estado'] ?? '') !== 'anunciado' || empty($d['live_id'])) continue;
            try {
                if ($d['red'] === 'facebook' && ($page = MetaPage::find($d['meta_page_id']))) $this->facebook->cancelarProgramado($page, $d['live_id']);
                if ($d['red'] === 'youtube' && ($canal = YoutubeCanal::find($d['canal_id']))) $this->youtube->borrar($canal, $d['live_id']);
            } catch (\Throwable) {
            }
            $d['estado'] = 'cancelado'; $d['stream_url'] = null;
        }
        unset($d);
        $e->destinos = $destinos;
        $e->save();
    }

    private function borrarArchivo(EmisionProgramada $e): bool
    {
        try {
            if (!$this->emisor->borrar($e->emisor_id)) return false;
        } catch (\Throwable) {
            return false;
        }
        $e->archivo_borrado_en = now();
        $e->save();
        return true;
    }

    /** Estado por destino que informa el emisor (cada destino es un proceso independiente). */
    private function copiarDestinos(EmisionProgramada $e, array $v): void
    {
        $remotos = (array) data_get($v, 'emision.destinos', []);
        $destinos = (array) $e->destinos;
        foreach ($destinos as &$d) {
            $i = $d['indice'] ?? null;
            if ($i === null || !isset($remotos[$i])) continue;
            $d['estado'] = match ($remotos[$i]['estado'] ?? '') { 'terminado' => 'terminado', 'error' => 'error', default => 'emitiendo' };
            $d['error'] = $remotos[$i]['error'] ?? null;
            if ($d['estado'] === 'emitiendo' && ($remotos[$i]['intentos'] ?? 0) > 0) $d['error'] = 'Reconectando (intento ' . $remotos[$i]['intentos'] . ')';
        }
        unset($d);
        $e->destinos = $destinos;
        $total = (float) data_get($v, 'emision.total', 0);
        if ($total > 0) $e->progreso = (int) min(100, floor(100 * (float) data_get($v, 'emision.posicion', 0) / $total));
        $e->save();
    }

    /** Los Lives se crean con el token de quien programó (si conectó la página). */
    private function usarTokensDe(EmisionProgramada $e): void
    {
        MetaPageTokenResolver::preferirUsuarioApp(null);
        MetaPageTokenResolver::preferirUsuarioWeb($e->user_id ? (int) $e->user_id : null);
    }

    /** Evita que la tarea y un aviso del emisor muevan la misma emisión a la vez. */
    private function conCandado(EmisionProgramada $e, callable $fn)
    {
        $candado = Cache::lock('emision.' . $e->id, 120);
        if (!$candado->block(20)) return 'ocupada';
        try {
            return $fn($e->refresh());
        } finally {
            $candado->release();
        }
    }
}
