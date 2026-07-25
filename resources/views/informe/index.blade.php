@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8">

        <!-- HEADER -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8 mt-10">
    
            <div class="text-[#00024f]">
                <h1 class="text-3xl font-bold">
                    Informe general
                </h1>
    
                <p class="mt-1 text-sm">
                    Analiza el rendimiento de tus publicaciones y páginas sincronizadas.
                </p>
            </div>
    
        </div>
    
        <!-- FILTROS -->
        <div class="rounded-3xl border border-white/10 bg-white/95 backdrop-blur-md p-5 shadow-xl mb-8">
    
            <form method="GET"
                  class="grid grid-cols-1 lg:grid-cols-[1fr_300px_auto_auto] gap-4">
    
                <!-- BUSCADOR -->
                <div class="relative">
    
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                        🔍
                    </span>
    
                    <input type="text"
                           name="q"
                           value="{{ $q }}"
                           placeholder="Buscar por título o contenido..."
                           class="h-12 w-full rounded-2xl border border-gray-200
                                  bg-gray-50/70 pl-11 pr-4 text-sm
                                  focus:border-indigo-500
                                  focus:ring-4 focus:ring-indigo-100
                                  outline-none transition">
    
                </div>
    
                <!-- SELECT -->
                <div class="relative">
    
                    <select name="page_id"
                        class="h-12 w-full appearance-none rounded-2xl
                               border border-gray-200 bg-gray-50/70
                               px-4 pr-10 text-sm
                               focus:border-indigo-500
                               focus:ring-4 focus:ring-indigo-100
                               outline-none transition">
    
                        <option value="">Todas las páginas</option>
    
                        @foreach ($pages as $p)
                            <option value="{{ $p->id }}"
                                @selected((string) $pageId === (string) $p->id)>
                                {{ $p->name }}
                            </option>
                        @endforeach
    
                    </select>
    
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center"> <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor"> <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.201l3.71-3.97a.75.75 0 1 1 1.08 1.04l-4.24 4.54a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /> </svg> </span>
    
                </div>
    
                <!-- BOTÓN -->
                <button type="submit"
                    class="h-12 px-6 rounded-2xl bg-indigo-600
                           text-white text-sm font-medium
                           hover:bg-indigo-700 shadow-sm transition">
    
                    Filtrar
    
                </button>
    
                <!-- LIMPIAR -->
                @if ($q || $pageId)
    
                    <a href="{{ route('informe.index') }}"
                       class="h-12 inline-flex items-center justify-center
                              px-5 rounded-2xl border border-gray-200
                              bg-white text-sm text-gray-700
                              hover:bg-gray-50 transition">
    
                        Limpiar
    
                    </a>
    
                @endif
    
            </form>
    
        </div>




        <!-- KPIs -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5 mb-10">

    <!-- CARD -->
    <div class="rounded-3xl bg-white/95 backdrop-blur-md
                border border-white/20 p-5 shadow-sm">

        <div class="flex items-start justify-between">

            <div>
                <p class="text-sm text-gray-500">
                    Total publicaciones
                </p>

                <h3 class="mt-2 text-xl md:text-3xl font-bold text-gray-800">
                    {{ number_format($totals->total_posts ?? 0) }}
                </h3>
            </div>

            <div class="w-12 h-12 rounded-2xl bg-indigo-50
                        hidden sm:flex items-center justify-center text-xl">
                📝
            </div>

        </div>

    </div>

    <!-- CARD -->
    <div class="rounded-3xl bg-white/95 backdrop-blur-md
                border border-white/20 p-5 shadow-sm">

        <div class="flex items-start justify-between">

            <div>
                <p class="text-sm text-gray-500">
                    Alcance total
                </p>

                <h3 class="mt-2 text-xl md:text-3xl font-bold text-gray-800">
                    {{ number_format($totals->total_alcance ?? 0) }}
                </h3>
            </div>

            <div class="w-12 h-12 rounded-2xl bg-emerald-50
                        hidden sm:flex items-center justify-center text-xl">
                📡
            </div>

        </div>

    </div>

    <!-- CARD -->
    <div class="rounded-3xl bg-white/95 backdrop-blur-md
                border border-white/20 p-5 shadow-sm">

        <div class="flex items-start justify-between">

            <div>
                <p class="text-sm text-gray-500">
                    Visualizaciones
                </p>

                <h3 class="mt-2 text-xl md:text-3xl font-bold text-gray-800">
                    {{ number_format($totals->total_visualizaciones ?? 0) }}
                </h3>
            </div>

            <div class="w-12 h-12 rounded-2xl bg-violet-50
                        hidden sm:flex items-center justify-center text-xl">
                👁️
            </div>

        </div>

    </div>

    <!-- CARD -->
    <div class="rounded-3xl bg-white/95 backdrop-blur-md
                border border-white/20 p-5 shadow-sm">

        <div class="flex items-start justify-between">

            <div>
                <p class="text-sm text-gray-500">
                    Interacciones
                </p>

                <h3 class="mt-2 text-xl md:text-3xl font-bold text-gray-800">
                    {{ number_format($totals->total_interacciones ?? 0) }}
                </h3>
            </div>

            <div class="w-12 h-12 rounded-2xl bg-amber-50
                        hidden sm:flex items-center justify-center text-xl">
                ❤️
            </div>

        </div>

    </div>

</div>

        {{-- Grid de publicaciones (agrupadas por batch) --}}
        @if ($groups->count() === 0)

            <div class="rounded-3xl bg-white/95 p-10 text-center shadow-sm">
        
                <div class="text-5xl mb-4">
                    📭
                </div>
        
                <h3 class="text-lg font-semibold text-gray-800">
                    No hay informes disponibles
                </h3>
        
                <p class="mt-1 text-sm text-gray-500">
                    Intenta cambiar los filtros o publicar nuevo contenido.
                </p>
        
            </div>
        
        @else
        
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-6">
        
            @foreach ($groups as $g)
        
                <div class="group rounded-3xl border border-white/20
                            bg-white/95 backdrop-blur-md
                            p-5 shadow-sm hover:shadow-xl
                            hover:-translate-y-1 transition-all duration-300">
        
                    <!-- TOP -->
                    <div class="flex items-start justify-between gap-4">
        
                        <div>
        
                            <div class="text-xs text-gray-400">
                                {{ \Carbon\Carbon::parse($g->effective_at)
                                    ->timezone(config('app.timezone'))
                                    ->format('d/m/Y • H:i') }}
                            </div>
        
                            <h3 class="mt-2 text-[15px] font-semibold
                                       text-gray-800 line-clamp-2">
        
                                {{ $g->message_sample ?: '— Sin contenido —' }}
        
                            </h3>
        
                        </div>
        
                    </div>
        
                    <!-- MÉTRICAS -->
                    <div class="mt-5 grid grid-cols-3 gap-3">
        
                        <div class="rounded-2xl bg-gray-50 p-3 text-center">
        
                            <div class="text-lg font-bold text-gray-800">
                                {{ number_format($g->alcance_sum) }}
                            </div>
        
                            <div class="mt-1 text-[11px] text-gray-500">
                                Alcance
                            </div>
        
                        </div>
        
                        <div class="rounded-2xl bg-gray-50 p-3 text-center">
        
                            <div class="text-lg font-bold text-gray-800">
                                {{ number_format($g->visualizaciones_sum) }}
                            </div>
        
                            <div class="mt-1 text-[11px] text-gray-500">
                                Vistas
                            </div>
        
                        </div>
        
                        <div class="rounded-2xl bg-gray-50 p-3 text-center">
        
                            <div class="text-lg font-bold text-gray-800">
                                {{ number_format($g->interacciones_sum) }}
                            </div>
        
                            <div class="mt-1 text-[11px] text-gray-500">
                                Interacciones
                            </div>
        
                        </div>
        
                    </div>
        
                    <!-- FOOTER -->
                    <div class="mt-5 flex items-center justify-between">
        
                        <span class="text-xs text-gray-500">
                            {{ $g->posts_count }} páginas
                        </span>
        
                        <div class="flex items-center gap-3">
        
                            @if ($g->any_permalink)
        
                                <a href="{{ $g->any_permalink }}"
                                   target="_blank"
                                   class="text-sm text-gray-500 hover:text-gray-700 transition">
        
                                    Ver FB
        
                                </a>
        
                            @endif
        
                            <a href="{{ route('informe.show', $g->group_key) }}"
                               class="inline-flex items-center gap-2
                                      text-sm font-medium text-indigo-600
                                      hover:text-indigo-700 transition">
        
                                Ver detalle →
        
                            </a>
        
                        </div>
        
                    </div>
        
                </div>
        
            @endforeach
        
        </div>
        
        <div class="mt-6"> {{ $groups->links() }} </div>
        
        @endif
    </div>
@endsection
