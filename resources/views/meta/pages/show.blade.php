@php use Illuminate\Support\Str; @endphp

@extends('layouts.app')

@section('title', $page->name . ' | Página')

@section('content')
<div class="max-w-6xl mx-auto p-4">

    {{-- BOTÓN VOLVER AL INDEX --}}
    <div class="mb-4 mt-10 flex justify-end">
        <a href="{{ route('facebook-pages.index') }}"
           class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">
            <span aria-hidden="true">←</span>
            <span>Volver</span>
        </a>
    </div>

    {{-- Perfil centrado --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <div class="flex flex-col items-center text-center gap-4">
            <img src="{{ $page->pictureUrl('square', 200, 200) }}"
                 alt="Foto de {{ $page->name }}"
                 class="w-32 h-32 rounded-full ring-1 ring-gray-200 object-cover" />
            <div>
                <h1 class="text-2xl font-bold">{{ $page->name }}</h1>
                @if($page->category)
                    <p class="text-sm text-gray-500">{{ $page->category }}</p>
                @endif
                <p class="text-xs text-gray-400 mt-1">ID: {{ $page->page_id }}</p>
            </div>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs text-gray-500">Publicaciones</p>
            <p class="text-2xl font-semibold">{{ number_format($totals['publicaciones']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs text-gray-500">Alcance total</p>
            <p class="text-2xl font-semibold">{{ number_format($totals['alcance']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs text-gray-500">Visualizaciones totales</p>
            <p class="text-2xl font-semibold">{{ number_format($totals['visualizaciones']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs text-gray-500">Interacciones totales</p>
            <p class="text-2xl font-semibold">{{ number_format($totals['interacciones']) }}</p>
        </div>
    </div>

    {{-- Tabla: FECHA + MENSAJE (preview/expand) + métricas + ACCIONES --}}
    <div class="rounded-2xl border border-gray-200 bg-white p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Publicaciones</h2>
            <span class="text-xs text-gray-500">Listado con métricas</span>
        </div>

        <div class="overflow-x-auto">
            <table id="postsTable" class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-600">
                        <th class="py-2 px-2">Fecha</th>
                        <th class="py-2 px-2">Mensaje</th>
                        <th class="py-2 px-2">Alcance</th>
                        <th class="py-2 px-2">Visualizaciones</th>
                        <th class="py-2 px-2">Interacciones</th>
                        <th class="py-2 px-2">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($posts as $post)
                        @php
                            $url = $post->fb_permalink_url ?: $post->link;
                            $hasMore = Str::length($post->message ?? '') > 15;
                            $preview = Str::limit($post->message ?? '', 15, '…');
                        @endphp
                        <tr class="border-t align-top">
                            {{-- FECHA --}}
                            <td class="py-2 px-2 whitespace-nowrap">
                                {{ optional($post->published_at)->format('Y-m-d H:i') ?? '—' }}
                            </td>

                            {{-- MENSAJE (15 chars + expandir/colapsar en la misma celda) --}}
                            <td class="py-2 px-2 max-w-[420px]">
                                <div class="leading-snug">
                                    <span class="msg-preview font-medium">{{ $preview }}</span>
                                    @if ($hasMore)
                                        <span class="msg-full hidden whitespace-pre-wrap text-gray-800">
                                            {{ $post->message }}
                                        </span>
                                        <button type="button"
                                            class="js-toggle-message ml-2 text-xs text-blue-600 hover:underline align-baseline">
                                            ver más
                                        </button>
                                    @endif
                                </div>
                            </td>

                            {{-- MÉTRICAS --}}
                            <td class="py-2 px-2">{{ number_format($post->alcance) }}</td>
                            <td class="py-2 px-2">{{ number_format($post->visualizaciones) }}</td>
                            <td class="py-2 px-2">{{ number_format($post->interacciones) }}</td>

                            {{-- ACCIONES --}}
                            <td class="py-2 px-2">
                                @if ($url)
                                    <a href="{{ $url }}" target="_blank" rel="noopener"
                                       class="inline-flex items-center rounded-lg bg-blue-600 text-white px-2 py-1 text-xs hover:bg-blue-700">
                                        Ver publicación
                                    </a>
                                @else
                                    <span class="text-xs text-gray-400">Sin enlace</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

{{-- Tu layout usa @yield("scripts"), así que aquí usamos @section en vez de @push --}}
@section('scripts')
    {{-- Carga DataTables v2 SOLO si lo necesitas.
         Si lo quitas, "ver más" sigue funcionando. --}}
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Inicializa DataTables SOLO si está disponible (evita error "DataTable is not defined")
            if (window.DataTable) {
                new DataTable('#postsTable', {
                    pageLength: 25,
                    order: [[0, 'desc']],
                    columnDefs: [{ targets: 5, orderable: false }]
                });
            }

            // Delegación para "ver más" que expande/colapsa en la misma celda (sin jQuery)
            document.getElementById('postsTable').addEventListener('click', function (e) {
                const btn = e.target.closest('.js-toggle-message');
                if (!btn) return;

                const td = btn.closest('td');
                const preview = td.querySelector('.msg-preview');
                const full = td.querySelector('.msg-full');
                if (!full) return;

                const expanded = !full.classList.contains('hidden');
                if (expanded) {
                    full.classList.add('hidden');
                    preview.classList.remove('hidden');
                    btn.textContent = 'ver más';
                } else {
                    full.classList.remove('hidden');
                    preview.classList.add('hidden');
                    btn.textContent = 'ver menos';
                }
            });
        });
    </script>
@endsection
