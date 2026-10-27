{{-- Encabezado y pestañas del plan de medios de una campaña --}}
<div class="mt-8 lg:mt-10 mb-5">
    <a href="{{ route('campaigns.index') }}" class="text-sm text-indigo-700 hover:underline">← {{ __('Campañas') }}</a>
    <div class="flex flex-wrap items-end justify-between gap-3 mt-1">
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600">{{ __('Plan de medios') }}</p>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">{{ $c->name }}</h1>
            @if ($c->starts_on)<p class="text-xs text-gray-500 mt-0.5">{{ $c->starts_on->format('d/m/Y') }}{{ $c->ends_on ? ' – ' . $c->ends_on->format('d/m/Y') : '' }}</p>@endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if (\App\Services\ProductosService::disponible())
                <a href="{{ route('campaigns.informes', $c) }}" class="h-9 inline-flex items-center gap-1.5 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold hover:opacity-90">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                    {{ __('Descargar informes') }}</a>
            @endif
            <a href="{{ route('campaigns.edit', $c) }}#inscripcion" class="h-9 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ __('Editar campaña') }}</a>
        </div>
    </div>
    @php
        $pestanas = [
            ['campaigns.plan', __('Tablero'), 'campaigns.plan'],
            ['campaigns.plan.subcampanas', __('Subcampañas'), ['campaigns.plan.subcampanas*', 'campaigns.subcampanas.*', 'campaigns.productos*', 'campaigns.banners.*']],
            ['campaigns.plan.tipos', __('Tipos de publicación'), 'campaigns.plan.tipos*'],
            ['campaigns.plan.cupos', __('Cupos por página'), 'campaigns.plan.cupos*'],
            ['campaigns.piezas', __('Mover publicaciones'), 'campaigns.piezas*'],
            ['campaigns.informes', __('Informes'), 'campaigns.informes*'],
        ];
        // Productos, programas e informes existen después de migrar
        if (!\App\Services\ProductosService::disponible()) $pestanas = array_values(array_filter($pestanas, fn($x) => !in_array($x[0], ['campaigns.informes', 'campaigns.plan.tipos'], true)));
    @endphp
    <nav class="mt-4 flex gap-1 overflow-x-auto border-b border-gray-200">
        @foreach ($pestanas as [$ruta, $et, $patron])
            <a href="{{ route($ruta, $c) }}" class="shrink-0 px-3 py-2 text-sm font-semibold border-b-2 -mb-px {{ request()->routeIs(...(array) $patron) ? 'border-[#00024f] text-[#00024f]' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $et }}</a>
        @endforeach
    </nav>
</div>
@if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
