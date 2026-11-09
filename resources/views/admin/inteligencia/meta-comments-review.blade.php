@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-8">
    <a href="{{ route('inteligencia.show', $campana) }}" class="text-sm text-indigo-700 hover:underline">← Volver a Inteligencia de audiencia</a>
    <div class="flex flex-wrap items-start justify-between gap-3 mt-4 mb-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Prueba aislada · Meta App Review</p>
            <h1 class="text-2xl font-bold text-[#00024f]">Lectura de comentarios de Facebook</h1>
            <p class="text-sm text-gray-600 mt-2">Campaña: {{ $campana->nombre }}. Consulta en vivo, de solo lectura, sin Anthropic ni almacenamiento de comentarios.</p>
        </div>
        <span class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">Módulo de prueba</span>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-5">
        <form method="GET" action="{{ route('inteligencia.meta-comments-review', $campana) }}" class="space-y-4">
            <div>
                <label for="page_id" class="block text-sm font-semibold text-gray-800 mb-1">1. Selecciona la Página de Facebook</label>
                <select id="page_id" name="page_id" required onchange="this.form.elements.post_id.value='';this.form.submit()" class="w-full rounded-lg border-gray-300">
                    <option value="">Seleccionar Página</option>
                    @foreach($pages as $option)
                        <option value="{{ $option->id }}" @selected($page?->id === $option->id)>{{ $option->name }} (ID: {{ $option->page_id }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="post_id" class="block text-sm font-semibold text-gray-800 mb-1">2. Selecciona una publicación</label>
                <select id="post_id" name="post_id" class="w-full rounded-lg border-gray-300" @disabled(!$page)>
                    <option value="">Seleccionar publicación</option>
                    @foreach($posts as $option)
                        <option value="{{ $option->id }}" @selected($post?->id === $option->id)>
                            {{ $option->publicado_en?->format('d/m/Y') }} · {{ \Illuminate\Support\Str::limit($option->texto ?: $option->post_id, 100) }}
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Se muestran hasta 50 publicaciones de Facebook de esta Página que están registradas en Editus.</p>
            </div>
            <button type="submit" class="bg-[#00024f] hover:opacity-90 text-white font-semibold rounded-lg px-5 py-2.5" @disabled(!$page)>3. Consultar comentarios en vivo</button>
        </form>
    </div>

    @if($page)
        <div class="bg-white rounded-xl border border-gray-200 p-5 mb-5">
            <p class="text-xs uppercase font-semibold tracking-wide text-gray-500">Página seleccionada</p>
            <h2 class="text-xl font-bold text-gray-900">{{ $page->name }}</h2>
            <p class="text-xs text-gray-500">Facebook Page ID: {{ $page->page_id }}</p>
            @if($post)
                <div class="border-t mt-3 pt-3">
                    <p class="text-sm font-semibold text-gray-700">Publicación: {{ $post->post_id }}</p>
                    <p class="text-sm text-gray-700 whitespace-pre-line mt-1">{{ $post->texto }}</p>
                    @if($post->permalink)
                        <a href="{{ $post->permalink }}" target="_blank" rel="noopener noreferrer" class="text-indigo-700 text-sm underline">Abrir publicación original en Facebook</a>
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if($error)
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 text-red-900 p-4 mb-5">{{ $error }}</div>
    @endif

    @if($retrieved)
        <section class="bg-white rounded-xl border border-gray-200 p-5" aria-label="Comentarios recuperados">
            <h2 class="text-lg font-bold text-gray-900">Comentarios recuperados en vivo</h2>
            <p class="text-sm text-gray-600 mb-4">Página: <strong>{{ $page->name }}</strong> · Publicación: {{ $post->post_id }} · {{ count($comments) }} comentarios en esta respuesta de Meta.</p>
            @forelse($comments as $comment)
                <article class="border-t border-gray-100 py-4">
                    <div class="flex flex-wrap justify-between gap-2 text-sm">
                        <strong>{{ data_get($comment, 'from.name', 'Usuario de Facebook') }}</strong>
                        <span class="text-gray-500">{{ data_get($comment, 'created_time', 'Fecha no disponible') }}</span>
                    </div>
                    <p class="text-gray-800 mt-1 whitespace-pre-wrap">{{ $comment['message'] ?? '(Comentario sin texto disponible)' }}</p>
                    <p class="text-xs text-gray-400 mt-1">ID: {{ $comment['id'] }}</p>
                    <a class="inline-block mt-2 text-sm text-indigo-700 hover:underline" href="{{ route('inteligencia.meta-comments-review', ['campana' => $campana, 'page_id' => $page->id, 'post_id' => $post->id, 'comment_id' => $comment['id']]) }}">Ver respuestas de este comentario</a>
                    @if(request('comment_id') === (string) $comment['id'])
                        <div class="ml-4 mt-3 pl-4 border-l-2 border-indigo-200">
                            <h3 class="text-sm font-semibold mb-2">Respuestas recuperadas</h3>
                            @forelse($replies as $reply)
                                <div class="py-2 border-b border-gray-100">
                                    <p class="text-sm font-semibold">{{ data_get($reply, 'from.name', 'Usuario de Facebook') }}</p>
                                    <p class="text-sm whitespace-pre-wrap">{{ $reply['message'] ?? '(Sin texto)' }}</p>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">No se encontraron respuestas.</p>
                            @endforelse
                        </div>
                    @endif
                </article>
            @empty
                <p class="text-gray-600 py-4">Meta respondió correctamente, pero no devolvió comentarios para esta publicación. Prueba con una publicación que tenga comentarios de otras personas.</p>
            @endforelse
            <p class="text-xs text-gray-500 mt-4">Los comentarios se muestran únicamente durante esta solicitud. No se almacenan en la base de datos de Editus.</p>
        </section>
    @endif
</div>
@endsection
