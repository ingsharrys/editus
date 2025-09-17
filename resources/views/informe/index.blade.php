@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8">

        <div class="flex items-center justify-between mb-6 mt-10">
            <h1 class="text-2xl md:text-3xl text-white font-bold">Informe general</h1>
        </div>

        {{-- Filtros --}}
        {{-- Filtros bonitos --}}
        {{-- Filtros (todo en una fila) --}}
        <div class="rounded-2xl border bg-white/95 backdrop-blur p-4 shadow-sm mb-6">
            <div class="overflow-x-auto">
                <form method="GET" class="flex items-center gap-3 whitespace-nowrap">

                    {{-- Buscar --}}
                    <div class="relative min-w-[280px] w-[380px]">
                       
                        <input type="text" name="q" value="{{ $q }}"
                            placeholder="Buscar por título o mensaje…"
                            class="h-11 w-full pl-10 pr-3 rounded-xl border-gray-300 shadow-sm
                      focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
                    </div>

                    {{-- Página --}}
                    <div class="relative min-w-[220px] w-[280px]">
                     
                        <select name="page_id"
                            class="h-11 w-full pl-10 pr-9 rounded-xl border-gray-300 shadow-sm
                       focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500
                       appearance-none">
                            <option value="">Todas las páginas</option>
                            @foreach ($pages as $p)
                                <option value="{{ $p->id }}" @selected((string) $pageId === (string) $p->id)>{{ $p->name }}
                                </option>
                            @endforeach
                        </select>
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20"
                                fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.201l3.71-3.97a.75.75 0 1 1 1.08 1.04l-4.24 4.54a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z"
                                    clip-rule="evenodd" />
                            </svg>
                        </span>
                    </div>

                    {{-- Botones --}}
                    <button type="submit"
                        class="h-11 inline-flex items-center px-5 rounded-xl
                     bg-indigo-600 text-white font-medium shadow-sm hover:bg-indigo-700">
                        Filtrar
                    </button>

                    @if ($q || $pageId)
                        <a href="{{ route('informe.index') }}"
                            class="h-11 inline-flex items-center px-4 rounded-xl border
                  bg-white text-gray-700 hover:bg-gray-50">
                            Limpiar
                        </a>
                    @endif

                </form>
            </div>
        </div>




        {{-- Tarjetas de totales --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <div class="text-sm ml-2 text-gray-500">Total publicaciones</div>
                <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_posts ?? 0) }}</div>
            </div>
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <div class="text-sm ml-2 text-gray-500">Total alcance</div>
                <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_alcance ?? 0) }}</div>
            </div>
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <div class="text-sm ml-2 text-gray-500">Total visualizaciones</div>
                <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_visualizaciones ?? 0) }}
                </div>
            </div>
            <div class="rounded-2xl border bg-white p-5 shadow-sm">
                <div class="text-sm ml-2 text-gray-500">Total interacciones</div>
                <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_interacciones ?? 0) }}
                </div>
            </div>
        </div>

        {{-- Grid de publicaciones (agrupadas por batch) --}}
        @if ($groups->count() === 0)
            <div class="p-6 border rounded-2xl bg-gray-50">
                <p class="text-gray-600">No hay publicaciones para mostrar.</p>
            </div>
        @else
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-10">
                @foreach ($groups as $g)
                    <div
                        class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition">
                        <div class="text-xs text-gray-500 mb-1">
                            {{ \Carbon\Carbon::parse($g->effective_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                        </div>
                        <h3 class="font-semibold line-clamp-2 mb-2">{{ $g->message_sample ?: '— Sin texto —' }}</h3>

                        <div class="grid grid-cols-3 gap-2 text-center text-xs mb-3">
                            <div class="rounded-lg bg-gray-50 p-2">
                                <div class="font-semibold">{{ number_format($g->alcance_sum) }}</div>
                                <div class="text-gray-500">Alcance</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-2">
                                <div class="font-semibold">{{ number_format($g->visualizaciones_sum) }}</div>
                                <div class="text-gray-500">Visualizaciones</div>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-2">
                                <div class="font-semibold">{{ number_format($g->interacciones_sum) }}</div>
                                <div class="text-gray-500">Interacciones</div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-500">{{ $g->posts_count }} pág.</span>
                            <div class="flex items-center gap-3">
                                @if ($g->any_permalink)
                                    <a href="{{ $g->any_permalink }}" target="_blank" class="underline">Ver en FB</a>
                                @endif
                                <a href="{{ route('informe.show', $g->group_key) }}"
                                    class="text-indigo-600 hover:underline">
                                    Ver detalle
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $groups->links() }}
            </div>
        @endif
    </div>
@endsection
