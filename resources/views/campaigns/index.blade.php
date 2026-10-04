@extends('layouts.app')

@section('content')
@php
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $colorTipo = ['politica' => 'bg-indigo-50 text-indigo-700', 'comercial' => 'bg-amber-50 text-amber-700', 'institucional' => 'bg-sky-50 text-sky-700', 'sistema' => 'bg-purple-50 text-purple-700'];
    $activas = $campaigns->where('is_active', true)->count();
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Estrategia</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Campañas</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">
                Aquí se crean todas las campañas. Cada una define en qué medios se publica: al publicar, sus páginas quedan marcadas y puedes excluir las que no quieras.
                El análisis con IA de cada campaña está en Inteligencia.
            </p>
        </div>
        <a href="{{ route('campaigns.create') }}" class="inline-flex items-center gap-2 h-10 rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm hover:opacity-90">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Nueva campaña
        </a>
    </div>

    @if (session('success'))<div class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        @foreach ([
            ['Campañas', $fmt($campaigns->count())], ['Activas', $fmt($activas)],
            ['Publicaciones', $fmt($campaigns->sum('posts_count'))], ['Alcance total', $fmt($campaigns->sum('total_reach'))],
        ] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm min-w-0"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400 truncate">{{ $et }}</div><div class="text-xl font-bold text-gray-900">{{ $v }}</div></div>
        @endforeach
    </div>

    <div class="space-y-3">
        @foreach ($campaigns as $c)
            @php
                $tipo = $c->is_system ? 'sistema' : ($c->tipo ?: 'institucional');
                $nPag = count($paginasPorCampana[$c->id] ?? []);
                $medios = $c->nombresMedios();
            @endphp
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5 {{ $c->is_active ? '' : 'opacity-70' }}">
                <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-bold text-gray-900">{{ $c->name }}</h2>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $colorTipo[$tipo] ?? $colorTipo['institucional'] }}">{{ $tipo === 'sistema' ? 'Sistema' : ($tipos[$tipo] ?? ucfirst($tipo)) }}</span>
                            @if ($c->is_active)
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Activa</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Inactiva</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            @if ($c->is_system) Artículos replicados automáticamente desde esnoticia en las páginas de cada medio. @else {{ $c->description ?: 'Sin descripción' }} @endif
                            @if ($c->territorio) · {{ $c->territorio }} @endif
                            @if ($c->starts_on) · {{ $c->starts_on->format('d/m/Y') }}{{ $c->ends_on ? ' a ' . $c->ends_on->format('d/m/Y') : '' }} @endif
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5 mt-2.5">
                            <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-400 mr-1">Medios</span>
                            @forelse ($medios as $m)
                                <span class="rounded-md bg-gray-100 text-gray-700 px-2 py-0.5 text-xs">{{ $m }}</span>
                            @empty
                                <span class="text-xs text-rose-600">Sin medios: edítala para elegirlos</span>
                            @endforelse
                            <span class="text-xs text-gray-400 ml-1">· {{ $nPag }} página(s)</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 lg:w-80 shrink-0">
                        @foreach ([['Publ.', $fmt($c->posts_count)], ['Alcance', $fmt($c->total_reach)], ['Interac.', $fmt($c->total_inter)]] as [$et, $v])
                            <div class="rounded-xl bg-gray-50 px-3 py-2 min-w-0"><div class="text-[11px] text-gray-400">{{ $et }}</div><div class="text-sm font-bold text-gray-900 truncate">{{ $v }}</div></div>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 mt-4 pt-3 border-t border-gray-100">
                    @if ($c->perfil)
                        <a href="{{ route('inteligencia.show', $c->perfil) }}" class="inline-flex items-center gap-1.5 h-9 rounded-lg bg-indigo-50 text-indigo-800 px-3 text-xs font-semibold hover:bg-indigo-100">✦ Analizar con IA
                            @if ($c->perfil->temas_count)<span class="text-indigo-500 font-normal">· {{ $c->perfil->temas_count }} temas</span>@endif
                        </a>
                    @endif
                    <a href="{{ route('reports.posts', ['campaign_id' => $c->id]) }}" class="h-9 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">Ver publicaciones</a>
                    @unless ($c->is_system)
                        <a href="{{ route('campaigns.edit', $c) }}" class="h-9 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">Editar</a>
                        <form method="POST" action="{{ route('campaigns.toggle', $c) }}" class="ml-auto" onsubmit="return confirm('{{ $c->is_active ? '¿Desactivar' : '¿Activar' }} la campaña «{{ $c->name }}»?');">
                            @csrf
                            <button class="h-9 rounded-lg px-3 text-xs font-semibold {{ $c->is_active ? 'text-amber-700 hover:bg-amber-50' : 'text-emerald-700 hover:bg-emerald-50' }}">{{ $c->is_active ? 'Desactivar' : 'Activar' }}</button>
                        </form>
                    @else
                        <span class="ml-auto text-[11px] text-gray-400">La campaña de sistema no se edita; se analiza con IA solo cuando lo pidas.</span>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
