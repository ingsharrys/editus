{{-- Cuerpo del informe de métricas (vista previa y PDF). $src(ref, max) devuelve la URL o el data-URI de una imagen. --}}
<style>
    .inf { font-family: 'Arial Narrow', 'DejaVu Sans Condensed', Arial, sans-serif; color: #111; font-size: 11pt; line-height: 1.35; }
    .inf .titulo { text-align: center; font-weight: bold; font-size: 13pt; margin: 0 0 14px; }
    .inf .capitulo { text-align: center; font-weight: bold; font-size: 12pt; margin: 0 0 12px; }
    .inf .seccion { text-align: center; font-weight: bold; margin: 6px 0 12px; }
    .inf .etiqueta { font-weight: bold; font-style: italic; text-decoration: underline; margin: 12px 0 8px; }
    .inf .desc { text-align: justify; margin: 0 0 8px; }
    .inf ul.datos { margin: 0 0 6px 18px; padding: 0; list-style: square; }
    .inf ul.datos li { margin: 0 0 1px; }
    .inf .medio { margin: 8px 0 4px 30px; }
    .inf table.met { border-collapse: collapse; margin: 0 auto 6px; width: 92%; font-size: 9.5pt; }
    .inf table.met th, .inf table.met td { border: 1px solid #000; padding: 2px 4px; text-align: center; }
    .inf table.met th { background: #FFD966; font-weight: bold; }
    .inf table.met td.url { word-break: break-all; font-size: 8.5pt; }
    .inf a { color: #0563C1; }
    .inf .img { text-align: center; margin: 4px 0 8px; }
    .inf .img img { max-width: 100%; }
    .inf .muestra img { max-height: 300px; }
    .inf .evid img { max-height: 280px; }
    .inf .web { margin: 4px 0 4px 34px; }
    .inf .salto { page-break-before: always; }
</style>
<div class="inf">
    @php $titulo = $titulo ?: $membrete['titulo']; @endphp
    @if ($titulo !== '')<div class="titulo">{{ $titulo }}</div>@endif
    @forelse ($capitulos as $ci => $cap)
        <div class="{{ $ci > 0 ? 'salto' : '' }}">
            @if (count($capitulos) > 1 || $titulo === '')<div class="capitulo">{{ $cap['titulo'] }}</div>@endif
            @foreach ($cap['secciones'] as $si => $sec)
                <div class="{{ $si > 0 ? 'salto' : '' }}">
                    <div class="seccion">{{ $sec['titulo'] }}</div>
                    @foreach ($sec['piezas'] as $pz)
                        <div class="etiqueta">{{ $pz['etiqueta'] }}</div>
                        <p class="desc">{{ $pz['descripcion'] }}</p>
                        <ul class="datos">
                            @foreach ($pz['datos'] as [$k, $v])<li><b>{{ $k }}:</b> {{ $v }}</li>@endforeach
                            @if ($pz['muestra'] && ($m = $src($pz['muestra'])))<li><b>MUESTRA:</b></li>@endif
                        </ul>
                        @if ($pz['muestra'] && $m)<div class="img muestra"><img src="{{ $m }}" alt=""></div>@endif
                        <ul class="datos"><li><b>{{ $pz['clase'] === 'web' ? 'PÁGINAS WEB:' : 'MEDIOS:' }}</b></li></ul>
                        @foreach ($pz['pubs']->values() as $i => $pub)
                            @if ($pz['clase'] === 'web')
                                <div class="web"><span style="font-family: DejaVu Sans, sans-serif">&#10146;</span> {{ $pub['medio'] }}: @if ($pub['url'])<a href="{{ $pub['url'] }}">{{ $pub['url'] }}</a>@endif</div>
                            @else
                                <div class="medio">{{ $i + 1 }}. {{ $pub['medio'] }}</div>
                                <table class="met">
                                    <tr><th style="width:15%">ALCANCE</th><th style="width:17%">VISUALIZACIÓN</th><th style="width:16%">INTERACCIÓN</th><th>URL</th></tr>
                                    <tr><td>{{ $fmt($pub['alcance']) }}</td><td>{{ $fmt($pub['visualizaciones']) }}</td><td>{{ $fmt($pub['interacciones']) }}</td>
                                        <td class="url">@if ($pub['url'])<a href="{{ $pub['url'] }}">{{ $pub['url'] }}</a>@else — @endif</td></tr>
                                </table>
                            @endif
                            @if ($e = $src($pub['evidencia']))<div class="img evid"><img src="{{ $e }}" alt=""></div>@endif
                        @endforeach
                    @endforeach
                </div>
            @endforeach
            @if ($con_resumen)
                <div class="seccion" style="margin-top:18px">RESUMEN DE MÉTRICAS – {{ $cap['titulo'] }}</div>
                <table class="met">
                    <tr><th style="text-align:left">TIPO DE PUBLICACIÓN</th><th>PIEZAS</th><th>PUBLICACIONES</th><th>ALCANCE</th><th>VISUALIZACIÓN</th><th>INTERACCIÓN</th></tr>
                    @php $t = ['piezas' => 0, 'publicaciones' => 0, 'alcance' => 0, 'visualizaciones' => 0, 'interacciones' => 0]; @endphp
                    @foreach ($cap['resumen'] as $r)
                        @php foreach ($t as $k => $_) $t[$k] += $r[$k]; @endphp
                        <tr><td style="text-align:left">{{ $r['titulo'] }}</td><td>{{ $fmt($r['piezas']) }}</td><td>{{ $fmt($r['publicaciones']) }}</td><td>{{ $fmt($r['alcance']) }}</td><td>{{ $fmt($r['visualizaciones']) }}</td><td>{{ $fmt($r['interacciones']) }}</td></tr>
                    @endforeach
                    <tr><td style="text-align:left"><b>TOTAL</b></td>@foreach (['piezas', 'publicaciones', 'alcance', 'visualizaciones', 'interacciones'] as $k)<td><b>{{ $fmt($t[$k]) }}</b></td>@endforeach</tr>
                </table>
            @endif
        </div>
    @empty
        <p style="text-align:center; font-style: italic">No hay piezas con publicaciones para los filtros elegidos.</p>
    @endforelse
</div>
