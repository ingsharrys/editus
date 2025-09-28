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
                                <option value="{{ $p->id }}" @selected($pageId == $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>

                        <button class="hidden md:inline-flex bg-indigo-600 text-white px-4 py-2 rounded-xl hover:bg-indigo-700 transition">
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
        // Auto-submit al cambiar de página (móvil)
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
                    $pubAt = $post->published_at ?? $post->created_at;
                    $permalink = $post->fb_permalink_url ?: $post->link;
                @endphp

                <div class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition flex flex-col justify-between">

                    {{-- Header: Avatar + Info página + Link "Ver publicación" --}}
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <img src="{{ $post->page?->picture_small_url ?? '' }}"
                                 onerror="this.style.display='none'"
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

                        <div class="flex flex-col items-end gap-1 text-xs">
                            @if ($permalink)
                                <a href="{{ $permalink }}" target="_blank" rel="noopener noreferrer"
                                   class="mt-1 inline-flex items-center gap-1.5 text-indigo-600 hover:underline"
                                   title="Ver publicación">
                                    Ver publicación
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M7 17L17 7"></path>
                                        <path d="M7 7h10v10"></path>
                                    </svg>
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Texto del post --}}
                    <p class="text-sm text-gray-800 line-clamp-3 mb-3">
                        {{ $post->message ?: '— sin texto —' }}
                    </p>

                    {{-- Métricas directas (desde meta_posts) --}}
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">Alcance</div>
                            <div class="text-base font-semibold">
                                {{ is_null($post->alcance) ? '—' : number_format((int) $post->alcance) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">Visualizaciones</div>
                            <div class="text-base font-semibold">
                                {{ is_null($post->visualizaciones) ? '—' : number_format((int) $post->visualizaciones) }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">Interacciones</div>
                            <div class="text-base font-semibold">
                                {{ is_null($post->interacciones) ? '—' : number_format((int) $post->interacciones) }}
                            </div>
                        </div>
                    </div>

                    {{-- Fechas --}}
                    <div class="flex items-center justify-between text-xs mt-3 text-gray-500">
                        <span>
                            Publicado:
                            {{ optional($pubAt)->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '—' }}
                        </span>
                        <span>
                            @if ($post->last_insights_at)
                                Últ. act.:
                                {{ $post->last_insights_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                            @endif
                        </span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $posts->links() }}
        </div>
    @endif
</div>
@endsection
