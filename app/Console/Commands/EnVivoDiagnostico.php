<?php

namespace App\Console\Commands;

use App\Services\EmisorClient;
use App\Services\LiveKitClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnóstico del en vivo (servidor LiveKit, egress, emisor, escena y recursos), paso a paso.
 * Sirve sobre todo después de cambiar el servidor de live: dice qué falta y qué hacer.
 *
 *   php artisan envivo:diagnostico
 *   php artisan envivo:diagnostico --ip=203.0.113.10   (IP del VPS nuevo: avisa si el DNS aún apunta al viejo)
 */
class EnVivoDiagnostico extends Command
{
    protected $signature = 'envivo:diagnostico {--ip= : IP del servidor de live (VPS nuevo) para comparar con el DNS}';

    protected $description = 'Revisa la configuración y la conexión del en vivo (LiveKit, egress, emisor, escena y recursos)';

    private array $acciones = [];
    private int $fallos = 0;

    public function handle(LiveKitClient $lk, EmisorClient $emisor): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>📡 Diagnóstico del en vivo — ' . now()->format('Y-m-d H:i') . '</>');

        // ---- 1. Configuración en el .env de editus ----
        $this->seccion('1. Configuración (.env de editus)');
        $cfg = config('services.livekit');
        $em = config('services.emisor');
        foreach ([
            'LIVEKIT_URL' => $cfg['url'] ?? '',
            'LIVEKIT_API_KEY' => $cfg['api_key'] ?? '',
            'LIVEKIT_API_SECRET' => $cfg['api_secret'] ?? '',
            'EMISOR_URL' => $em['url'] ?? '',
            'EMISOR_SECRETO' => $em['secreto'] ?? '',
        ] as $var => $valor) {
            $valor = (string) $valor;
            if ($valor === '') {
                $this->mal("$var vacío");
                $this->acciones[] = "Escribe $var en el .env de editus (los valores los imprime el instalador del VPS y quedan en /opt/livekit/claves.txt y /opt/emisor/.env), luego: php artisan config:clear";
            } else {
                $mostrar = str_contains($var, 'SECRET') || str_contains($var, 'KEY') ? '(' . strlen($valor) . ' caracteres, huella ' . substr(hash('sha256', $valor), 0, 8) . ')' : $valor;
                $this->bien("$var = $mostrar");
            }
        }
        $url = (string) ($cfg['url'] ?? '');
        if ($url !== '' && !preg_match('#^wss://#', $url)) {
            $this->mal('LIVEKIT_URL debe empezar por wss:// (p. ej. wss://live.esnoticia.org)');
            $this->acciones[] = 'Corrige LIVEKIT_URL a wss://live.esnoticia.org';
        }
        if (app()->configurationIsCached()) {
            $this->line('  <fg=yellow>ℹ La configuración está en caché: si cambiaste el .env, corre php artisan config:clear</>');
        }

        // ---- 2. DNS del subdominio ----
        $host = parse_url($lk->apiUrl() ?: '', PHP_URL_HOST) ?: '';
        if ($host !== '') {
            $this->seccion("2. DNS de $host");
            $ips = gethostbynamel($host) ?: [];
            if (!$ips) {
                $this->mal("$host no resuelve a ninguna IP");
                $this->acciones[] = "Crea (o corrige) el registro DNS tipo A de $host apuntando a la IP del VPS nuevo";
            } else {
                $this->bien("$host → " . implode(', ', $ips));
                $ipNueva = trim((string) $this->option('ip'));
                if ($ipNueva !== '' && !in_array($ipNueva, $ips, true)) {
                    $this->mal("El DNS todavía apunta a " . implode(', ', $ips) . ", no a la IP del VPS nuevo ($ipNueva)");
                    $this->acciones[] = "Cambia el registro A de $host a $ipNueva (en Cloudflare: «Solo DNS», nube gris, para que pasen WebRTC y TURN) y espera unos minutos";
                } elseif ($ipNueva === '') {
                    $this->line('  <fg=gray>(compara esta IP con la del VPS nuevo, o vuelve a correr con --ip=IP-DEL-VPS)</>');
                }
            }
        }

        // ---- 3. LiveKit responde con certificado válido ----
        if ($lk->apiUrl() !== '') {
            $this->seccion('3. Servidor LiveKit (' . $lk->apiUrl() . ')');
            try {
                $r = Http::timeout(15)->connectTimeout(8)->get($lk->apiUrl());
                $r->ok()
                    ? $this->bien('Responde (HTTP ' . $r->status() . ', certificado válido)')
                    : $this->mal('Responde HTTP ' . $r->status() . ': ' . mb_substr(trim(strip_tags($r->body())), 0, 120));
                if (!$r->ok()) $this->acciones[] = "Revisa el proxy del subdominio en el VPS nuevo (nginx → LiveKit en el puerto 7880). En el VPS: cd /opt/livekit && docker compose ps  y  systemctl status nginx";
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->mal('No conecta: ' . mb_substr($msg, 0, 160));
                $this->acciones[] = str_contains(strtolower($msg), 'ssl') || str_contains(strtolower($msg), 'certificate')
                    ? "El certificado de $host no es válido: en el VPS nuevo vuelve a correr el instalador (emite el certificado con certbot) o corre: certbot --nginx -d $host"
                    : "El VPS nuevo no responde en https://$host: instala el en vivo con  bash /opt/editus-src/infra/en-vivo/instalar-ubuntu.sh $host tu-correo  y abre en el proveedor los puertos 80, 443, 7881/tcp, 5349/tcp, 3478/udp y 50000-60000/udp";
            }

            // ---- 4. Clave y secreto ----
            if ($lk->configurado()) {
                $claveMala = false;
                $this->seccion('4. Clave y secreto de LiveKit');
                try {
                    $salas = $lk->listarSalas();
                    $this->bien('editus entra al servidor con su clave (salas abiertas: ' . count($salas) . ')');
                } catch (\Throwable $e) {
                    $this->mal(mb_substr($e->getMessage(), 0, 200));
                    if (str_contains($e->getMessage(), '401')) {
                        $claveMala = true;
                        $ip = implode(', ', gethostbynamel(parse_url($lk->apiUrl(), PHP_URL_HOST) ?: '') ?: ['?']);
                        $this->acciones[] = "El LiveKit que responde en $ip no reconoce la clave de editus (huella " . substr(hash('sha256', (string) config('services.livekit.api_key')), 0, 8) . "). "
                            . "En el VPS nuevo corre:  curl -4 -s ifconfig.me; echo  (debe ser $ip; si no, el DNS apunta a otro servidor)  y  "
                            . "grep -A1 '^keys:' /opt/livekit/livekit.yaml | tail -1 | awk -F': ' '{gsub(/ /,\"\",\$1); printf \"%s\", \$1}' | sha256sum | cut -c1-8  "
                            . "(debe dar la misma huella). Si no coincide, copia la clave y el secreto de esa línea (CLAVE: SECRETO) al .env de editus y corre php artisan config:clear; "
                            . "si coincide, reinicia LiveKit: cd /opt/livekit && docker compose restart livekit";
                    } else {
                        $this->acciones[] = 'LiveKit no aceptó la llamada: revisa en el VPS  cd /opt/livekit && docker compose logs livekit';
                    }
                }

                // ---- 5. Egress (lo que manda la señal a Facebook) ----
                $this->seccion('5. Egress (envía la transmisión a Facebook / YouTube)');
                try {
                    $activos = $lk->listarEgress();
                    $this->bien('El egress responde (activos: ' . count($activos) . ')');
                } catch (\Throwable $e) {
                    if (!empty($claveMala) && str_contains($e->getMessage(), '401')) {
                        $this->line('  <fg=yellow>…</> No se puede revisar hasta que la clave funcione (es el mismo error de la clave).');
                    } else {
                        $this->mal(mb_substr($e->getMessage(), 0, 200));
                        $this->acciones[] = 'El egress no está corriendo en el VPS nuevo: cd /opt/livekit && docker compose up -d  y revisa  docker compose logs egress';
                    }
                }
            }
        }

        // ---- 6. Escena (la plantilla que dibuja el egress) ----
        $escena = (string) ($cfg['escena_url'] ?? '') ?: url('/en-vivo/escena');
        $this->seccion("6. Escena ($escena)");
        try {
            $r = Http::timeout(15)->connectTimeout(8)->get($escena);
            $r->status() < 500
                ? $this->bien('Carga (HTTP ' . $r->status() . ')')
                : $this->mal('HTTP ' . $r->status());
        } catch (\Throwable $e) {
            $this->mal('No carga: ' . mb_substr($e->getMessage(), 0, 160));
            $this->acciones[] = 'La escena no carga: si la sirve el VPS (LIVEKIT_ESCENA_URL), instálala en el nuevo con  bash /opt/editus-src/infra/en-vivo/instalar-escena.sh; si no, quita LIVEKIT_ESCENA_URL del .env';
        }

        // ---- 7. Recursos (intro, plantilla, publicidad) ----
        $recursos = (string) ($cfg['recursos_url'] ?? '');
        if ($recursos !== '') {
            $this->seccion("7. Recursos ($recursos)");
            try {
                $r = Http::timeout(15)->connectTimeout(8)->get(rtrim($recursos, '/') . '/');
                $r->status() < 500 ? $this->bien('Responde (HTTP ' . $r->status() . ')') : $this->mal('HTTP ' . $r->status());
            } catch (\Throwable $e) {
                $this->mal('No responde: ' . mb_substr($e->getMessage(), 0, 160));
                $this->acciones[] = 'Instala los recursos en el VPS nuevo: bash /opt/editus-src/infra/en-vivo/instalar-recursos.sh https://app.editus.online';
            }
        }

        // ---- 8. Emisor (pregrabados programados) ----
        if ($emisor->configurado()) {
            $this->seccion('8. Emisor de pregrabados (' . $emisor->base() . ')');
            $this->probarEmisor($emisor);
        } elseif ((string) config('services.emisor.url') !== '') {
            $this->seccion('8. Emisor de pregrabados');
            $this->mal('EMISOR_SECRETO vacío o con menos de 32 caracteres');
            $this->acciones[] = 'Copia EMISOR_SECRETO de /opt/emisor/.env del VPS al .env de editus, luego: php artisan config:clear';
        }

        $this->seccion('Qué hacer');
        if (!$this->acciones) {
            $this->line('  <fg=green>✔ Todo responde desde editus.</> Si la app aún no conecta, revisa en el VPS los puertos de WebRTC: 7881/tcp, 3478/udp y 50000-60000/udp (firewall del VPS y del proveedor).');
        } else {
            foreach (array_values(array_unique($this->acciones)) as $i => $a) $this->line('  ' . ($i + 1) . '. ' . $a);
        }
        $this->line('');
        return $this->fallos ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Prueba el emisor por partes, igual que lo usan editus y el navegador: si llega (Nginx), si acepta el
     * secreto y el reloj, si deja subir desde la página de editus (CORS) y si acepta el permiso de subida.
     */
    private function probarEmisor(EmisorClient $emisor): void
    {
        $base = $emisor->base();
        $instalar = 'En el VPS (root): bash /opt/editus-src/infra/en-vivo/instalar-emisor.sh https://app.editus.online';

        // a) /salud es pública: dice si Nginx manda /emisor/ al contenedor
        try {
            $r = Http::timeout(15)->acceptJson()->get($base . '/salud');
        } catch (\Throwable $e) {
            $this->mal('No conecta con ' . $base . '/salud: ' . mb_substr(preg_replace('/\?\S*/', '?…', $e->getMessage()), 0, 160));
            $this->acciones[] = 'Revisa que Nginx esté prendido en el VPS: systemctl status nginx (si dice failed: nginx -t && systemctl restart nginx); y que EMISOR_URL sea https://live.esnoticia.org/emisor, sin / al final';
            return;
        }
        if (!$r->successful() || ($r->json('ok') !== true)) {
            $this->mal("{$base}/salud responde HTTP {$r->status()} pero no es el emisor: a Nginx le falta «location /emisor/» o el contenedor está caído");
            $this->acciones[] = $instalar . '  (agrega «location /emisor/» a Nginx y levanta el contenedor; luego: cd /opt/emisor && docker compose logs --tail 50)';
            return;
        }
        $this->bien('Llega al emisor (versión ' . ($r->json('version') ?? '?') . ')');
        if ($fecha = $r->header('Date')) {
            $desfase = abs(time() - (int) strtotime($fecha));
            if ($desfase > 120) {
                $this->mal("El reloj de este servidor y el del VPS se llevan {$desfase} s (el emisor rechaza las firmas con más de 300 s)");
                $this->acciones[] = 'Sincroniza la hora en los dos servidores: timedatectl set-ntp true';
            }
        }

        // b) /estado firmado: el secreto es el mismo de /opt/emisor/.env
        [$codigo, $json] = $emisor->estadoFirmado();
        if ($codigo === 200) {
            $gb = isset($json['libre']) ? ' · espacio libre: ' . round(((float) $json['libre']) / 1073741824, 1) . ' GB' : '';
            $this->bien('Acepta el secreto (EMISOR_SECRETO)' . $gb);
        } elseif ($codigo === 401) {
            $this->mal('No acepta EMISOR_SECRETO: no es el mismo de /opt/emisor/.env (o los relojes no coinciden)');
            $this->acciones[] = 'Compara la huella: en el VPS, grep -oP "^EMISOR_SECRETO=\K.*" /opt/emisor/.env | tr -d "\r\n" | sha256sum | cut -c1-8 debe dar la misma huella del punto 1; si no, copia el secreto del VPS al .env de editus y corre php artisan config:clear';
        } else {
            $this->mal('/estado respondió ' . ($codigo ?: 'sin conexión') . ': ' . mb_substr((string) ($json['error'] ?? ''), 0, 120));
            $this->acciones[] = 'Revisa los logs del emisor: cd /opt/emisor && docker compose logs --tail 50';
        }

        // c) Subida desde el navegador: CORS para la página de editus y permiso (token) de subida
        $origen = preg_replace('#^(https?://[^/]+).*$#', '$1', rtrim((string) config('app.url'), '/'));
        $id = 'diag-' . now()->format('YmdHis');
        try {
            $pre = Http::timeout(15)->withHeaders(['Origin' => $origen, 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'content-range'])
                ->send('OPTIONS', $base . '/subidas/' . $id);
            $permitido = (string) $pre->header('Access-Control-Allow-Origin');
            if ($permitido === $origen || $permitido === '*') {
                $this->bien("Deja subir videos desde {$origen}");
            } else {
                $this->mal("No deja subir desde {$origen} (CORS): el navegador corta la subida y sale «Sin respuesta del servidor de transmisión»");
                $this->acciones[] = "En el VPS: en /opt/emisor/.env pon EMISOR_ORIGENES={$origen} (sin / al final; varias separadas por coma) y luego: cd /opt/emisor && docker compose up -d";
            }
            $sub = Http::timeout(15)->withHeaders(['Origin' => $origen])->acceptJson()->get($emisor->urlSubida($id, 1024));
            if ($sub->status() === 200) {
                $this->bien('Acepta el permiso de subida que firma editus');
            } elseif ($sub->status() === 403) {
                $this->mal('Rechaza el permiso de subida: EMISOR_SECRETO no es el mismo de /opt/emisor/.env');
                $this->acciones[] = 'Copia EMISOR_SECRETO de /opt/emisor/.env al .env de editus (sin espacios ni comillas) y corre php artisan config:clear';
            } else {
                $this->mal("La subida de prueba respondió HTTP {$sub->status()}");
            }
        } catch (\Throwable $e) {
            $this->mal('La prueba de subida no conectó: ' . mb_substr(preg_replace('/\?\S*/', '?…', $e->getMessage()), 0, 160));
        }
    }

    private function seccion(string $t): void
    {
        $this->line('');
        $this->line("<options=bold>── $t</>");
    }

    private function bien(string $t): void
    {
        $this->line("  <fg=green>✔</> $t");
    }

    private function mal(string $t): void
    {
        $this->fallos++;
        $this->line("  <fg=red>✖</> $t");
    }
}
