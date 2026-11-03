<?php

namespace Tests\Feature;

use App\Services\MetaMetricasService;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Métricas de Meta en paralelo: mismos resultados (y respaldos) que antes, en el mismo orden. */
class MetricasEnParaleloTest extends TestCase
{
    public function test_respaldos_y_orden_con_muchas_publicaciones(): void
    {
        config(['services.facebook.version' => 'v23.0']);
        $g = 'graph.facebook.com/v23.0/';
        $fotos = [];
        for ($n = 1; $n <= 30; $n++) $fotos["{$g}111_{$n}/insights*"] = Http::response(['data' => [['name' => 'post_total_media_view_unique', 'values' => [['value' => $n * 10]]]]], 200);
        Http::fake($fotos + [
            // Video de un post «pagina_post»: primero el id del video; sus reproducciones salen con los nombres antiguos
            "{$g}111_77/insights*" => Http::response(['data' => [['name' => 'post_media_view', 'values' => [['value' => 500]]]]], 200),
            "{$g}111_77?fields=object_id*" => Http::response(['object_id' => '7700'], 200),
            "{$g}7700/video_insights?access_token*" => Http::response(['data' => []], 200),
            "{$g}7700/video_insights?metric=total_video_views*" => Http::response(['data' => [['name' => 'total_video_views', 'values' => [['value' => 321]]]]], 200),
            // Página que aún no tiene las métricas nuevas
            "{$g}111_88/insights?metric=post_media_view*" => Http::response(['error' => ['message' => 'Invalid metric']], 400),
            "{$g}111_88/insights?metric=post_impressions*" => Http::response(['data' => [['name' => 'post_impressions_unique', 'values' => [['value' => 77]]]]], 200),
            // Instagram con el nombre antiguo
            "{$g}1789/insights?metric=reach%2Cviews*" => Http::response(['error' => ['message' => 'views no disponible']], 400),
            "{$g}1789/insights?metric=reach%2Cimpressions*" => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 90]]], ['name' => 'likes', 'values' => [['value' => 4]]]]], 200),
            "{$g}*" => Http::response(['reactions' => ['summary' => ['total_count' => 2]], 'comments' => ['summary' => ['total_count' => 1]], 'shares' => ['count' => 1]], 200),
        ]);
        $items = [['red' => 'facebook', 'post_id' => '111_77', 'page_id' => '111', 'tipo' => 'video'], ['red' => 'facebook', 'post_id' => '111_88', 'page_id' => '111'],
            ['red' => 'instagram', 'post_id' => '1789', 'page_id' => '111'], ['red' => 'facebook', 'post_id' => '', 'page_id' => '111']];
        for ($n = 1; $n <= 30; $n++) $items[] = ['red' => 'facebook', 'post_id' => "111_{$n}", 'page_id' => '111'];

        $m = app(MetaMetricasService::class)->metricas($items, ['111' => 'tok']);

        $this->assertCount(34, $m);
        $this->assertSame([true, null, 500, 321, 4], [$m[0]['ok'], $m[0]['alcance'], $m[0]['impresiones'], $m[0]['reproducciones'], $m[0]['interacciones']]);
        $this->assertSame([true, 77], [$m[1]['ok'], $m[1]['alcance']]);
        $this->assertSame([true, 90, 4], [$m[2]['ok'], $m[2]['alcance'], $m[2]['interacciones']]);
        $this->assertSame([false, 'Falta post_id o page_id'], [$m[3]['ok'], $m[3]['error']]);
        $this->assertSame(range(10, 300, 10), array_column(array_slice($m, 4), 'alcance'), 'cada resultado en su lugar');
        Http::assertSent(fn(PeticionHttp $p) => str_contains($p->url(), '7700/video_insights?metric=total_video_views'));
    }
}
