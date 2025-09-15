@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8">

    <div class="flex items-center justify-between mb-6 mt-10">
        <h1 class="text-2xl md:text-3xl text-white  font-bold">Informe general</h1>
    </div>

    {{-- Filtro Owner --}}
    <form method="GET" class="mb-6">
        <div class="rounded-2xl bg-white/90 backdrop-blur border border-gray-200 shadow p-4 flex items-center gap-3">
            <div>
                <label class="block text-xs font-medium uppercase tracking-wider text-gray-500">Propietario</label>
                <select name="owner_id"
                        class="border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                    <option value="">Todos</option>
                    @foreach($owners as $o)
                        <option value="{{ $o->id }}" @selected($ownerId == $o->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="bg-indigo-600 text-white px-4 py-2 rounded-xl hover:bg-indigo-700">Aplicar</button>
            @if($ownerId)
                <a href="{{ route('informe.index') }}" class="text-sm px-3 py-2 rounded-xl border hover:bg-gray-50">Limpiar</a>
            @endif
        </div>
    </form>

    {{-- Tarjetas de totales --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm ml-2 text-gray-500">Total publicaciones</div>
            <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_posts ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm ml-2 text-gray-500">Total alcance</div>
            <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_alcance ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm ml-2 text-gray-500">Total visualizaciones</div>
            <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_visualizaciones ?? 0) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-sm ml-2 text-gray-500">Total interacciones</div>
            <div class="mt-2 ml-2 text-2xl font-semibold">{{ number_format($totals->total_interacciones ?? 0) }}</div>
        </div>
    </div>

    {{-- Grid de publicaciones (agrupadas por batch) --}}
    @if ($groups->count() === 0)
        <div class="p-6 border rounded-2xl bg-gray-50">
            <p class="text-gray-600">No hay publicaciones para mostrar.</p>
        </div>
    @else
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-10">
            @foreach ($groups as $g)
                <div class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition">
                    <div class="text-xs text-gray-500 mb-1">
                        {{ \Carbon\Carbon::parse($g->effective_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                    </div>
                    <h3 class="font-semibold line-clamp-2 mb-2">{{ $g->message_sample ?: '— Sin texto —' }}</h3>

                    <div class="grid grid-cols-3 gap-2 text-center text-xs mb-3">
                        <div class="rounded-lg bg-gray-50 p-2">
                            <div class="font-semibold">{{ number_format($g->alcance_sum) }}</div>
                            <div class="text-gray-500">Alcance</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-2">
                            <div class="font-semibold">{{ number_format($g->visualizaciones_sum) }}</div>
                            <div class="text-gray-500">Visualizaciones</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-2">
                            <div class="font-semibold">{{ number_format($g->interacciones_sum) }}</div>
                            <div class="text-gray-500">Interacciones</div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-xs">
                        <span class="text-gray-500">{{ $g->posts_count }} pág.</span>
                        <div class="flex items-center gap-3">
                            @if($g->any_permalink)
                                <a href="{{ $g->any_permalink }}" target="_blank" class="underline">Ver en FB</a>
                            @endif
                            <a href="{{ route('informe.show', $g->group_key) }}" class="text-indigo-600 hover:underline">
                                Ver detalle
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $groups->links() }}
        </div>
    @endif
</div>
@endsection
