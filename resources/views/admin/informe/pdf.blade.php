<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <title>{{ __('reporte editus – :titulo', ['titulo' => $summary['titulo'] ?: '—']) }}</title>
    <style>
        @page {
            margin: 30px 25px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111;
            font-size: 12px;
        }

        h1 {
            font-size: 20px;
            margin: 0 0 6px;
            text-transform: capitalize;
            line-height: 1.25;
        }


        h2 {
            font-size: 14px;
            margin: 10px 0 6px;
        }

        .muted {
            color: #666;
        }

        .hr {
            height: 1px;
            background: #e5e7eb;
            margin: 10px 0 12px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        th,
        td {
            border-bottom: 1px solid #f0f0f0;
            text-align: left;
            padding: 6px 4px;
        }

        th {
            color: #666;
            font-weight: 600;
        }

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #f3f4f6;
            font-size: 11px;
        }

        .img {
            max-width: 520px;
            max-height: 420px;
            object-fit: contain;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }

        .footer {
            position: fixed;
            bottom: 10px;
            left: 25px;
            right: 25px;
            font-size: 10px;
            color: #888;
        }

        .mb-4 {
            margin-bottom: 14px;
        }

        .mb-2 {
            margin-bottom: 8px;
        }
    </style>
</head>

<body>

    {{-- Encabezado --}}
    <h1>{{ __('reporte editus – :titulo', ['titulo' => $summary['titulo'] ?: '—']) }}</h1>
    <div class="muted mb-4">
        @if ($summary['effective_at'])
            · {{ __('Publicación:') }}
            {{ optional($summary['effective_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
        @endif
    </div>

    {{-- Resumen --}}
    <div class="grid-2 mb-4">
        <div class="card">
            <h2>{{ __('Resumen') }}</h2>
            <div class="hr"></div>
            <table>
                <tbody>
                    <tr>
                        <th>{{ __('Páginas con datos') }}</th>
                        <td>{{ number_format($summary['total_paginas']) }}</td>
                    </tr>
                    <tr>
                        <th>{{ __('Total alcance') }}</th>
                        <td>{{ number_format($summary['total_alcance']) }}</td>
                    </tr>
                    <tr>
                        <th>{{ __('Total visualizaciones') }}</th>
                        <td>{{ number_format($summary['total_visualizaciones']) }}</td>
                    </tr>
                    <tr>
                        <th>{{ __('Total interacciones') }}</th>
                        <td>{{ number_format($summary['total_interacciones']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

    </div>

    {{-- Detalle por página con evidencia --}}
    @forelse ($rows as $row)
        <div class="card">
            <strong>{{ $row['pagina'] }}</strong>
            @if ($row['permalink'])
                <span class="pill">FB</span>
            @endif

            <table>
                <thead>
                    <tr>
                        <th>{{ __('Alcance') }}</th>
                        <th>{{ __('Visualizaciones') }}</th>
                        <th>{{ __('Interacciones') }}</th>
                        <th>{{ __('Enlace') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ number_format($row['alcance']) }}</td>
                        <td>{{ number_format($row['visualizaciones']) }}</td>
                        <td>{{ number_format($row['interacciones']) }}</td>
                        <td style="word-break: break-all;">
                            @if ($row['permalink'])
                                {{ $row['permalink'] }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>

            @if ($row['evidencia_src'])
                <div class="mb-2"></div>
                <img src="{{ $row['evidencia_src'] }}"
                    style="width:520px; max-width:520px; height:auto;
            display:block; margin:8px auto; 
            border:1px solid #e5e7eb; border-radius:6px;"
                    alt="{{ __('Evidencia :pagina', ['pagina' => $row['pagina']]) }}">
            @endif


        </div>
    @empty
        <p class="muted">{{ __('No hay páginas con datos para este reporte.') }}</p>
    @endforelse

    <div class="footer">
        Editus · {{ config('app.url') ?? request()->getHost() }} · {{ now()->format('Y') }}
    </div>
</body>

</html>
