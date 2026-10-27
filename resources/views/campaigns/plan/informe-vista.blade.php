<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vista previa – Informe de métricas – {{ $campana->name }}</title>
    @php
        $src = fn($ref, $max = 1400) => \App\Services\ProductosService::url($c, $ref);
        $fmt = fn($n) => $inf->numero((int) $n);
        $fondo = \App\Services\ProductosService::url($c, $membrete['fondo']);
        $enc = \App\Services\ProductosService::url($c, $membrete['encabezado']);
        $pie = \App\Services\ProductosService::url($c, $membrete['pie']);
        $qs = http_build_query(array_filter($filtros, fn($v) => $v !== null && $v !== ''));
    @endphp
    <style>
        body { margin: 0; background: #e5e7eb; font-family: Arial, sans-serif; }
        .barra { position: sticky; top: 0; z-index: 2; background: #00024f; color: #fff; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 14px; }
        .barra a { color: #fff; background: rgba(255,255,255,.12); padding: 6px 12px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .hoja { background: #fff; max-width: 816px; margin: 20px auto; padding: 28px 80px 50px; box-shadow: 0 1px 4px rgba(0,0,0,.15); box-sizing: border-box; }
        .hoja .enc { text-align: center; margin: -8px -50px 18px; } .hoja .enc img { max-width: 100%; max-height: 110px; }
        .hoja .pie { text-align: center; color: #808080; font-weight: bold; font-variant: small-caps; font-size: 12px; margin-top: 30px; border-top: 1px solid #eee; padding-top: 8px; }
        .hoja .pie img { max-width: 100%; max-height: 80px; }
        .hoja .salto { border-top: 2px dashed #e5e7eb; margin-top: 24px; padding-top: 18px; }
        @media (max-width: 700px) { .hoja { padding: 18px 16px; margin: 10px 0; } .hoja .enc { margin: 0 0 12px; } }
    </style>
</head>
<body>
    <div class="barra">
        <strong style="margin-right:auto">Vista previa · {{ $campana->name }}</strong>
        <a href="{{ route('campaigns.informes.generar', [$c, 'word']) . ($qs ? '?' . $qs : '') }}">Descargar Word</a>
        <a href="{{ route('campaigns.informes.generar', [$c, 'pdf']) . ($qs ? '?' . $qs : '') }}">Descargar PDF</a>
    </div>
    <div class="hoja" @if ($fondo) style="background: #fff url('{{ $fondo }}') top center / 100% auto repeat-y; padding-top: 140px;" @endif>
        @if ($enc)<div class="enc"><img src="{{ $enc }}" alt=""></div>@endif
        @include('campaigns.plan._informe-cuerpo')
        @if ($pie)<div class="pie"><img src="{{ $pie }}" alt=""></div>@elseif ($membrete['pie_texto'] !== '')<div class="pie">{!! nl2br(e($membrete['pie_texto'])) !!}</div>@endif
    </div>
</body>
</html>
