<?php

namespace Tests\Feature;

use App\Models\MetaPage;
use App\Models\Role;
use App\Models\TransmisionEnVivo;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Transmisiones en vivo: Facebook Live + LiveKit (Twirp simulado). */
class EnVivoApiTest extends TestCase
{
    private const TOKEN = 'token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.editus.ingest_token' => self::TOKEN,
            'services.facebook.version' => 'v23.0',
            'services.livekit.url' => 'wss://live.prueba.test',
            'services.livekit.api_key' => 'APIkey',
            'services.livekit.api_secret' => 'secreto-muy-largo-de-prueba-1234567890',
        ]);
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id'); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('transmisiones_en_vivo', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->string('usuario_app', 60)->nullable(); $t->string('titulo', 200); $t->text('descripcion')->nullable(); $t->string('room', 80)->unique();
            $t->string('fb_live_id', 60)->nullable(); $t->string('fb_video_id', 60)->nullable(); $t->string('fb_permalink', 500)->nullable(); $t->text('stream_url')->nullable(); $t->string('egress_id', 80)->nullable(); $t->json('destinos')->nullable();
            $t->string('estado', 20)->default('creada'); $t->json('plantilla')->nullable(); $t->json('escena')->nullable(); $t->json('invitaciones')->nullable(); $t->text('error')->nullable(); $t->timestamp('iniciada_en')->nullable(); $t->timestamp('terminada_en')->nullable(); $t->timestamps();
        });
        $rol = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user = User::factory()->create(['role_id' => $rol->id]);
        $page = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias']);
        $page->users()->attach($user->id, ['page_access_token' => 'tok-pagina', 'is_active' => true]);
        $page2 = MetaPage::create(['page_id' => '222', 'name' => 'Neiva 24']);
        $page2->users()->attach($user->id, ['page_access_token' => 'tok-pagina-2', 'is_active' => true]);
        Schema::create('recursos_en_vivo', function (Blueprint $t) { $t->id(); $t->string('tipo', 10); $t->string('uso', 15)->default('publicidad'); $t->string('nombre', 80); $t->string('archivo', 300); $t->unsignedInteger('duracion')->nullable(); $t->unsignedSmallInteger('orden')->default(0); $t->boolean('activo')->default(true); $t->timestamps(); });
    }

    private function fakeTodo(array $listEgress = [['egress_id' => 'EG_1', 'status' => 'EGRESS_ACTIVE']]): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/111/live_videos' => Http::response(['id' => '7001', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-SECRETA', 'stream_url' => 'rtmp://x'], 200),
            'graph.facebook.com/v23.0/7001?*' => Http::response(['permalink_url' => '/opa/videos/7001/', 'status' => 'LIVE', 'live_views' => 42, 'video' => ['id' => '7002']], 200),
            'graph.facebook.com/v23.0/7001' => Http::response(['success' => true], 200),
            'graph.facebook.com/v23.0/222/live_videos' => Http::response(['id' => '8001', 'secure_stream_url' => 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-DOS', 'stream_url' => 'rtmp://y'], 200),
            'graph.facebook.com/v23.0/8001?*' => Http::response(['permalink_url' => '/neiva24/videos/8001/', 'status' => 'LIVE', 'live_views' => 8, 'video' => ['id' => '8002']], 200),
            'graph.facebook.com/v23.0/8001' => Http::response(['success' => true], 200),
            'live.prueba.test/twirp/livekit.RoomService/CreateRoom' => Http::response(['name' => 'x', 'sid' => 'RM_1'], 200),
            'live.prueba.test/twirp/livekit.RoomService/UpdateRoomMetadata' => Http::response(['name' => 'x'], 200),
            'live.prueba.test/twirp/livekit.RoomService/DeleteRoom' => Http::response([], 200),
            'live.prueba.test/twirp/livekit.RoomService/ListParticipants' => Http::response(['participants' => [
                ['identity' => 'camara-principal', 'name' => 'Estudio', 'state' => 'ACTIVE', 'tracks' => [['type' => 'VIDEO', 'source' => 'CAMERA', 'muted' => false]]],
                ['identity' => 'invitado-abc', 'name' => 'Carlos', 'state' => 'ACTIVE', 'tracks' => [['type' => 'VIDEO', 'source' => 'CAMERA', 'muted' => false], ['type' => 'AUDIO', 'source' => 'MICROPHONE', 'muted' => true]]],
                ['identity' => 'EG_1', 'name' => '', 'state' => 'ACTIVE', 'tracks' => []],
            ]], 200),
            'live.prueba.test/twirp/livekit.RoomService/RemoveParticipant' => Http::response([], 200),
            'live.prueba.test/twirp/livekit.Egress/StartRoomCompositeEgress' => Http::response(['egress_id' => 'EG_1', 'status' => 'EGRESS_STARTING'], 200),
            'live.prueba.test/twirp/livekit.Egress/StopEgress' => Http::response(['egress_id' => 'EG_1'], 200),
            'live.prueba.test/twirp/livekit.Egress/ListEgress' => Http::response(['items' => $listEgress], 200),
        ]);
    }

    public function test_inicia_actualiza_plantilla_y_termina(): void
    {
        $this->fakeTodo();
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', [
            'page_id' => '111', 'titulo' => 'Consejo de Neiva en vivo', 'descripcion' => 'Sesión del concejo', 'usuario' => '5',
            'plantilla' => ['logo_texto' => 'OPA Noticias', 'color_logo' => '#ffd400', 'etiqueta' => 'Política', 'pie' => 'Opanoticias.com'],
        ]);
        $r->assertOk()->assertJsonPath('success', true)
          ->assertJsonPath('transmision.estado', 'en_vivo')
          ->assertJsonPath('transmision.fb_live_id', '7001')
          ->assertJsonPath('transmision.permalink', 'https://www.facebook.com/opa/videos/7001/')
          ->assertJsonPath('transmision.plantilla.etiqueta', 'POLÍTICA')
          ->assertJsonPath('transmision.plantilla.color_logo', '#FFD400')
          ->assertJsonPath('transmision.plantilla.titulo', 'Consejo de Neiva en vivo')
          ->assertJsonPath('livekit.url', 'wss://live.prueba.test');
        // El RTMP secreto no sale en la API
        $this->assertStringNotContainsString('CLAVE-SECRETA', $r->getContent());

        // Token de la cámara: JWT válido con permiso de publicar en la sala
        $room = $r->json('transmision.room');
        $jwt = JWT::decode($r->json('livekit.token'), new Key('secreto-muy-largo-de-prueba-1234567890', 'HS256'));
        $this->assertSame('APIkey', $jwt->iss);
        $this->assertSame('camara-principal', $jwt->sub);
        $this->assertSame($room, $jwt->video->room);
        $this->assertTrue($jwt->video->canPublish);

        // LiveKit: sala con la plantilla como metadata y egress RTMP a Facebook con la escena
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/CreateRoom') && $req['name'] === $room && str_contains($req['metadata'], '"logo_texto":"OPA Noticias"') && str_starts_with($req->header('Authorization')[0], 'Bearer '));
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/StartRoomCompositeEgress') && $req['room_name'] === $room
            && $req['stream_outputs'][0]['urls'][0] === 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-SECRETA'
            && str_contains($req['custom_base_url'], '/en-vivo/escena')
            && $req['advanced']['width'] === 1920 && $req['advanced']['height'] === 1080 && $req['advanced']['key_frame_interval'] === 2 && $req['advanced']['audio_codec'] === 'AAC');
        // Facebook: live creado con título
        Http::assertSent(fn($req) => str_contains($req->url(), '/111/live_videos') && $req['status'] === 'LIVE_NOW' && $req['title'] === 'Consejo de Neiva en vivo');

        $id = $r->json('transmision.id');

        // Plantilla en tiempo real → metadata de la sala
        $p = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/plantilla", ['plantilla' => ['titulo' => 'Aprobado el presupuesto', 'mostrar' => true]]);
        $p->assertOk()->assertJsonPath('plantilla.titulo', 'Aprobado el presupuesto')->assertJsonPath('plantilla.logo_texto', 'OPA Noticias');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/UpdateRoomMetadata') && $req['room'] === $room && str_contains($req['metadata'], 'Aprobado el presupuesto'));

        // Estado: espectadores de Facebook y egress activo
        $e = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson("/api/en-vivo/{$id}/estado");
        $e->assertOk()->assertJsonPath('facebook.espectadores', 42)->assertJsonPath('egress', 'EGRESS_ACTIVE')->assertJsonPath('transmision.estado', 'en_vivo');
        $this->assertNotEmpty($e->json('livekit.token'));

        $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/en-vivo/activas?usuario=5')->assertOk()->assertJsonCount(1, 'transmisiones');

        // Terminar: detiene el egress, cierra el live y guarda el video resultante
        $t = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/terminar");
        $t->assertOk()->assertJsonPath('transmision.estado', 'terminada')->assertJsonPath('transmision.fb_video_id', '7002');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/StopEgress') && $req['egress_id'] === 'EG_1');
        Http::assertSent(fn($req) => $req->url() === 'https://graph.facebook.com/v23.0/7001' && ($req['end_live_video'] ?? null) === 'true');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/DeleteRoom') && $req['room'] === $room);
        $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/en-vivo/activas')->assertOk()->assertJsonCount(0, 'transmisiones');
    }

    public function test_invitados_escena_y_expulsar(): void
    {
        $this->fakeTodo();
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', ['page_id' => '111', 'titulo' => 'Debate en vivo']);
        $r->assertOk()->assertJsonPath('transmision.escena.layout', 'solo');
        $id = $r->json('transmision.id');
        $room = $r->json('transmision.room');
        // La sala nace con la escena en la metadata
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/CreateRoom') && str_contains($req['metadata'], '"escena":{"layout":"solo"'));

        // Invitación → enlace público
        $i = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/invitacion", ['nombre' => 'Carlos']);
        $i->assertOk()->assertJsonPath('success', true);
        $codigo = $i->json('codigo');
        $this->assertStringContainsString("/en-vivo/invitado/{$codigo}", $i->json('url'));
        $this->withHeader('X-Editus-Token', self::TOKEN)->getJson("/api/en-vivo/{$id}/estado")->assertOk()->assertJsonPath('transmision.invitaciones.0.nombre', 'Carlos');

        // Página del invitado sin sesión, y token con permiso de publicar en la misma sala
        $this->get("/en-vivo/invitado/{$codigo}")->assertOk()->assertSee('Debate en vivo')->assertSee('enableCameraAndMicrophone');
        $this->get('/en-vivo/invitado/noexiste')->assertNotFound();
        $t = $this->postJson("/en-vivo/invitado/{$codigo}/token", ['nombre' => 'Carlos desde Pitalito']);
        $t->assertOk()->assertJsonPath('url', 'wss://live.prueba.test')->assertJsonPath('identity', "invitado-{$codigo}");
        $jwt = JWT::decode($t->json('token'), new Key('secreto-muy-largo-de-prueba-1234567890', 'HS256'));
        $this->assertSame($room, $jwt->video->room);
        $this->assertTrue($jwt->video->canPublish);
        $this->assertSame('Carlos desde Pitalito', $jwt->name);

        // Participantes conectados (el egress no cuenta)
        $p = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson("/api/en-vivo/{$id}/participantes");
        $p->assertOk()->assertJsonCount(2, 'participantes')->assertJsonPath('participantes.1.nombre', 'Carlos')->assertJsonPath('participantes.1.audio', false);

        // Escena: dos cámaras, el invitado al aire → metadata de la sala
        $e = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['layout' => 'dos', 'visibles' => ['invitado-abc']]);
        $e->assertOk()->assertJsonPath('escena.layout', 'dos')->assertJsonPath('escena.visibles.0', 'invitado-abc')->assertJsonPath('escena.principal', 'camara-principal');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/UpdateRoomMetadata') && $req['room'] === $room && str_contains($req['metadata'], '"layout":"dos"'));
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['layout' => 'otro'])->assertStatus(422);

        // Expulsar
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/participantes/invitado-abc/expulsar")->assertOk();
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/RemoveParticipant') && $req['identity'] === 'invitado-abc');
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/participantes/camara-principal/expulsar")->assertStatus(422);

        // Terminada → la invitación deja de servir
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/terminar")->assertOk();
        $this->postJson("/en-vivo/invitado/{$codigo}/token")->assertStatus(422);
        $this->get("/en-vivo/invitado/{$codigo}")->assertOk()->assertSee('no está en vivo');
    }

    public function test_el_estado_explica_por_que_se_corto_el_egress(): void
    {
        $this->fakeTodo([['egress_id' => 'EG_1', 'status' => 'EGRESS_FAILED', 'error' => 'page load error: net::ERR_NAME_NOT_RESOLVED']]);
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', ['page_id' => '111', 'titulo' => 'Prueba']);
        $id = $r->json('transmision.id');
        $e = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson("/api/en-vivo/{$id}/estado");
        $e->assertOk()->assertJsonPath('transmision.estado', 'error');
        $this->assertStringContainsString('ERR_NAME_NOT_RESOLVED', $e->json('transmision.error'));
    }

    public function test_varias_paginas_a_la_vez_rotulo_logo_y_recursos(): void
    {
        $this->fakeTodo();
        $recurso = \App\Models\RecursoEnVivo::create(['tipo' => 'video', 'nombre' => 'Cortinilla', 'archivo' => 'en-vivo/recursos/c.mp4', 'duracion' => 12, 'orden' => 0, 'activo' => true]);
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', [
            'page_ids' => ['111', '222'], 'titulo' => 'Rueda de prensa',
            'plantilla' => ['rotulo_nombre' => 'Carlos Pérez', 'rotulo_cargo' => 'Periodista', 'rotulo_mostrar' => true, 'logo_url' => 'https://app.editus.online/storage/logos/opa.png'],
        ]);
        $r->assertOk()->assertJsonPath('transmision.estado', 'en_vivo')
          ->assertJsonCount(2, 'transmision.destinos')
          ->assertJsonPath('transmision.destinos.1.pagina', 'Neiva 24')
          ->assertJsonPath('transmision.destinos.1.fb_live_id', '8001')
          ->assertJsonPath('transmision.paginas', ['Opa Noticias', 'Neiva 24'])
          ->assertJsonPath('transmision.plantilla.rotulo_nombre', 'Carlos Pérez')
          ->assertJsonPath('transmision.plantilla.rotulo_mostrar', true)
          ->assertJsonPath('transmision.plantilla.logo_url', 'https://app.editus.online/storage/logos/opa.png');
        $this->assertStringNotContainsString('CLAVE-DOS', $r->getContent());
        // Un solo egress con las dos salidas RTMP
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/StartRoomCompositeEgress') && count($req['stream_outputs'][0]['urls']) === 2 && $req['stream_outputs'][0]['urls'][1] === 'rtmps://live-api-s.facebook.com:443/rtmp/CLAVE-DOS');
        $id = $r->json('transmision.id');

        // Espectadores sumados
        $e = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson("/api/en-vivo/{$id}/estado");
        $e->assertOk()->assertJsonPath('facebook.espectadores', 50)->assertJsonCount(2, 'facebook.por_pagina');

        // Recursos y cortinilla al aire
        $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/en-vivo/recursos')->assertOk()->assertJsonPath('recursos.0.nombre', 'Cortinilla')->assertJsonPath('recursos.0.tipo', 'video');
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['recurso_id' => $recurso->id])->assertOk()->assertJsonPath('escena.recurso.nombre', 'Cortinilla')->assertJsonPath('escena.recurso.duracion', 12)->assertJsonPath('escena.recurso.formato', 'completa');
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['recurso_id' => $recurso->id, 'formato' => 'mitad'])->assertOk()->assertJsonPath('escena.recurso.formato', 'mitad');
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['recurso_id' => $recurso->id, 'formato' => 'tercio'])->assertStatus(422);
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/UpdateRoomMetadata') && str_contains($req['metadata'], '"recurso":{') && str_contains($req['metadata'], 'c.mp4'));
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['quitar_recurso' => true])->assertOk()->assertJsonMissingPath('escena.recurso');
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['recurso_id' => 999])->assertStatus(422);
        // Un logo que no es URL se descarta
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/plantilla", ['plantilla' => ['logo_url' => 'javascript:alert(1)']])->assertOk()->assertJsonPath('plantilla.logo_url', '');

        // Terminar: cierra los dos lives y guarda ambos videos
        $t = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/terminar");
        $t->assertOk()->assertJsonPath('transmision.destinos.0.fb_video_id', '7002')->assertJsonPath('transmision.destinos.1.fb_video_id', '8002');
        Http::assertSent(fn($req) => $req->url() === 'https://graph.facebook.com/v23.0/8001' && ($req['end_live_video'] ?? null) === 'true');
    }

    public function test_sala_primero_y_luego_al_aire_con_intro_y_nombres(): void
    {
        $this->fakeTodo();
        $intro = \App\Models\RecursoEnVivo::create(['tipo' => 'video', 'uso' => 'intro', 'nombre' => 'Intro Opa', 'archivo' => 'en-vivo/recursos/intro.mp4', 'orden' => 0, 'activo' => true]);
        $marco = \App\Models\RecursoEnVivo::create(['tipo' => 'imagen', 'uso' => 'plantilla', 'nombre' => 'Marco rojo', 'archivo' => 'en-vivo/recursos/marco.png', 'orden' => 1, 'activo' => true]);

        // 1) Sala: hay room y token, pero no hay Live en Facebook ni egress
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/preparar', ['page_ids' => ['111', '222'], 'titulo' => 'Noticiero', 'plantilla' => ['marco_url' => $marco->url()]]);
        $r->assertOk()->assertJsonPath('transmision.estado', 'sala')->assertJsonPath('transmision.destinos.0.estado', 'pendiente')->assertJsonPath('transmision.plantilla.marco_url', $marco->url())->assertJsonPath('transmision.escena.rotulo_auto', false);
        $this->assertNotEmpty($r->json('livekit.token'));
        // Monitor de programa: la escena con un token oculto que solo mira y en modo monitor (sin audio)
        $monitor = (string) $r->json('monitor.url');
        $this->assertStringContainsString('/en-vivo/escena?', $monitor);
        $this->assertStringContainsString('monitor=1', $monitor);
        parse_str((string) parse_url($monitor, PHP_URL_QUERY), $qs);
        $jm = JWT::decode($qs['token'], new Key('secreto-muy-largo-de-prueba-1234567890', 'HS256'));
        $this->assertStringStartsWith('monitor-', $jm->sub);
        $this->assertFalse($jm->video->canPublish);
        $this->assertTrue($jm->video->hidden);
        Http::assertNotSent(fn($req) => str_contains($req->url(), '/live_videos'));
        Http::assertNotSent(fn($req) => str_contains($req->url(), 'StartRoomCompositeEgress'));
        $id = $r->json('transmision.id');
        // La sala aparece como activa y los invitados ya pueden entrar
        $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/en-vivo/activas')->assertOk()->assertJsonCount(1, 'transmisiones');
        $i = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/invitacion", ['modo' => 'pantalla']);
        $i->assertOk()->assertJsonPath('modo', 'pantalla');
        $this->get('/en-vivo/invitado/' . $i->json('codigo'))->assertOk()->assertSee('Compartir mi pantalla');
        $this->postJson('/en-vivo/invitado/' . $i->json('codigo') . '/token')->assertOk()->assertJsonPath('identity', 'invitado-' . $i->json('codigo'));

        // Nombres por cámara y rótulo de quien habla → metadata de la sala
        $e = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['nombres' => [
            'camara-principal' => ['personas' => [['nombre' => 'Ana Ruiz', 'cargo' => 'Presentadora'], ['nombre' => 'Pedro Gil', 'cargo' => 'Invitado']], 'activa' => 1],
            'invitado-abc' => ['nombre' => 'Luis', 'cargo' => ''], // formato antiguo: una sola persona
        ], 'rotulo_de' => 'camara-principal']);
        $e->assertOk()->assertJsonPath('escena.nombres.camara-principal.personas.1.nombre', 'Pedro Gil')->assertJsonPath('escena.nombres.camara-principal.activa', 1)
          ->assertJsonPath('escena.nombres.invitado-abc.personas.0.nombre', 'Luis')->assertJsonPath('escena.rotulo_de', 'camara-principal');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/UpdateRoomMetadata') && str_contains($req['metadata'], 'Ana Ruiz') && str_contains($req['metadata'], '"rotulo_de":"camara-principal"'));
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['rotulo_auto' => true, 'quitar_rotulo' => true])->assertOk()->assertJsonPath('escena.rotulo_auto', true)->assertJsonPath('escena.rotulo_de', null);

        // Probar la intro en la sala (se ve en el monitor) no la deja "pegada" al salir al aire sin intro
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['recurso_id' => $intro->id])->assertOk()->assertJsonPath('escena.recurso.nombre', 'Intro Opa');

        // 2) Al aire con intro: lives en las dos páginas, intro en la metadata y egress con dos salidas
        $a = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/iniciar", ['intro_recurso_id' => $intro->id]);
        $a->assertOk()->assertJsonPath('transmision.estado', 'en_vivo')->assertJsonPath('transmision.destinos.1.fb_live_id', '8001')->assertJsonPath('transmision.escena.recurso.nombre', 'Intro Opa');
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/StartRoomCompositeEgress') && count($req['stream_outputs'][0]['urls']) === 2);
        Http::assertSent(fn($req) => str_ends_with($req->url(), '/UpdateRoomMetadata') && str_contains($req['metadata'], 'intro.mp4'));
        // No se puede salir al aire dos veces
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/iniciar")->assertStatus(422);
    }

    public function test_subida_de_recursos_desde_la_app_y_borrado(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $exp = time() + 600;
        $sig = hash_hmac('sha256', "5|{$exp}", self::TOKEN);
        // Intro en video
        $r = $this->post('/api/subidas/recurso', ['u' => '5', 'exp' => $exp, 'sig' => $sig, 'nombre' => 'Intro', 'uso' => 'intro',
            'archivo' => \Illuminate\Http\UploadedFile::fake()->create('intro.mp4', 1200, 'video/mp4')]);
        $r->assertOk()->assertJsonPath('recurso.uso', 'intro')->assertJsonPath('recurso.tipo', 'video');
        // Plantilla debe ser PNG
        $this->post('/api/subidas/recurso', ['u' => '5', 'exp' => $exp, 'sig' => $sig, 'nombre' => 'Marco', 'uso' => 'plantilla',
            'archivo' => \Illuminate\Http\UploadedFile::fake()->image('marco.jpg', 1920, 1080)])->assertStatus(422);
        $p = $this->post('/api/subidas/recurso', ['u' => '5', 'exp' => $exp, 'sig' => $sig, 'nombre' => 'Marco', 'uso' => 'plantilla',
            'archivo' => \Illuminate\Http\UploadedFile::fake()->image('marco.png', 1920, 1080)]);
        $p->assertOk()->assertJsonPath('recurso.uso', 'plantilla')->assertJsonPath('recurso.tipo', 'imagen');
        // Publicidad en imagen con duración
        $this->post('/api/subidas/recurso', ['u' => '5', 'exp' => $exp, 'sig' => $sig, 'nombre' => 'Aviso', 'uso' => 'publicidad', 'duracion' => 15,
            'archivo' => \Illuminate\Http\UploadedFile::fake()->image('aviso.jpg', 1920, 1080)])->assertOk()->assertJsonPath('recurso.duracion', 15);
        // Firma inválida
        $this->post('/api/subidas/recurso', ['u' => '5', 'exp' => $exp, 'sig' => 'mala', 'nombre' => 'X', 'uso' => 'intro',
            'archivo' => \Illuminate\Http\UploadedFile::fake()->create('x.mp4', 10, 'video/mp4')])->assertStatus(401);

        $lista = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/en-vivo/recursos');
        $lista->assertOk()->assertJsonCount(3, 'recursos');
        $this->assertSame(3, count(\Illuminate\Support\Facades\Storage::disk('public')->files('en-vivo/recursos')));
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/recursos/' . $p->json('recurso.id') . '/borrar')->assertOk();
        $this->withHeader('X-Editus-Token', self::TOKEN)->getJson('/api/en-vivo/recursos')->assertOk()->assertJsonCount(2, 'recursos');
        $this->assertSame(2, count(\Illuminate\Support\Facades\Storage::disk('public')->files('en-vivo/recursos')));
    }

    public function test_si_facebook_falla_al_salir_al_aire_la_sala_sigue(): void
    {
        Http::fake(['graph.facebook.com/v23.0/*/live_videos' => Http::response(['error' => ['message' => '(#200) Requires publish_video permission']], 400)]);
        $this->fakeTodo();
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/preparar', ['page_id' => '111', 'titulo' => 'Prueba']);
        $id = $r->json('transmision.id');
        $a = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/iniciar");
        $a->assertStatus(422);
        $this->assertStringContainsString('publish_video', $a->json('error'));
        $this->assertSame('sala', TransmisionEnVivo::find($id)->estado);
        Http::assertNotSent(fn($req) => str_contains($req->url(), 'StartRoomCompositeEgress'));
    }

    public function test_lo_probado_en_la_sala_no_sale_al_aire(): void
    {
        $this->fakeTodo();
        $aviso = \App\Models\RecursoEnVivo::create(['tipo' => 'imagen', 'uso' => 'publicidad', 'nombre' => 'Aviso', 'archivo' => 'en-vivo/recursos/a.png', 'orden' => 0, 'activo' => true]);
        $id = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/preparar', ['page_id' => '111', 'titulo' => 'Prueba'])->json('transmision.id');
        $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/escena", ['recurso_id' => $aviso->id])->assertOk();
        $a = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson("/api/en-vivo/{$id}/iniciar");
        $a->assertOk()->assertJsonPath('transmision.estado', 'en_vivo')->assertJsonMissingPath('transmision.escena.recurso');
        $e = $this->withHeader('X-Editus-Token', self::TOKEN)->getJson("/api/en-vivo/{$id}/estado");
        $this->assertStringContainsString('monitor=1', $e->json('monitor.url'));
    }

    public function test_si_una_pagina_falla_sigue_con_las_demas(): void
    {
        // El primer stub registrado gana: la página 222 falla y la 111 sigue
        Http::fake(['graph.facebook.com/v23.0/222/live_videos' => Http::response(['error' => ['message' => '(#200) Requires publish_video permission']], 400)]);
        $this->fakeTodo();
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', ['page_ids' => ['222', '111'], 'titulo' => 'Prueba']);
        $r->assertOk()->assertJsonPath('transmision.estado', 'en_vivo')->assertJsonPath('transmision.destinos.0.estado', 'error')->assertJsonPath('transmision.destinos.1.estado', 'ok')->assertJsonPath('transmision.pagina', 'Opa Noticias');
        $this->assertStringContainsString('publish_video', $r->json('transmision.destinos.0.error'));
    }

    public function test_el_invitado_puede_compartir_pantalla(): void
    {
        $this->fakeTodo();
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', ['page_id' => '111', 'titulo' => 'Prueba']);
        $i = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/' . $r->json('transmision.id') . '/invitacion', []);
        $this->get('/en-vivo/invitado/' . $i->json('codigo'))->assertOk()->assertSee('Compartir pantalla')->assertSee('setScreenShareEnabled');
        $this->get('/en-vivo/escena')->assertOk()->assertSee('notranslate')->assertSee('ScreenShare')->assertSee('rotulo')->assertSee('recurso');
    }

    public function test_si_facebook_falla_no_queda_nada_a_medias(): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/111/live_videos' => Http::response(['error' => ['message' => '(#200) Requires publish_video permission', 'code' => 200]], 400),
            'live.prueba.test/*' => Http::response([], 200),
        ]);
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', ['page_id' => '111', 'titulo' => 'Prueba']);
        $r->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('publish_video', $r->json('error'));
        $this->assertSame('error', TransmisionEnVivo::first()->estado);
        Http::assertNotSent(fn($req) => str_contains($req->url(), 'StartRoomCompositeEgress'));
    }

    public function test_sin_livekit_configurado_avisa(): void
    {
        config(['services.livekit.url' => '']);
        $r = $this->withHeader('X-Editus-Token', self::TOKEN)->postJson('/api/en-vivo/iniciar', ['page_id' => '111', 'titulo' => 'Prueba']);
        $r->assertStatus(422);
        $this->assertStringContainsString('LiveKit no está configurado', $r->json('error'));
    }

    public function test_la_escena_se_sirve_sin_sesion(): void
    {
        $this->get('/en-vivo/escena?url=wss://x&token=y')->assertOk()->assertSee('START_RECORDING')->assertSee('RoomMetadataChanged');
    }
}
