@extends('layouts.app')

@section('content')
@php
    $colorEstado = ['Pendiente' => 'bg-gray-100 text-gray-700', 'En producción' => 'bg-sky-50 text-sky-700', 'En revisión' => 'bg-amber-50 text-amber-700',
        'Aprobado' => 'bg-emerald-50 text-emerald-700', 'Publicado / Entregado' => 'bg-indigo-50 text-indigo-700'];
    $sel = 'h-9 rounded-lg border-gray-200 text-sm';
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p class="text-sm text-gray-600 max-w-3xl">{{ __('Control de productos: cada pieza (video, banner, en vivo…) con su tipo, programa, estado, actas, muestra y link. Al publicar en la campaña eliges la pieza y sus métricas por medio llenan el informe.') }}</p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('campaigns.informes.excel', $c) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('Descargar Excel') }}</a>
            <a href="{{ route('campaigns.productos.crear', [$c, 'tipo' => request('tipo'), 'subcampana' => request('subcampana')]) }}" class="h-10 inline-flex items-center rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm hover:opacity-90">+ {{ __('Nueva pieza') }}</a>
        </div>
    </div>

    @if ($tipos->isEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">
            {{ __('Primero crea los tipos de publicación en') }} <a href="{{ route('campaigns.plan.tipos', $c) }}" class="font-semibold underline">{{ __('Tipos de publicación') }}</a>
            {{ __('y los programas en') }} <a href="{{ route('campaigns.plan.programas', $c) }}" class="font-semibold underline">{{ __('Programas') }}</a>, {{ __('o importa tu Excel del control de productos (se crean solos).') }}
        </div>
    @endif

    {{-- Resumen por programa y tipo de publicación --}}
    @if ($resumen['total'])
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm mb-6 overflow-hidden">
            <h3 class="px-4 py-3 border-b border-gray-100 text-sm font-bold text-gray-900">{{ __('Resumen por programa y tipo de publicación') }}</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-[#1F3864] text-white text-[11px] uppercase tracking-wide"><tr>
                        <th class="px-3 py-2 text-left">{{ __('Programa') }}</th>
                        @foreach ($resumen['tipos'] as $s)<th class="px-2 py-2 text-center whitespace-nowrap">{{ $s->nombre }}</th>@endforeach
                        <th class="px-3 py-2 text-center">{{ __('Total') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($resumen['filas'] as $f)
                            <tr class="border-t border-gray-100">
                                <td class="px-3 py-1.5"><a href="{{ route('campaigns.productos', [$c, 'programa' => $f['programa']?->id]) }}" class="hover:text-indigo-700">{{ $f['programa'] ? $f['programa']->etiqueta() : __('Sin programa') }}</a></td>
                                @foreach ($resumen['tipos'] as $s)<td class="px-2 py-1.5 text-center tabular-nums {{ $f['por_tipo'][$s->id] ? 'text-gray-900' : 'text-gray-300' }}">{{ $f['por_tipo'][$s->id] }}</td>@endforeach
                                <td class="px-3 py-1.5 text-center font-semibold tabular-nums">{{ $f['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-sky-50 font-semibold"><tr class="border-t border-gray-200">
                        <td class="px-3 py-2">{{ __('Total') }}</td>
                        @foreach ($resumen['tipos'] as $s)<td class="px-2 py-2 text-center tabular-nums">{{ $resumen['totales'][$s->id] }}</td>@endforeach
                        <td class="px-3 py-2 text-center tabular-nums">{{ $resumen['total'] }}</td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- Avance por subcampaña --}}
    @if ($avance->isNotEmpty())
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
            @foreach ($avance as $a)
                <a href="{{ route('campaigns.productos', [$c, 'subcampana' => $a['sub']->id]) }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 hover:border-indigo-300">
                    <div class="flex items-center justify-between gap-2"><span class="text-sm font-bold text-gray-900">{{ $a['sub']->nombre }}</span><span class="text-xs text-gray-500">{{ __(':n pieza(s)', ['n' => $a['total']]) }}</span></div>
                    @if ($a['sub']->programaContrato)<div class="text-[11px] text-gray-500">{{ $a['sub']->programaContrato->etiqueta() }}{{ $a['sub']->subprograma ? ' · ' . $a['sub']->subprograma : '' }}</div>@endif
                    <div class="mt-2 space-y-1">
                        @forelse ($a['tipos'] as $x)
                            @php $pct = $x['planeadas'] ? min(100, (int) round($x['piezas'] * 100 / max(1, $x['planeadas']))) : null; @endphp
                            <div class="text-xs">
                                <div class="flex justify-between"><span class="text-gray-700">{{ $x['tipo']->nombre }}</span><span class="tabular-nums text-gray-600">{{ $x['piezas'] }}{{ $x['planeadas'] !== null ? ' / ' . $x['planeadas'] : '' }}</span></div>
                                @if ($pct !== null)<div class="h-1 mt-0.5 rounded-full bg-gray-100"><div class="h-1 rounded-full {{ $pct >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' }}" style="width: {{ $pct }}%"></div></div>@endif
                            </div>
                        @empty
                            <p class="text-[11px] text-gray-400">{{ __('Sin tipos de publicación asignados.') }}</p>
                        @endforelse
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Filtros --}}
    <form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Subcampaña') }}</label>
            <select name="subcampana" class="{{ $sel }}" onchange="this.form.submit()"><option value="">{{ __('Todas') }}</option><option value="-1" @selected(request('subcampana') === '-1')>{{ __('Sin subcampaña') }}</option>@foreach ($subs as $s)<option value="{{ $s->id }}" @selected((string) request('subcampana') === (string) $s->id)>{{ $s->nombre }}</option>@endforeach</select></div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Tipo') }}</label>
            <select name="tipo" class="{{ $sel }}" onchange="this.form.submit()"><option value="">{{ __('Todos') }}</option>@foreach ($tipos as $s)<option value="{{ $s->id }}" @selected((string) request('tipo') === (string) $s->id)>{{ $s->nombre }}</option>@endforeach</select></div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Programa') }}</label>
            <select name="programa" class="{{ $sel }} max-w-xs" onchange="this.form.submit()"><option value="">{{ __('Todos') }}</option>@foreach ($programas as $p)<option value="{{ $p->id }}" @selected((string) request('programa') === (string) $p->id)>{{ $p->etiqueta() }}</option>@endforeach</select></div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Estado') }}</label>
            <select name="estado" class="{{ $sel }}" onchange="this.form.submit()"><option value="">{{ __('Todos') }}</option>@foreach ($estados as $e)<option value="{{ $e }}" @selected(request('estado') === $e)>{{ __($e) }}</option>@endforeach</select></div>
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Buscar') }}</label>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Nombre de la pieza') }}" class="{{ $sel }}"></div>
        <button class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Filtrar') }}</button>
    </form>

    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500"><tr>
                <th class="px-3 py-2 text-left w-20">{{ __('Muestra') }}</th>
                <th class="px-3 py-2 text-left">{{ __('Pieza') }}</th>
                <th class="px-3 py-2 text-left">{{ __('Subcampaña') }}</th>
                <th class="px-3 py-2 text-left">{{ __('Programa') }}</th>
                <th class="px-3 py-2 text-left">{{ __('Estado') }}</th>
                <th class="px-3 py-2 text-left">{{ __('Entrega') }}</th>
                <th class="px-3 py-2 text-right">{{ __('Publicaciones') }}</th>
                <th class="px-3 py-2"></th>
            </tr></thead>
            <tbody>
                @forelse ($productos as $p)
                    @php $miniatura = \App\Services\ProductosService::url($c, $p->muestra_path ? ['local', $p->muestra_path] : null); @endphp
                    <tr class="border-t border-gray-100 align-middle">
                        <td class="px-3 py-2">@if ($miniatura)<img src="{{ $miniatura }}" alt="" class="h-12 w-16 object-cover rounded-md border border-gray-100">@else<div class="h-12 w-16 rounded-md bg-gray-50 border border-dashed border-gray-200"></div>@endif</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('campaigns.productos.ver', [$c, $p]) }}" class="font-semibold text-gray-900 hover:text-indigo-700">{{ $p->nombre }}</a>
                            <div class="text-[11px] text-gray-500">{{ $p->codigo() }}@if ($p->tipo?->formato) · {{ $p->tipo->formato }}@endif</div>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-700">{{ $p->subcampana?->nombre ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-700">{{ $p->programa?->etiqueta() ?? '—' }}@if ($p->subprograma)<div class="text-gray-400">{{ $p->subprograma }}</div>@endif</td>
                        <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $colorEstado[$p->estado] ?? 'bg-gray-100 text-gray-700' }}">{{ __($p->estado) }}</span></td>
                        <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">{{ $p->fecha_entrega?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $p->posts_count + $p->publicaciones_count }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap text-xs">
                            <a href="{{ route('campaigns.productos.ver', [$c, $p]) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Abrir') }}</a>
                            @if ($p->link_archivo) · <a href="{{ $p->link_archivo }}" target="_blank" rel="noopener" class="text-indigo-700 hover:underline">{{ __('Archivo') }}</a>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-10 text-center text-sm text-gray-500">{{ __('Aún no hay piezas.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $productos->links() }}</div>

    {{-- Importar el Excel del control de productos --}}
    <form method="POST" action="{{ route('campaigns.productos.importar', $c) }}" enctype="multipart/form-data" class="mt-8 rounded-2xl border border-dashed border-gray-300 bg-white p-5">
        @csrf
        <h3 class="text-sm font-bold text-gray-900">{{ __('Importar el Excel del control de productos') }}</h3>
        <p class="text-xs text-gray-500 mt-1 mb-3">{{ __('Una hoja por tipo de publicación con las columnas «Programa», «Nombre de la pieza», «Responsable», «Fecha solicitud», «Fecha entrega», «Estado», «Link…» y «Observaciones». Se crean los tipos, los programas y las piezas que falten, con su miniatura. Las piezas que ya existen no se repiten.') }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <input type="file" name="archivo" accept=".xlsx,.xlsm" required class="text-sm">
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm hover:opacity-90" onclick="this.disabled=true; this.textContent=@js(__('Importando… puede tardar un minuto')); this.form.submit();">{{ __('Importar') }}</button>
        </div>
    </form>
</div>
@endsection
