<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** php artisan envivo:diagnostico: dice qué falla del en vivo y qué hacer. */
class EnVivoDiagnosticoTest extends TestCase
{
    private function configurar(): void
    {
        config([
            'services.livekit.url' => 'wss://live.prueba.test', 'services.livekit.api_url' => null,
            'services.livekit.api_key' => 'APIviejo', 'services.livekit.api_secret' => str_repeat('s', 40),
            'services.livekit.escena_url' => 'https://live.prueba.test/escena/', 'services.livekit.recursos_url' => null,
            'services.emisor.url' => null, 'services.emisor.secreto' => null,
        ]);
    }

    public function test_avisa_si_las_claves_no_son_las_del_servidor_nuevo(): void
    {
        $this->configurar();
        Http::fake([
            'live.prueba.test/twirp/*' => Http::response(['code' => 'unauthenticated', 'msg' => 'invalid API key'], 401),
            'live.prueba.test/escena/*' => Http::response('<html>escena</html>', 200),
            'live.prueba.test*' => Http::response('OK', 200),
        ]);
        $this->artisan('envivo:diagnostico')
            ->expectsOutputToContain('EMISOR_URL vacío')
            ->expectsOutputToContain('Responde (HTTP 200')
            ->expectsOutputToContain('no reconoce la clave de editus')
            ->assertExitCode(1);
    }

    public function test_todo_bien_y_el_dns_apunta_a_otra_ip(): void
    {
        $this->configurar();
        config(['services.emisor.url' => 'https://live.prueba.test/emisor', 'services.emisor.secreto' => str_repeat('e', 40)]);
        Http::fake([
            'live.prueba.test/twirp/livekit.RoomService/ListRooms' => Http::response(['rooms' => [['name' => 'a']]], 200),
            'live.prueba.test/twirp/livekit.Egress/ListEgress' => Http::response(['items' => []], 200),
            'live.prueba.test/emisor/*' => Http::response(['ok' => true, 'version' => 3], 200, ['Access-Control-Allow-Origin' => '*']),
            'live.prueba.test*' => Http::response('OK', 200),
        ]);
        $this->artisan('envivo:diagnostico')
            ->expectsOutputToContain('salas abiertas: 1')
            ->expectsOutputToContain('El egress responde')
            ->expectsOutputToContain('Llega al emisor (versión 3)')
            ->expectsOutputToContain('Acepta el secreto (EMISOR_SECRETO)')
            ->expectsOutputToContain('Deja subir videos desde')
            ->expectsOutputToContain('Acepta el permiso de subida')
            ->expectsOutputToContain('no resuelve');   // live.prueba.test no existe en el DNS
    }

    public function test_emisor_con_otro_secreto_y_sin_cors_para_editus(): void
    {
        $this->configurar();
        config(['app.url' => 'https://app.editus.online', 'services.emisor.url' => 'https://live.prueba.test/emisor', 'services.emisor.secreto' => str_repeat('e', 40)]);
        Http::fake(function ($req) {
            $ruta = (string) parse_url($req->url(), PHP_URL_PATH);
            if ($ruta === '/emisor/salud') return Http::response(['ok' => true, 'version' => 3], 200);
            if ($ruta === '/emisor/estado') return Http::response(['error' => 'Firma no válida'], 401);
            if (str_starts_with($ruta, '/emisor/subidas/')) return Http::response(['error' => 'El permiso de subida venció.'], 403);
            return Http::response('OK', 200);
        });
        $this->artisan('envivo:diagnostico')
            ->expectsOutputToContain('No acepta EMISOR_SECRETO')
            ->expectsOutputToContain('No deja subir desde https://app.editus.online (CORS)')
            ->expectsOutputToContain('EMISOR_ORIGENES=https://app.editus.online')
            ->expectsOutputToContain('Rechaza el permiso de subida')
            ->assertExitCode(1);
    }

    public function test_nginx_sin_la_ruta_del_emisor(): void
    {
        $this->configurar();
        config(['services.emisor.url' => 'https://live.prueba.test/emisor', 'services.emisor.secreto' => str_repeat('e', 40)]);
        Http::fake(['live.prueba.test/emisor/*' => Http::response('404 page not found', 404), 'live.prueba.test*' => Http::response('OK', 200)]);
        $this->artisan('envivo:diagnostico')
            ->expectsOutputToContain('pero no es el emisor')
            ->assertExitCode(1);
    }
}
