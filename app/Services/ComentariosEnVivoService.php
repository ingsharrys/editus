<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\TransmisionEnVivo;
use App\Models\YoutubeCanal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Comentarios en vivo de todos los destinos (Facebook y YouTube) en una sola lista, para el
 * panel "Comentarios" del estudio: quien dirige elige uno y lo muestra en pantalla.
 * Se guarda en caché unos segundos para no gastar cuota de YouTube si hay varias pestañas abiertas.
 */
class ComentariosEnVivoService
{
    public function __construct(private FacebookLiveService $facebook, private YouTubeLiveService $youtube)
    {
    }

    /** @return array{comentarios: array, avisos: array} */
    public function leer(TransmisionEnVivo $t): array
    {
        if ($t->estado !== 'en_vivo') return ['comentarios' => [], 'avisos' => []];
        return Cache::remember("en-vivo:{$t->id}:comentarios", 6, fn() => $this->leerAhora($t));
    }

    private function leerAhora(TransmisionEnVivo $t): array
    {
        $todos = []; $avisos = [];
        foreach ($t->destinosLista() as $d) {
            if (($d['estado'] ?? '') !== 'ok' || empty($d['fb_live_id'])) continue;
            $nombre = (string) ($d['pagina'] ?? '');
            try {
                if (($d['red'] ?? 'facebook') === 'youtube') {
                    $c = YoutubeCanal::find($d['canal_id'] ?? 0);
                    if (!$c) continue;
                    // Mensajes acumulados del chat (YouTube entrega los nuevos desde el último "pageToken")
                    $clave = "en-vivo:{$t->id}:yt:{$d['fb_live_id']}";
                    $estado = Cache::get($clave, ['chat' => null, 'pagina' => null, 'mensajes' => []]);
                    $estado['chat'] = $estado['chat'] ?: $this->youtube->chatId($c, $d['fb_live_id']);
                    if (!$estado['chat']) continue;
                    $r = $this->youtube->mensajesChat($c, $estado['chat'], $estado['pagina']);
                    $estado['pagina'] = $r['siguiente'] ?: $estado['pagina'];
                    $estado['mensajes'] = array_slice(array_merge(array_reverse($r['mensajes']), $estado['mensajes']), 0, 100);
                    Cache::put($clave, $estado, now()->addHours(8));
                    foreach ($estado['mensajes'] as $m) $todos[] = $m + ['destino' => $nombre];
                } else {
                    $p = MetaPage::find($d['meta_page_id'] ?? 0);
                    if (!$p) continue;
                    foreach ($this->facebook->comentarios($p, $d['fb_live_id']) as $m) $todos[] = $m + ['destino' => $nombre];
                }
            } catch (\Throwable $e) {
                $avisos[] = $nombre . ': ' . Str::limit($e->getMessage(), 160, '');
            }
        }
        usort($todos, fn($a, $b) => strcmp((string) ($b['fecha'] ?? ''), (string) ($a['fecha'] ?? '')));
        return ['comentarios' => array_slice($todos, 0, 120), 'avisos' => $avisos];
    }
}
