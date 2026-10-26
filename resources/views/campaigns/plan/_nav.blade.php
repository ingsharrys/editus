{{-- Encabezado y pestañas del plan de medios de una campaña --}}
<div class="mt-8 lg:mt-10 mb-5">
    <a href="{{ route('campaigns.index') }}" class="text-sm text-indigo-700 hover:underline">← {{ __('Campañas') }}</a>
    <div class="flex flex-wrap items-end justify-between gap-3 mt-1">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600">{{ __('Plan de medios') }}</p>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">{{ $c->name }}</h1>
            @if ($c->starts_on)<p class="text-xs text-gray-500 mt-0.5">{{ $c->starts_on->format('d/m/Y') }}{{ $c->ends_on ? ' – ' . $c->ends_on->format('d/m/Y') : '' }}</p>@endif
        </div>
        <a href="{{ route('campaigns.edit', $c) }}#inscripcion" class="h-9 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ __('Editar campaña') }}</a>
    </div>
    @php
        $pestanas = [
            ['campaigns.plan', __('Tablero')],
            ['campaigns.plan.subcampanas', __('Subcampañas')],
            ['campaigns.plan.cupos', __('Cupos por página')],
            ['campaigns.piezas', __('Clasificar publicaciones')],
        ];
    @endphp
    <nav class="mt-4 flex gap-1 overflow-x-auto border-b border-gray-200">
        @foreach ($pestanas as [$ruta, $et])
            <a href="{{ route($ruta, $c) }}" class="shrink-0 px-3 py-2 text-sm font-semibold border-b-2 -mb-px {{ request()->routeIs($ruta) ? 'border-[#00024f] text-[#00024f]' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $et }}</a>
        @endforeach
    </nav>
</div>
@if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
