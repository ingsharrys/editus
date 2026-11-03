<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MetaPage;
use App\Models\MetaPost;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/** Lo publicado desde la web de editus sale para las estadísticas de la app del editor (sin lo que llega del backend). */
class PublicacionesDeEditusTest extends TestCase
{
    use Concerns\EsquemaPlanMedios;

    public function test_lista_lo_publicado_en_editus_sin_lo_del_backend(): void
    {
        config(['services.editus.ingest_token' => 'tok-integracion', 'services.editus.medios' => ['opanoticias' => 'Opanoticias']]);
        $this->crearEsquemaPlanMedios();
        $u = User::factory()->create(['name' => 'Jorge Camilo', 'email' => 'Puentesl.Jorge@prueba.co', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $pg = MetaPage::create(['page_id' => '111', 'name' => 'Opa Noticias', 'medio_slug' => 'opanoticias']);
        $sistema = Campaign::where('is_system', true)->first() ?? Campaign::create(['name' => 'Esnoticia', 'slug' => 'esnoticia', 'is_system' => true, 'is_active' => true]);
        $salud = Campaign::create(['name' => 'Salud 2026', 'slug' => 'salud-2026', 'is_active' => true]);
        $base = ['meta_page_id' => $pg->id, 'user_id' => $u->id, 'network' => 'facebook', 'message' => 'Jornada de vacunación', 'status' => 'success', 'published_at' => '2026-11-02 10:00:00'];
        MetaPost::create($base + ['type' => 'photo', 'fb_post_id' => '111_1', 'campaign_id' => $salud->id, 'batch_uuid' => '11111111-1111-1111-1111-111111111111', 'alcance' => 500, 'visualizaciones' => 800, 'interacciones' => 40]);
        MetaPost::create($base + ['type' => 'video', 'fb_post_id' => '7002', 'campaign_id' => null]);
        MetaPost::create($base + ['type' => 'photo', 'fb_post_id' => '111_2', 'campaign_id' => $sistema->id]);        // llegó del backend (Redes)
        MetaPost::create(['status' => 'error', 'fb_post_id' => null, 'type' => 'photo'] + $base);                     // falló
        MetaPost::create(['published_at' => '2026-10-01 10:00:00', 'type' => 'photo', 'fb_post_id' => '111_3'] + $base); // fuera del rango

        $this->getJson('/api/publicaciones/editus?desde=2026-11-01&hasta=2026-11-03')->assertStatus(401);
        $r = $this->withHeader('X-Editus-Token', 'tok-integracion')->getJson('/api/publicaciones/editus?desde=2026-11-01&hasta=2026-11-03')->assertOk();
        $this->assertSame(['111_1', '7002'], collect($r->json('publicaciones'))->pluck('fb_post_id')->all());
        $p = $r->json('publicaciones.0');
        $this->assertSame(['foto', 'facebook', 'Opa Noticias', 'Puentesl.Jorge@prueba.co', 500, 800, 40],
            [$p['tipo'], $p['red'], $p['pagina'], $p['usuario']['email'], $p['alcance'], $p['visualizaciones'], $p['interacciones']]);
        $this->assertSame('video', $r->json('publicaciones.1.tipo'));
        $this->assertSame(['opanoticias', 'Opanoticias'], [$p['medio_slug'], $p['medio']]);
    }
}
