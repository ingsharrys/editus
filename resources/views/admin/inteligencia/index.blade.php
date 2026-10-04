@extends('layouts.app')

@php $fmt = fn($n) => number_format((int) $n, 0, ',', '.'); @endphp

@section('content')
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Analítica</div>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Inteligencia de audiencia</h1>
        <p class="mt-1.5 text-sm text-gray-500 max-w-3xl">
            Qué temas conectan, con qué público, en qué red y a qué hora; qué siente y qué dice la gente en los comentarios; y un consultor de IA que responde preguntas políticas o comerciales con diagnóstico, recomendaciones y publicaciones listas para usar.
            Todo el análisis es <strong>agregado por segmento</strong> (ciudad, edad, género, tema, horario), nunca por personas.
        </p>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm"><ul class="list-disc ml-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    @unless ($iaLista)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">
            La IA no está configurada: falta <code>ANTHROPIC_API_KEY</code> en el <code>.env</code>. Se recolectan datos y estadísticas, pero no se clasifican temas, ni se leen comentarios, ni funciona el consultor.
        </div>
    @endunless

    {{-- Indicadores de toda la organización --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        @foreach ([
            ['Páginas con histórico', $fmt($indicadores['paginas_con_datos']), null, 'bg-slate-100 text-slate-600', 'M4 4h16v16H4z'],
            ['Publicaciones recolectadas', $fmt($indicadores['publicaciones']), $fmt($indicadores['publicaciones_30']) . ' en los últimos 30 días', 'bg-indigo-50 text-indigo-600', 'M4 6h16M4 12h16M4 18h10'],
            ['Campañas activas', $fmt($indicadores['campanas_activas']), null, 'bg-emerald-50 text-emerald-600', 'M4 22V4a2 2 0 0 1 2-2h12l-3 5 3 5H6'],
            ['Consultas a la IA', $fmt($indicadores['consultas']), $indicadores['ultima'] ? 'datos ' . \Carbon\Carbon::parse($indicadores['ultima'])->diffForHumans() : 'sin recolección aún', 'bg-amber-50 text-amber-600', 'M12 3a9 9 0 1 0 9 9 M12 7v5l3 3'],
        ] as [$et, $n, $sub, $color, $icono])
            <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3.5 min-w-0 shadow-sm">
                <span class="h-9 w-9 shrink-0 rounded-xl flex items-center justify-center {{ $color }}"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icono }}"/></svg></span>
                <div class="min-w-0"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400 truncate">{{ $et }}</div><div class="text-xl font-bold text-gray-900 leading-tight">{{ $n }}</div>@if ($sub)<div class="text-[11px] text-gray-400 truncate">{{ $sub }}</div>@endif</div>
            </div>
        @endforeach
    </div>

    {{-- Vista general --}}
    <a href="{{ route('inteligencia.general') }}" class="block rounded-2xl bg-[#00024f] text-white p-5 shadow-md hover:opacity-95 transition mb-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-200">Toda la organización</div>
                <h2 class="text-xl font-bold mt-0.5">Vista general de todas las páginas</h2>
                <p class="text-sm text-indigo-100 mt-1">Alcance, interacción, formatos, horarios, público y emociones de todas las páginas integradas, con filtros por medio y por página, comparativa de campañas y el consultor de IA.</p>
            </div>
            <span class="shrink-0 inline-flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2 text-sm font-semibold">Abrir tablero →</span>
        </div>
    </a>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-base font-bold text-gray-900">Campañas</h2>
                <a href="{{ route('campaigns.index') }}" class="text-xs text-indigo-700 hover:underline">Gestionar campañas →</a>
            </div>
            @forelse ($campanas as $c)
                @php $r = $resumenes[$c->id] ?? null; @endphp
                <a href="{{ route('inteligencia.show', $c) }}" class="block rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:shadow-md hover:border-gray-300 transition mb-3 {{ $c->activa ? '' : 'opacity-70' }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-base font-bold text-gray-900">{{ $c->nombre }}</h3>
                                @if ($c->esDeSistema())<span class="rounded-full bg-purple-50 text-purple-700 px-2 py-0.5 text-[11px] font-semibold">sistema</span>@endif
                                @if ($c->activa)<span class="rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 text-[11px] font-semibold">activa</span>@else<span class="rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-[11px] font-semibold">pausada</span>@endif
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                {{ $c->territorio ?: 'Sin territorio' }} · {{ $c->paginas->count() }} página(s) · {{ $c->temas_count }} tema(s) · {{ $c->informes_count }} informe(s)
                                @if ($c->desde) · {{ $c->desde->format('d/m/Y') }}{{ $c->hasta ? ' a ' . $c->hasta->format('d/m/Y') : '' }}@endif
                            </p>
                        </div>
                        <div class="flex -space-x-2 shrink-0">
                            @foreach ($c->paginas->take(5) as $p)
                                <img src="{{ $p->picture_url ?: $p->pictureUrl('small') }}" alt="{{ $p->name }}" title="{{ $p->name }}" class="w-8 h-8 rounded-full border-2 border-white bg-gray-200">
                            @endforeach
                        </div>
                    </div>
                    @if ($r)
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-4">
                            @foreach ([['Publicaciones (7 d)', $fmt($r['publicaciones'])], ['Alcance', $fmt($r['alcance'])], ['Interacciones', $fmt($r['interacciones'])], ['Tasa', $r['tasa'] !== null ? $r['tasa'] . '%' : '—']] as [$et, $v])
                                <div class="rounded-xl bg-gray-50 px-3 py-2"><div class="text-[11px] text-gray-400 truncate">{{ $et }}</div><div class="text-sm font-bold text-gray-900">{{ $v }}</div></div>
                            @endforeach
                        </div>
                    @endif
                </a>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">
                    Aún no hay campañas. Crea la primera con el formulario de la derecha: elige las páginas y escribe los temas que quieres seguir.
                </div>
            @endforelse
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-bold text-gray-900 mb-1">Las campañas se crean en Campañas</h2>
            <p class="text-xs text-gray-500 mb-4">Allí defines el nombre, el tipo, los medios donde se publica y el contexto que lee la IA. Aparecen aquí automáticamente para analizarlas.</p>
            <a href="{{ route('campaigns.create') }}" class="w-full h-10 inline-flex items-center justify-center rounded-lg bg-[#00024f] text-white font-semibold text-sm hover:opacity-90 shadow-sm">+ Nueva campaña</a>
            <a href="{{ route('campaigns.index') }}" class="w-full h-10 mt-2 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 font-medium text-sm hover:bg-gray-50">Ver todas las campañas</a>
            <div class="mt-5 rounded-xl bg-gray-50 p-3 text-xs text-gray-600 space-y-1.5">
                <div><strong class="text-gray-800">Cada madrugada:</strong> se recolectan los datos de todas las páginas y la IA clasifica y lee los comentarios de las campañas activas.</div>
                <div><strong class="text-gray-800">Esnoticia:</strong> cubre todos los medios, así que su análisis con IA se hace solo cuando lo pidas desde la campaña.</div>
            </div>
        </div>
    </div>
</div>
@endsection
