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
            'live.prueba.test/emisor/*' => Http::response(['ok' => true], 200),
            'live.prueba.test*' => Http::response('OK', 200),
        ]);
        $this->artisan('envivo:diagnostico')
            ->expectsOutputToContain('salas abiertas: 1')
            ->expectsOutputToContain('El egress responde')
            ->expectsOutputToContain('Responde y acepta el secreto')
            ->expectsOutputToContain('no resuelve');   // live.prueba.test no existe en el DNS
    }
}
