{{--
    Contrato firmado (PDF).
    Con hoja membreteada del contratante, cada página va sobre ella, con las márgenes del Word de los modelos;
    si no, lleva un encabezado con el logo y el nombre del medio. Después del texto: las firmas de las dos partes
    y, como anexo, los datos que el medio registró en EDITUS.
--}}
@php
    $conMembrete = (bool) $membreteContratante;
    $documentoMedio = $documento;
    $canalesTexto = \App\Models\MedioCampana::textoDe($canales);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato {{ $k->codigo }}</title>
    <style>
        @if ($conMembrete)
        /* Márgenes del Word (carta): arriba 1,48", lados 1,18", abajo 0,89" + el pie de editus */
        @page { margin: 1.48in 1.18in 1in 1.18in; }
        .fondo { position: fixed; top: -1.595in; left: -1.305in; width: 8.646in; height: 11.198in; z-index: -1000; }
        .pie { position: fixed; bottom: -0.2in; left: 0; right: 0; font-size: 6.5pt; color: #6b7280; text-align: center; }
        @else
        @page { margin: 48px 56px 64px; }
        .pie { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 7pt; color: #9ca3af; text-align: center; }
        @endif
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.45; color: #111827; }
        .membrete { width: 100%; margin-bottom: 14px; text-align: center; }
        .membrete img { max-width: 100%; max-height: 120px; }
        .cab { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .cab td { vertical-align: middle; }
        .logo { max-height: 70px; max-width: 160px; }
        .medio { font-size: 13pt; font-weight: bold; color: #00024f; }
        .gris { color: #6b7280; }
        h1 { text-align: center; font-size: 13pt; margin: 12px 0 2px; letter-spacing: .5px; color: #00024f; }
        .sub { text-align: center; color: #4b5563; margin: 0 0 14px; }
        .cuerpo p { margin: 0 0 7px; text-align: justify; }
        .cuerpo h2, .cuerpo h3, .cuerpo h4 { font-size: 10pt; margin: 12px 0 6px; }
        .cuerpo ul, .cuerpo ol { margin: 0 0 7px 22px; padding: 0; }
        .cuerpo li p { margin: 0 0 3px; }
        .cuerpo blockquote { margin: 0 0 7px 22px; }
        .cuerpo table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
        .cuerpo td, .cuerpo th { border: 1px solid #d1d5db; padding: 3px 6px; }
        .firmas-caja { margin-top: 22px; page-break-inside: avoid; }
        table.firmas { width: 100%; border-collapse: collapse; }
        table.firmas td { width: 50%; vertical-align: top; padding: 0 12px 0 0; }
        table.firmas tr.roles td { font-weight: bold; padding-bottom: 4px; }
        table.firmas tr.trazos td { height: 62px; vertical-align: bottom; }
        .firma-img { max-height: 58px; max-width: 190px; }
        .linea { border-top: 1px solid #111827; margin-top: 3px; padding-top: 3px; font-size: 8.5pt; }
        .anexo { margin-top: 26px; page-break-inside: avoid; }
        .anexo h3 { font-size: 9.5pt; margin: 0 0 6px; }
        table.datos { width: 100%; border-collapse: collapse; font-size: 8pt; }
        table.datos td { border: 1px solid #d1d5db; padding: 3px 6px; }
        table.datos td.k { width: 32%; background: #f3f4f6; font-weight: bold; }
    </style>
</head>
<body>
    @if ($conMembrete)<img class="fondo" src="{{ $membreteContratante }}" alt="">@endif
    <div class="pie">{{ __('Contrato No. :n · firmado electrónicamente en EDITUS el :f · IP :ip · huella :h', ['n' => $k->codigo, 'f' => $k->firmado_at?->format('d/m/Y H:i'), 'ip' => $k->ip ?: '—', 'h' => substr((string) $k->cuerpo_hash, 0, 16)]) }}</div>

    @unless ($conMembrete)
        {{-- Sin hoja del contratante: encabezado con el medio --}}
        @if ($membrete)<div class="membrete"><img src="{{ $membrete }}" alt=""></div>@endif
        <table class="cab">
            <tr>
                @if ($logo)<td style="width: 170px"><img class="logo" src="{{ $logo }}" alt=""></td>@endif
                <td>
                    <div class="medio">{{ $k->dato('medio_nombre', $k->medio?->nombre ?? '') }}</div>
                    <div class="gris">{{ $canalesTexto !== '' ? mb_strtoupper(mb_substr($canalesTexto, 0, 1)) . mb_substr($canalesTexto, 1) : $k->dato('clase_medio') }}{{ $k->dato('nit') ? ' · NIT ' . $k->dato('nit') : '' }}</div>
                </td>
            </tr>
        </table>
        <h1>{{ __('CONTRATO No. :n', ['n' => $k->codigo]) }}</h1>
        <p class="sub">{{ $k->dato('tipo_nombre') }} · {{ $c->name }}</p>
    @endunless

    <div class="cuerpo">{!! $k->cuerpo !!}</div>

    {{-- Firmas: el medio a la izquierda y el contratante a la derecha, como en el modelo --}}
    <div class="firmas-caja">
        <table class="firmas">
            <tr class="roles"><td>{{ $rolMedio }}</td><td>{{ $rolContratante }}</td></tr>
            <tr class="trazos">
                <td>@if ($firma)<img class="firma-img" src="{{ $firma }}" alt="">@endif</td>
                <td>@if ($firmaContratante)<img class="firma-img" src="{{ $firmaContratante }}" alt="">@endif</td>
            </tr>
            <tr>
                <td><div class="linea"><strong>{{ mb_strtoupper($k->dato('representante')) }}</strong><br>{{ $documentoMedio }}<br>
                    {{ __('Representante legal de :m', ['m' => $k->dato('razon_social') ?: $k->dato('medio_nombre')]) }}</div></td>
                <td><div class="linea"><strong>{{ mb_strtoupper($contratante['contratante_representante'] ?: __('El contratante')) }}</strong><br>{{ $contratante['contratante_documento'] ?: '' }}<br>
                    {{ $contratante['contratante'] }}{{ $contratante['contratante_nit'] ? ' · NIT ' . $contratante['contratante_nit'] : '' }}</div></td>
            </tr>
        </table>
    </div>

    {{-- Anexo: lo que el medio registró en EDITUS --}}
    <div class="anexo">
        <h3>{{ __('Anexo · Datos del medio registrados en EDITUS') }}</h3>
        <table class="datos">
            <tr><td class="k">{{ __('Medio') }}</td><td>{{ $k->dato('medio_nombre') }}</td></tr>
            @if ($k->dato('razon_social'))<tr><td class="k">{{ __('Razón social') }}</td><td>{{ $k->dato('razon_social') }}</td></tr>@endif
            @if ($k->dato('nit'))<tr><td class="k">{{ __('NIT') }}</td><td>{{ $k->dato('nit') }}</td></tr>@endif
            <tr><td class="k">{{ __('Representante legal') }}</td><td>{{ $k->dato('representante') }} · {{ $documentoMedio }}</td></tr>
            @forelse ($canales as $canal => $x)
                <tr><td class="k">{{ \App\Models\MedioCampana::nombreDe($canal, $x['pauta']) }}</td><td>{{ ($x['dato'] ?? '') ?: '—' }}</td></tr>
            @empty
                @if ($k->dato('sitio'))<tr><td class="k">{{ __('Página web o emisora') }}</td><td>{{ $k->dato('sitio') }}</td></tr>@endif
            @endforelse
            <tr><td class="k">{{ __('Correo') }}</td><td>{{ $k->dato('correo') }}</td></tr>
            @if ($k->dato('telefono'))<tr><td class="k">{{ __('Teléfono') }}</td><td>{{ $k->dato('telefono') }}</td></tr>@endif
            @if ($k->dato('direccion') || $k->dato('ciudad'))<tr><td class="k">{{ __('Dirección') }}</td><td>{{ trim($k->dato('direccion') . ($k->dato('ciudad') ? ', ' . $k->dato('ciudad') : ''), ', ') }}</td></tr>@endif
            @if ($k->dato('banco'))<tr><td class="k">{{ __('Cuenta para los pagos') }}</td><td>{{ $k->dato('banco') }} · {{ $k->dato('tipo_cuenta') }} No. {{ $k->dato('numero_cuenta') }} · {{ __('Titular') }}: {{ $k->dato('titular_cuenta') }} ({{ $k->dato('documento_titular') }})</td></tr>@endif
            @if ($valor)<tr><td class="k">{{ __('Valor del contrato') }}</td><td>{{ $valor }}</td></tr>@endif
            <tr><td class="k">{{ __('Documentos') }}</td><td>{{ $k->rut_path ? __('RUT adjunto') : __('Sin RUT') }} · {{ $k->certificado_bancario_path ? __('Certificado bancario adjunto') : __('Sin certificado bancario') }}</td></tr>
        </table>
    </div>
</body>
</html>
