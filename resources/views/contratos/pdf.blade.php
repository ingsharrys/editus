{{-- Contrato firmado (PDF): encabezado del medio, texto tal como se firmó y firmas de las dos partes. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato {{ $k->codigo }}</title>
    <style>
        @page { margin: 48px 56px 64px; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; line-height: 1.55; color: #1f2937; }
        .membrete { width: 100%; margin-bottom: 14px; text-align: center; }
        .membrete img { max-width: 100%; max-height: 120px; }
        .cab { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .cab td { vertical-align: middle; }
        .logo { max-height: 70px; max-width: 160px; }
        .medio { font-size: 15px; font-weight: bold; color: #00024f; }
        .gris { color: #6b7280; }
        h1 { text-align: center; font-size: 15px; margin: 14px 0 2px; letter-spacing: .5px; color: #00024f; }
        .sub { text-align: center; color: #4b5563; margin: 0 0 14px; }
        table.datos { width: 100%; border-collapse: collapse; margin: 0 0 16px; }
        table.datos td { border: 1px solid #d1d5db; padding: 4px 7px; }
        table.datos td.k { width: 30%; background: #f3f4f6; font-weight: bold; }
        .cuerpo p { margin: 0 0 8px; text-align: justify; }
        .cuerpo h2, .cuerpo h3, .cuerpo h4 { font-size: 11.5px; margin: 12px 0 6px; color: #111827; }
        .cuerpo ul, .cuerpo ol { margin: 0 0 8px 18px; padding: 0; }
        .cuerpo table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
        .cuerpo td, .cuerpo th { border: 1px solid #d1d5db; padding: 3px 6px; }
        .firmas-caja { margin-top: 30px; page-break-inside: avoid; }
        table.firmas { width: 100%; border-collapse: collapse; }
        table.firmas td { width: 50%; vertical-align: top; padding: 0 14px; }
        table.firmas tr.trazos td { height: 64px; vertical-align: bottom; }
        .firma-img { max-height: 60px; max-width: 200px; }
        .linea { border-top: 1px solid #111827; margin-top: 4px; padding-top: 4px; }
        .pie { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="pie">{{ __('Firmado electrónicamente en editus el :f · IP :ip · huella :h', ['f' => $k->firmado_at?->format('d/m/Y H:i'), 'ip' => $k->ip ?: '—', 'h' => substr((string) $k->cuerpo_hash, 0, 16)]) }}</div>

    @if ($membrete)
        <div class="membrete"><img src="{{ $membrete }}" alt=""></div>
    @endif
    <table class="cab">
        <tr>
            @if ($logo)<td style="width: 170px"><img class="logo" src="{{ $logo }}" alt=""></td>@endif
            <td>
                <div class="medio">{{ $k->dato('medio_nombre', $k->medio?->nombre ?? '') }}</div>
                <div class="gris">{{ $k->dato('clase_medio') }}{{ $k->dato('sitio') ? ' · ' . $k->dato('sitio') : '' }}{{ $k->dato('nit') ? ' · NIT ' . $k->dato('nit') : '' }}</div>
            </td>
        </tr>
    </table>

    <h1>{{ __('CONTRATO No. :n', ['n' => $k->codigo]) }}</h1>
    <p class="sub">{{ $k->dato('tipo_nombre') }} · {{ $c->name }}</p>

    <table class="datos">
        <tr><td class="k">{{ __('Medio') }}</td><td>{{ $k->dato('medio_nombre') }} ({{ $k->dato('clase_medio') }})</td></tr>
        @if ($k->dato('sitio'))<tr><td class="k">{{ __('Página web o emisora') }}</td><td>{{ $k->dato('sitio') }}</td></tr>@endif
        @if ($k->dato('nit'))<tr><td class="k">{{ __('NIT') }}</td><td>{{ $k->dato('nit') }}</td></tr>@endif
        <tr><td class="k">{{ __('Representante legal') }}</td><td>{{ $k->dato('representante') }}</td></tr>
        <tr><td class="k">{{ __('Identificación') }}</td><td>{{ $documento }}</td></tr>
        <tr><td class="k">{{ __('Correo') }}</td><td>{{ $k->dato('correo') }}</td></tr>
        @if ($k->dato('telefono'))<tr><td class="k">{{ __('Teléfono') }}</td><td>{{ $k->dato('telefono') }}</td></tr>@endif
        @if ($k->dato('direccion') || $k->dato('ciudad'))<tr><td class="k">{{ __('Dirección') }}</td><td>{{ trim($k->dato('direccion') . ($k->dato('ciudad') ? ', ' . $k->dato('ciudad') : ''), ', ') }}</td></tr>@endif
        <tr><td class="k">{{ __('Documentos') }}</td><td>{{ $k->rut_path ? __('RUT adjunto') : __('Sin RUT') }} · {{ $k->certificado_bancario_path ? __('Certificado bancario adjunto') : __('Sin certificado bancario') }}</td></tr>
    </table>

    <div class="cuerpo">{!! $k->cuerpo !!}</div>

    {{-- Dos filas (trazos y datos) para que las dos líneas de firma queden a la misma altura --}}
    <div class="firmas-caja">
        <table class="firmas">
            <tr class="trazos">
                <td>@if ($firmaContratante)<img class="firma-img" src="{{ $firmaContratante }}" alt="">@endif</td>
                <td>@if ($firma)<img class="firma-img" src="{{ $firma }}" alt="">@endif</td>
            </tr>
            <tr>
                <td>
                    <div class="linea"><strong>{{ mb_strtoupper($cfg['contratante_representante'] ?: __('El contratante')) }}</strong><br>
                        {{ $cfg['contratante_documento'] }}{{ $cfg['contratante_documento'] && $cfg['contratante'] ? ' · ' : '' }}{{ $cfg['contratante'] }}{{ $cfg['contratante_nit'] ? ' · NIT ' . $cfg['contratante_nit'] : '' }}</div>
                </td>
                <td>
                    <div class="linea"><strong>{{ mb_strtoupper($k->dato('representante')) }}</strong><br>
                        {{ $documento }} · {{ __('Representante legal de :m', ['m' => $k->dato('medio_nombre')]) }}</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
