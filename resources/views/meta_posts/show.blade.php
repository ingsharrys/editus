@extends('layouts.app')

@section('content')
    <div class="max-w-6xl mx-auto p-4 space-y-4 mt-10">
        <div class="rounded-xl border border-gray-200 p-4 bg-white">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm">
                        <span class="font-semibold">{{ strtoupper($summary['type']) }}</span>
                        · {{ $summary['message'] ?: '—' }}
                    </div>
                    <div class="text-xs text-gray-400">
                        Por: {{ $summary['user']?->name ?? '—' }} ·
                        {{ \Carbon\Carbon::parse($summary['first_at'])->format('Y-m-d H:i') }}
                    </div>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">OK {{ $summary['ok'] }}</span>
                    <span class="px-2 py-1 rounded-full bg-red-100 text-red-700">Fail {{ $summary['fails'] }}</span>
                    <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700">Total {{ $summary['total'] }}</span>
                </div>
            </div>
        </div>
        @if (session('ok'))
            <div class="mb-3 rounded-lg bg-green-50 text-green-700 px-3 py-2 text-sm">{{ session('ok') }}</div>
        @endif
        @if (session('warn'))
            <div class="mb-3 rounded-lg bg-yellow-50 text-yellow-800 px-3 py-2 text-sm">{{ session('warn') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-3 rounded-lg bg-red-50 text-red-700 px-3 py-2 text-sm">{{ session('error') }}</div>
        @endif

        <div class="text-right">
            <a href="{{ route('meta.posts.index') }}" class="text-sm text-white hover:underline">
                ← Volver al listado
            </a>
        </div>
        @if ($summary['fails'] > 0)
            <form method="POST" action="{{ route('meta.posts.retryFails', $summary['batch']) }}" class="text-right mb-3">
                @csrf
                <button class="px-3 py-1.5 rounded bg-amber-600 text-white text-xs hover:bg-amber-700">
                    Reintentar fallidos del lote
                </button>
            </form>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Página</th>
                        <th class="px-3 py-2 text-left">Estado</th>
                        <th class="px-3 py-2 text-left">Permalink</th>
                        <th class="px-3 py-2 text-left">Publicado</th>
                        <th class="px-3 py-2 text-left">Acciones</th>
                        <th class="px-3 py-2 text-left">Reintentar</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($posts as $p)
                        <tr class="border-t">
                            <td class="px-3 py-2">
                                {{ $p->page?->name ?? '—' }}
                                <div class="text-[11px] text-gray-400">{{ $p->page?->page_id }}</div>
                            </td>
                            <td class="px-3 py-2">
                                @php
                                    $badge = match ($p->status) {
                                        'success' => 'bg-green-100 text-green-700',
                                        'fail' => 'bg-red-100 text-red-700',
                                        default => 'bg-gray-100 text-gray-700',
                                    };
                                @endphp
                                <span
                                    class="px-2 py-1 rounded-full {{ $badge }}">{{ strtoupper($p->status) }}</span>
                                @if ($p->error)
                                    <div class="text-[11px] text-red-500 mt-1 truncate" title="{{ $p->error }}">Error:
                                        {{ \Illuminate\Support\Str::limit($p->error, 80) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @php
                                    $link = $p->fb_permalink_url;
                                    if (
                                        $p->type === 'video' &&
                                        $p->fb_post_id &&
                                        !str_contains($link, 'facebook.com')
                                    ) {
                                        $link = 'https://www.facebook.com/reel/' . $p->fb_post_id;
                                    }
                                @endphp

                                @if ($link)
                                    <a href="{{ $link }}" target="_blank" rel="noopener"
                                        class="text-indigo-600 hover:underline">Ver publicación</a>
                                    <button type="button" class="ml-2 text-xs text-gray-500 hover:text-gray-700"
                                        onclick="navigator.clipboard.writeText('{{ $link }}')">Copiar</button>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                                <div class="text-[11px] text-gray-400">ID: {{ $p->fb_post_id ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2">
                                {{ $p->published_at ? $p->published_at->format('Y-m-d H:i') : '—' }}
                            </td>
                            <td class="px-3 py-2">
                                @if ($link)
                                    <a href="{{ $link }}" target="_blank" rel="noopener"
                                        class="inline-block px-2 py-1 rounded bg-indigo-600 text-white text-xs">Abrir</a>
                                @else
                                    <span class="text-gray-400 text-xs">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                @if ($p->status === 'fail' && empty($p->fb_post_id))
                                    <form method="POST" action="{{ route('meta.posts.retry', $p->id) }}" class="inline">
                                        @csrf
                                        <button
                                            class="inline-block px-2 py-1 rounded bg-amber-600 text-white text-xs hover:bg-amber-700">
                                            Reintentar
                                        </button>
                                    </form>
                                @else
                                    <span class="text-gray-400 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>


    </div>
@endsection
