@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto px-4 py-8 mt-10">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">Mis publicaciones</h1>
        </div>

        {{-- Barra de filtro (solo página) --}}
        <form method="GET" class="sticky top-0 z-10 mb-6">
            <div class="rounded-2xl bg-white/90 backdrop-blur border border-gray-200 shadow-md p-4">
                <div class="flex items-center gap-4">


                    <div class="flex-1">
                        <label for="pageSelect" class="block text-xs font-medium uppercase tracking-wider text-gray-500">
                            Página
                        </label>
                        <div class="mt-1 flex items-center gap-2">
                            <select id="pageSelect" name="page_id"
                                class="w-full md:w-1/2 border border-gray-300 rounded-xl px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">Todas las páginas</option>
                                @foreach ($pages as $p)
                                    <option value="{{ $p->id }}" @selected($pageId == $p->id)>{{ $p->name }}
                                    </option>
                                @endforeach
                            </select>

                            <button
                                class="hidden md:inline-flex bg-indigo-600 text-white px-4 py-2 rounded-xl hover:bg-indigo-700 transition">
                                Aplicar
                            </button>

                            @if ($pageId)
                                <a href="{{ route('mis-posts.index') }}"
                                    class="text-sm px-3 py-2 rounded-xl border hover:bg-gray-50 transition">
                                    Limpiar
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="hidden md:block text-sm text-gray-500">
                        {{ $posts->total() }} resultados
                    </div>
                </div>
            </div>
        </form>

        <script>
            // Auto-submit al cambiar de página (en móviles queda ✨)
            document.getElementById('pageSelect')?.addEventListener('change', e => e.target.form.submit());
        </script>

        @if ($posts->count() === 0)
            <div class="p-6 border rounded-2xl bg-gray-50">
                <p class="text-gray-600">No hay publicaciones que coincidan.</p>
            </div>
        @else
            {{-- Grid de tarjetas --}}
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($posts as $post)
                    @php
                        $pubAt = $post->effective_at;
                        $round = $post->metrics_next_round; // 1, 2 o null
                        $r1Complete = $post->first_metric?->is_complete ?? false;
                        $r2Complete = $post->second_metric?->is_complete ?? false;
                    @endphp

                    <div
                        class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition flex flex-col justify-between">

                        {{-- Header: Avatar + Info página  +  Dots R1/R2 a la derecha --}}
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $post->page?->picture_small_url ?? '' }}" onerror="this.style.display='none'"
                                    class="w-10 h-10 rounded-full object-cover" alt="">

                                <div>
                                    <div class="font-semibold">
                                        {{ $post->page?->name ?? '—' }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        Tipo: {{ strtoupper($post->type ?? 'post') }}
                                    </div>
                                </div>
                            </div>

                            {{-- Puntos de estado de rondas --}}
                            <div class="flex items-center gap-4 text-xs text-gray-500">
                                <span class="inline-flex items-center gap-1.5"
                                    title="Ronda 1: {{ $r1Complete ? 'completada' : 'sin registrar' }}">
                                    <span
                                        class="w-2.5 h-2.5 rounded-full ring-1 ring-black/5 {{ $r1Complete ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                                    <span></span>
                                </span>

                                <span class="inline-flex items-center gap-1.5"
                                    title="Ronda 2: {{ $r2Complete ? 'completada' : 'sin registrar' }}">
                                    <span
                                        class="w-2.5 h-2.5 rounded-full ring-1 ring-black/5 {{ $r2Complete ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                                    <span></span>
                                </span>
                            </div>
                        </div>

                        {{-- Contenido del post --}}
                        <p class="text-sm text-gray-800 line-clamp-3 mb-3">
                            {{ $post->message ?: '— sin texto —' }}
                        </p>

                        {{-- Estado + Fecha publicación --}}
                        <div class="flex items-center justify-between text-xs mb-3">
                            @php
                                $color = match ($post->status) {
                                    'published' => 'bg-green-100 text-green-700',
                                    'scheduled' => 'bg-yellow-100 text-yellow-700',
                                    'failed' => 'bg-red-100 text-red-700',
                                    'processing', 'queued' => 'bg-blue-100 text-blue-700',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                            @endphp

                            <span class="px-2 py-1 rounded bg-gray-100">
                                Fecha publicación
                            </span>

                            <span class="text-gray-500">
                                {{ $pubAt?->timezone(config('app.timezone'))?->format('d/m/Y H:i') ?? '—' }}
                            </span>
                        </div>

                        {{-- CTA --}}
                        @if ($post->can_register_metrics)
                            <a href="{{ route('mis-posts.show', $post) }}?round={{ $round }}"
                                class="mt-3 block w-full text-center px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition">
                                Registrar Métricas
                            </a>
                        @else
                            <button type="button"
                                class="mt-3 block w-full text-center px-4 py-2 rounded-xl bg-gray-200 text-gray-600 text-sm font-medium cursor-not-allowed"
                                disabled title="{{ $post->metrics_state_message }}">
                                {{ $post->metrics_state_message }}
                            </button>
                        @endif
                    </div>
                @endforeach

            </div>
            <div class="mt-6">
                {{ $posts->links() }}
            </div>
        @endif
    </div>
@endsection
