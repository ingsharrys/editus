@extends('layouts.app')

@section('content')
@php
    $fmt = fn($n) => number_format((int) $n, 0, ',', '.');
    $sel = $m['seleccion'];
    $puedePublicar = auth()->user()->tienePermiso('paginas.publicar');
    $tiposVista = $s->tipos->isNotEmpty() ? $s->tipos : $tipos->filter(fn($t) => isset($matriz[$t->id]));
    $canales = $canales ?? [];
    $esGrupo = $s->esGrupo();
    $avanceHijas = $avanceHijas ?? collect();
    // Los tipos con canal salen en el avance por canal; aquí solo los demás
    if ($canales) $tiposVista = $tiposVista->filter(fn($t) => !$t->canal)->values();
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
        <div>
            @if ($s->parent_id && $s->parent)
                <a href="{{ route('campaigns.subcampanas.ver', [$c, $s->parent]) }}" class="text-xs text-indigo-700 hover:underline">← {{ $s->parent->nombre }}</a>
            @else
                <a href="{{ route('campaigns.plan.subcampanas', $c) }}" class="text-xs text-indigo-700 hover:underline">← {{ __('Subcampañas') }}</a>
            @endif
            <h2 class="text-2xl font-bold text-gray-900 mt-0.5">{{ $s->nombre }} @unless ($s->is_active)<span class="text-xs font-semibold text-gray-400">· {{ __('inactiva') }}</span>@endunless</h2>
            <p class="text-xs text-gray-500">{{ $s->programaContrato?->etiqueta() ?? __('Sin programa') }}{{ $s->subprograma ? ' · ' . $s->subprograma : '' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($puedePublicar && $s->is_active && $c->is_active && !$esGrupo)
                <a href="{{ route('campaigns.subcampanas.publicar', [$c, $s]) }}" class="h-10 inline-flex items-center gap-1.5 rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm hover:opacity-90">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    {{ __('Publicar en :nombre', ['nombre' => $s->nombre]) }}</a>
            @endif
            @unless ($esGrupo)<a href="{{ route('campaigns.banners.crear', [$c, 'subcampana' => $s->id]) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Registrar banner en páginas web') }}</a>@endunless
            <a href="{{ route('campaigns.informes', [$c, 'subcampana' => $s->id]) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Informe') }}</a>
        </div>
    </div>

    {{-- Métricas de la subcampaña --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        @foreach ([[__('Piezas'), $piezas->filter(fn($x) => $x['tot']['publicaciones'] > 0)->count()], [__('Publicaciones'), $sel['publicaciones']], [__('Alcance'), $sel['alcance']], [__('Visualizaciones'), $sel['visualizaciones']], [__('Interacciones'), $sel['interacciones']]] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm min-w-0"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400 truncate">{{ $et }}</div><div class="text-xl font-bold text-gray-900 tabular-nums">{{ $fmt($v) }}</div></div>
        @endforeach
    </div>

    @if ($esGrupo)
        {{-- Subcampañas que agrupa --}}
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 mb-6">
            <h3 class="text-sm font-bold text-gray-900">{{ __('Subcampañas de :n', ['n' => $s->nombre]) }}</h3>
            <p class="text-[11px] text-gray-500 mb-3">{{ __('Se publica y se reporta en cada una; aquí ves la suma de todas.') }}</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($s->hijas as $h)
                    @php $av = $avanceHijas->firstWhere('sub.id', $h->id); $plan = collect($av['tipos'] ?? [])->sum('planeadas'); $hecho = collect($av['tipos'] ?? [])->sum(fn($x) => min($x['publicadas'], $x['planeadas'] ?? $x['publicadas'])); $pct = $plan ? min(100, (int) round($hecho * 100 / $plan)) : null; @endphp
                    <div class="rounded-xl border border-gray-200 bg-white p-3 flex flex-col gap-2">
                        <div class="flex items-baseline justify-between gap-2">
                            <a href="{{ route('campaigns.subcampanas.ver', [$c, $h]) }}" class="text-sm font-semibold text-indigo-800 hover:underline">{{ $h->nombre }}</a>
                            <span class="text-sm font-bold tabular-nums">{{ $hecho }}@if ($plan)<span class="text-xs font-semibold text-gray-400"> / {{ $plan }}</span>@endif</span>
                        </div>
                        @if ($pct !== null)<div class="h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full {{ $pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' }}" style="width: {{ $pct }}%"></div></div>@endif
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('campaigns.subcampanas.ver', [$c, $h]) }}" class="h-8 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ __('Abrir') }}</a>
                            @if ($puedePublicar && $h->is_active && $s->is_active && $c->is_active)<a href="{{ route('campaigns.subcampanas.publicar', [$c, $h]) }}" class="h-8 inline-flex items-center rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">{{ __('Publicar') }}</a>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($canales)
        @include('campaigns.plan._canales', ['canales' => $canales, 's' => $esGrupo ? null : $s, 'emisoras' => $emisoras ?? collect()])
    @endif

    {{-- Avance por tipo de publicación --}}
    @if (!$canales || $tiposVista->isNotEmpty())
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-gray-900">{{ __('Publicado por tipo de publicación') }}</h3>
            <a href="{{ route('campaigns.plan', [$c, 'sub' => $s->id]) }}" class="text-xs font-semibold text-indigo-700 hover:underline">{{ __('Ver por página') }}</a>
        </div>
        <div class="p-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @forelse ($tiposVista as $t)
                @php $x = $matriz[$t->id] ?? ['piezas' => 0, 'publicaciones' => 0, 'planeadas' => null]; $pct = $x['planeadas'] ? min(100, (int) round($x['piezas'] * 100 / max(1, $x['planeadas']))) : null; @endphp
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-semibold text-gray-900">{{ $t->nombre }}</span>
                        <span class="text-lg font-bold tabular-nums {{ $x['planeadas'] !== null && $x['piezas'] >= $x['planeadas'] ? 'text-emerald-700' : 'text-gray-900' }}">{{ $x['piezas'] }}@if ($x['planeadas'] !== null)<span class="text-sm font-semibold text-gray-400"> / {{ $x['planeadas'] }}</span>@endif</span>
                    </div>
                    @if ($pct !== null)<div class="h-1.5 mt-1.5 rounded-full bg-white"><div class="h-1.5 rounded-full {{ $pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' }}" style="width: {{ $pct }}%"></div></div>@endif
                    <div class="text-[11px] text-gray-500 mt-1">{{ __(':n pieza(s) publicadas en :p página(s) o sitio(s) web', ['n' => $x['piezas'], 'p' => $x['publicaciones']]) }}</div>
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('Esta subcampaña no tiene tipos de publicación asignados.') }} <a href="{{ route('campaigns.plan.subcampanas', $c) }}" class="text-indigo-700 underline">{{ __('Asignarlos') }}</a></p>
            @endforelse
        </div>
    </div>
    @endif

    {{-- Piezas publicadas --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100">
            <h3 class="text-sm font-bold text-gray-900">{{ __('Piezas publicadas') }} <span class="font-normal text-gray-400">({{ $piezas->count() }})</span></h3>
            <p class="text-[11px] text-gray-500">{{ __('Cada publicación en varias páginas es una pieza del informe. Ábrela para ponerle las actas, la muestra y los pantallazos que falten.') }}</p>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse ($piezas as $x)
                @php $p = $x['pieza']; $mini = \App\Services\ProductosService::url($c, $p->muestra_path ? ['local', $p->muestra_path] : null); @endphp
                <a href="{{ route('campaigns.productos.ver', [$c, $p]) }}" class="flex flex-wrap items-center gap-4 px-4 py-3 hover:bg-gray-50">
                    @if ($mini)<img src="{{ $mini }}" alt="" class="h-12 w-16 object-cover rounded-md border border-gray-100">@else<div class="h-12 w-16 rounded-md bg-gray-50 border border-dashed border-gray-200"></div>@endif
                    <div class="flex-1 min-w-[12rem]">
                        <div class="text-sm font-semibold text-gray-900">{{ $p->nombre }}@if ($esGrupo && $p->subcampana)<span class="ml-1 text-[11px] font-normal text-indigo-700">· {{ $p->subcampana->nombre }}</span>@endif</div>
                        <div class="text-[11px] text-gray-500">@if ($p->tipo?->conPauta())<span class="rounded-full bg-amber-50 text-amber-800 px-1.5 py-0.5 text-[10px] font-semibold mr-1">{{ __('Pauta') }}{{ $p->pauta_asignada ? ' $ ' . number_format((float) $p->pauta_asignada, 0, ',', '.') : '' }}</span>@endif{{ $p->tipo?->nombre ?? __('Sin tipo') }} · {{ $x['fecha']?->format('d/m/Y') ?? '—' }} · {{ __(':n medio(s)', ['n' => $x['tot']['medios']]) }}
                            @if ($p->acta_aprobacion) · {{ __('Acta') }} {{ $p->acta_aprobacion }}@endif</div>
                    </div>
                    <div class="flex gap-4 text-xs text-gray-600 tabular-nums">
                        <span>{{ __('Alcance') }} <b>{{ $fmt($x['tot']['alcance']) }}</b></span>
                        <span>{{ __('Interac.') }} <b>{{ $fmt($x['tot']['interacciones']) }}</b></span>
                    </div>
                    @if ($x['sinCaptura'])<span class="rounded-full bg-amber-50 text-amber-700 px-2 py-0.5 text-[10px] font-semibold">{{ __(':n sin pantallazo', ['n' => $x['sinCaptura']]) }}</span>@endif
                </a>
            @empty
                <p class="p-6 text-center text-sm text-gray-500">{{ __('Aún no hay publicaciones en esta subcampaña.') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
