@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-8"
     x-data="{
        show:false, img:null,
        open(src){ this.img = src; this.show = true },
        close(){ this.show = false; this.img = null }
     }"
     @keydown.escape.window="close()">

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
                <div class="text-gray-500 text-sm">Publicaciones</div>
                <div class="text-2xl font-semibold mt-1">{{ number_format($summary['total_posts']) }}</div>
            </div>
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
                        {{-- NUEVO: columna Evidencia --}}
                        <th class="py-2 pr-4">Evidencia</th>
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

                            {{-- NUEVO: celda Evidencia con miniatura / ícono y modal --}}
                            <td class="py-2 pr-4">
                                @if ($p->evidencia_path)
                                    @php $src = \Illuminate\Support\Facades\Storage::url($p->evidencia_path); @endphp
                                    <button type="button" @click="open('{{ $src }}')"
                                            class="group inline-flex items-center gap-2">
                                        <img src="{{ $src }}" alt="Evidencia"
                                             class="h-10 w-10 rounded object-cover border">
                                        <span class="text-indigo-600 underline opacity-0 group-hover:opacity-100 text-xs">Ver</span>
                                    </button>
                                @else
                                    <div class="h-10 w-10 rounded bg-gray-100 flex items-center justify-center text-gray-400"
                                         title="Sin evidencia">
                                        {{-- ícono imagen --}}
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm0 2v9.586l-3.293-3.293a1 1 0 0 0-1.414 0L8 18H5V5h14ZM9 9a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
                                        </svg>
                                    </div>
                                @endif
                            </td>

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
                        <td class="py-2 pr-4">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- MODAL de evidencia --}}
    <div x-show="show" x-transition.opacity
         class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/60" @click="close()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b">
                    <h4 class="font-medium text-sm">Evidencia</h4>
                    <div class="flex items-center gap-2">
                        <template x-if="img">
                            <a :href="img" target="_blank"
                               class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50">Abrir en pestaña</a>
                        </template>
                        <button @click="close()" class="p-1 rounded hover:bg-gray-100" aria-label="Cerrar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 8.586 4.293 2.879A1 1 0 1 0 2.879 4.293L8.586 10l-5.707 5.707a1 1 0 0 0 1.414 1.414L10 11.414l5.707 5.707a1 1 0 0 0 1.414-1.414L11.414 10l5.707-5.707A1 1 0 0 0 15.707 2.879L10 8.586Z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="p-4">
                    <img :src="img" alt="Evidencia"
                         class="w-full h-auto max-h-[70vh] object-contain rounded-lg border">
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
