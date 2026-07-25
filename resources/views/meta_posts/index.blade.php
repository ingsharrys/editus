@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-4 sm:p-6 mt-8">

    <!-- HEADER -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">

        <div class="text-[#00024f]">
            <h1 class="text-2xl font-bold">
                Publicaciones enviadas
            </h1>

            <p class="text-sm mt-1">
                Historial de publicaciones realizadas en páginas sincronizadas.
            </p>
        </div>

        <!-- FUTUROS FILTROS -->
        <div class="flex items-center gap-2">
            <button
                class="px-4 py-2 rounded-lg bg-blue-600 text-white text-xs font-medium hover:bg-blue-700 transition">
                Recientes
            </button>
        </div>

    </div>

    @if($groups->isEmpty())

        <!-- EMPTY -->
        <div class="rounded-3xl bg-white/95 border border-white/20 shadow-sm p-10 text-center">

            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100">
                📭
            </div>

            <h3 class="text-lg font-semibold text-gray-800">
                No hay publicaciones
            </h3>

            <p class="mt-1 text-sm text-gray-500">
                Aún no has realizado publicaciones.
            </p>

        </div>

    @else

        <!-- GRID -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-5">

            @foreach($groups as $g)

                @php

                    $typeStyles = match($g->type) {

                        'text' => [
                            'badge' => 'bg-blue-50 text-blue-700 border-blue-100',
                            'icon'  => '📝',
                            'label' => 'Texto',
                        ],

                        'photo' => [
                            'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
                            'icon'  => '🖼️',
                            'label' => 'Imagen',
                        ],

                        'video' => [
                            'badge' => 'bg-violet-50 text-violet-700 border-violet-100',
                            'icon'  => '🎥',
                            'label' => 'Video',
                        ],

                        default => [
                            'badge' => 'bg-gray-50 text-gray-700 border-gray-100',
                            'icon'  => '📄',
                            'label' => 'Publicación',
                        ],
                    };

                @endphp

                <!-- CARD -->
                <a href="{{ route('meta.posts.show', $g->batch_uuid) }}"
                   class="group relative overflow-hidden rounded-3xl
                          border border-white/20 bg-white/95 backdrop-blur-md
                          shadow-sm hover:shadow-xl
                          hover:-translate-y-1
                          transition-all duration-300">

                    <!-- Hover glow -->
                    <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition duration-300
                                bg-gradient-to-br from-indigo-50/40 to-transparent pointer-events-none">
                    </div>

                    <div class="relative p-5">

                        <!-- TOP -->
                        <div class="flex items-start justify-between gap-3">

                            <!-- BADGE -->
                            <div class="flex items-center gap-2">

                                <span class="flex items-center gap-1 px-3 py-1 rounded-full
                                             border text-[11px] font-semibold
                                             {{ $typeStyles['badge'] }}">

                                    <span>
                                        {{ $typeStyles['icon'] }}
                                    </span>

                                    {{ strtoupper($g->type) }}

                                </span>

                            </div>

                            <!-- ARROW -->
                            <div class="flex items-center justify-center
                                        w-8 h-8 rounded-full
                                        bg-gray-100 text-gray-400
                                        group-hover:bg-indigo-100
                                        group-hover:text-indigo-600
                                        transition">

                                <svg class="w-4 h-4"
                                     viewBox="0 0 24 24"
                                     fill="none"
                                     stroke="currentColor"
                                     stroke-width="2">

                                    <path d="M9 18l6-6-6-6"/>

                                </svg>

                            </div>

                        </div>

                        <!-- CONTENT -->
                        <div class="mt-4">

                            <div class="text-[15px] leading-6 font-medium text-gray-800 line-clamp-3 min-h-[72px]">

                                {{ \Illuminate\Support\Str::limit($g->message ?? 'Sin contenido', 120) }}

                            </div>

                            <!-- DATE -->
                            <div class="mt-3 flex items-center gap-2 text-xs text-gray-500">

                                <span class="flex items-center justify-center
                                             w-6 h-6 rounded-full bg-gray-100">

                                    🕒

                                </span>

                                <span>
                                    {{ \Carbon\Carbon::parse($g->first_at)->format('Y-m-d H:i') }}
                                </span>

                            </div>

                        </div>

                        <!-- STATS -->
                        <div class="mt-5 flex items-center gap-2 flex-wrap">

                            <!-- OK -->
                            <div class="inline-flex items-center gap-1
                                        rounded-full bg-green-50 border border-green-100
                                        px-2.5 py-1 text-[11px] font-medium text-green-700">

                                <span class="w-2 h-2 rounded-full bg-green-500"></span>

                                OK {{ $g->ok }}

                            </div>

                            <!-- FAIL -->
                            <div class="inline-flex items-center gap-1
                                        rounded-full bg-red-50 border border-red-100
                                        px-2.5 py-1 text-[11px] font-medium text-red-700">

                                <span class="w-2 h-2 rounded-full bg-red-500"></span>

                                Fail {{ $g->fails }}

                            </div>

                            <!-- TOTAL -->
                            <div class="inline-flex items-center gap-1
                                        rounded-full bg-gray-100 border border-gray-200
                                        px-2.5 py-1 text-[11px] font-medium text-gray-700">

                                Total {{ $g->total }}

                            </div>

                        </div>

                    </div>

                </a>

            @endforeach

        </div>

        <!-- PAGINATION -->
        <div class="mt-8">

            {{ $groups->links() }}

        </div>

    @endif

</div>
@endsection
