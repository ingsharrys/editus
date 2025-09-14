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
                            @foreach($pages as $p)
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
                <div class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition">
                    <div class="flex items-center gap-3 mb-3">
                        {{-- Avatar página (vía Graph) --}}
                        <img src="{{ $post->page?->picture_small_url ?? '' }}"
                             onerror="this.style.display='none'"
                             class="w-10 h-10 rounded-full object-cover" alt="">

                        <div>
                            <div class="font-semibold">{{ $post->page?->name ?? '—' }}</div>
                            <div class="text-xs text-gray-500">Tipo: {{ strtoupper($post->type ?? 'post') }}</div>
                        </div>
                    </div>

                    <p class="text-sm text-gray-800 line-clamp-3 mb-3">
                        {{ $post->message ?: '— sin texto —' }}
                    </p>

                    <div class="flex items-center justify-between text-xs mb-3">
                        {{-- Estado --}}
                        @php
                            $color = match ($post->status) {
                                'published'  => 'bg-green-100 text-green-700',
                                'scheduled'  => 'bg-yellow-100 text-yellow-700',
                                'failed'     => 'bg-red-100 text-red-700',
                                'processing','queued' => 'bg-blue-100 text-blue-700',
                                default      => 'bg-gray-100 text-gray-700',
                            };
                        @endphp
                        <span class="px-2 py-1 rounded bg-gray-100">Fecha publicación</span>

                        <span class="text-gray-500">
                            {{ $post->effective_at?->timezone(config('app.timezone'))?->format('d/m/Y H:i') ?? '—' }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between">
                        <a href="{{ route('mis-posts.show', $post) }}"
                           class="text-indigo-600 hover:underline text-sm">Ver detalle</a>

                        @if ($post->fb_permalink_url)
                            <a href="{{ $post->fb_permalink_url }}" target="_blank" class="text-sm underline">Ver en Facebook</a>
                        @endif
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
