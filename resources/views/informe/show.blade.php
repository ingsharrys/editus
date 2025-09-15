@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6 mt-10">
        <h1 class="text-2xl text-white md:text-3xl font-bold">Detalle de publicación</h1>
        <a href="{{ route('informe.index') }}" class="text-sm underline text-white">← Volver</a>
    </div>

    <div class="rounded-2xl border bg-white p-6 shadow-sm mb-6">
        <div class="text-sm text-gray-500 mb-1">
            {{ optional($summary['effective_at'])->timezone(config('app.timezone'))?->format('d/m/Y H:i') ?? '—' }}
        </div>
        <h2 class="text-lg font-semibold mb-4">{{ $summary['message_sample'] ?: '— Sin texto —' }}</h2>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-gray-500 text-sm">Alcance</div>
                <div class="text-2xl font-semibold mt-1">{{ number_format($summary['alcance_sum']) }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-gray-500 text-sm">Visualizaciones</div>
                <div class="text-2xl font-semibold mt-1">{{ number_format($summary['visualizaciones_sum']) }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-gray-500 text-sm">Interacciones</div>
                <div class="text-2xl font-semibold mt-1">{{ number_format($summary['interacciones_sum']) }}</div>
            </div>
        </div>

     
    </div>

    {{-- Desglose por página --}}
    <div class="rounded-2xl border bg-white p-6 shadow-sm">
        <h3 class="text-lg font-semibold mb-4">Por página</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 pr-4">Página</th>
                        <th class="py-2 pr-4">Alcance</th>
                        <th class="py-2 pr-4">Visualizaciones</th>
                        <th class="py-2 pr-4">Interacciones</th>
                        <th class="py-2 pr-4">Enlace</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($byPage as $p)
                        <tr class="border-b last:border-0">
                            <td class="py-2 pr-4 font-medium">{{ $p->page?->name ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ number_format((int)($p->alcance ?? 0)) }}</td>
                            <td class="py-2 pr-4">{{ number_format((int)($p->visualizaciones ?? 0)) }}</td>
                            <td class="py-2 pr-4">{{ number_format((int)($p->interacciones ?? 0)) }}</td>
                            <td class="py-2 pr-4">
                                @if($p->fb_permalink_url)
                                    <a href="{{ $p->fb_permalink_url }}" target="_blank" class="underline">Abrir</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td class="py-2 pr-4 text-right">Totales</td>
                        <td class="py-2 pr-4">{{ number_format($summary['alcance_sum']) }}</td>
                        <td class="py-2 pr-4">{{ number_format($summary['visualizaciones_sum']) }}</td>
                        <td class="py-2 pr-4">{{ number_format($summary['interacciones_sum']) }}</td>
                        <td class="py-2 pr-4">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>
@endsection
