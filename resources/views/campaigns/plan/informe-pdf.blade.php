<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de métricas – {{ $campana->name }}</title>
    @php
        $src = fn($ref, $max = 1400) => $img($ref, $max);
        $fondo = $img($membrete['fondo'], 2550);
        $enc = $img($membrete['encabezado'], 2000);
        // Márgenes de la hoja (como el Word: 3,75 cm arriba, 3 cm a los lados y abajo)
        $mt = $fondo ? 142 : ($enc ? 125 : 70);
        $mlr = $fondo ? 113 : 80;
        $mb = $fondo ? 113 : (($membrete['pie'] || $membrete['pie_texto'] !== '') ? 95 : 60);
        $pie = $img($membrete['pie'], 2000);
    @endphp
    <style>
        @page { margin: {{ $mt }}px {{ $mlr }}px {{ $mb }}px; }
        .fondo { position: fixed; top: -{{ $mt }}px; left: -{{ $mlr }}px; width: 816px; height: 1056px; z-index: -1; }
        body { margin: 0; }
        .enc { position: fixed; top: -110px; left: -60px; right: -60px; text-align: center; }
        .enc img { max-height: 95px; max-width: 100%; }
        .pie { position: fixed; bottom: -80px; left: -60px; right: -60px; text-align: center; color: #808080; font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; font-weight: bold; font-variant: small-caps; }
        .pie img { max-height: 70px; max-width: 100%; }
    </style>
</head>
<body>
    @if ($fondo)<img class="fondo" src="{{ $fondo }}" alt="">@endif
    @if ($enc)<div class="enc"><img src="{{ $enc }}" alt=""></div>@endif
    @if ($pie)<div class="pie"><img src="{{ $pie }}" alt=""></div>@elseif ($membrete['pie_texto'] !== '')<div class="pie">{!! nl2br(e($membrete['pie_texto'])) !!}</div>@endif
    @include('campaigns.plan._informe-cuerpo')
</body>
</html>
