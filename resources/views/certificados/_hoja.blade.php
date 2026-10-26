{{-- Hoja del certificado (la usan la vista web y el PDF). CSS simple para que dompdf lo pinte igual. --}}
<style>
    .cert-hoja { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #111; font-size: 13px; line-height: 1.55; }
    .cert-logo { text-align: center; margin: 0 0 18px; min-height: 40px; }
    .cert-logo img { max-height: 120px; max-width: 260px; }
    .cert-titulo { text-align: center; font-weight: bold; font-size: 15px; letter-spacing: .5px; margin: 6px 0 22px; }
    .cert-cuerpo { text-align: justify; margin: 0 0 18px; }
    .cert-tabla { width: 100%; border-collapse: collapse; margin: 0 0 22px; font-size: 12px; }
    .cert-tabla th, .cert-tabla td { border: 1px solid #333; padding: 6px 8px; text-align: left; vertical-align: top; }
    .cert-tabla th { background: #f1f1f1; font-weight: bold; text-transform: uppercase; font-size: 11px; }
    .cert-tabla .cert-vacia { text-align: center; color: #666; font-style: italic; }
    .cert-cierre { text-align: justify; margin: 0 0 34px; }
    .cert-firma { margin-top: 10px; }
    .cert-firma img { max-height: 90px; max-width: 260px; display: block; margin-bottom: -6px; }
    .cert-firma .cert-sinfirma { height: 70px; width: 240px; border-bottom: 1px dashed #999; color: #999; font-size: 11px; }
    .cert-firma .cert-linea { width: 260px; border-top: 1px solid #111; padding-top: 4px; }
    .cert-firma strong { display: block; }
</style>
<div class="cert-hoja">
    <div class="cert-logo">@if ($logo)<img src="{{ $logo }}" alt="{{ $participante->medio_nombre }}">@endif</div>
    <div class="cert-titulo">CERTIFICA:</div>
    <p class="cert-cuerpo">{!! $cuerpo !!}</p>
    <table class="cert-tabla">
        <thead><tr><th style="width:42%">Programa</th><th style="width:30%">Sub programa</th><th>Tipo de pieza</th></tr></thead>
        <tbody>
            @forelse ($piezas as $f)
                <tr><td>{{ $f['programa'] }}</td><td>{{ $f['subprograma'] }}</td><td>{{ $f['tipo'] }}@if ($f['cantidad'] > 1) ({{ $f['cantidad'] }})@endif</td></tr>
            @empty
                <tr><td colspan="3" class="cert-vacia">Aún no hay publicaciones de la campaña en este medio. La tabla se llena sola con cada publicación.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p class="cert-cierre">{!! $cierre !!}</p>
    <div class="cert-firma">
        @if ($firma)<img src="{{ $firma }}" alt="Firma">@else<div class="cert-sinfirma">Pendiente de firma</div>@endif
        <div class="cert-linea">
            <strong>{{ $nombre }}</strong>
            {{ $documento }}<br>
            @if ($cargo){{ $cargo }}@endif
        </div>
    </div>
</div>
