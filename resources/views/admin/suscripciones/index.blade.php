@extends('layouts.app')

@section('content')
@php $fmt = fn($n) => '$' . number_format((int) $n, 0, ',', '.'); @endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Administración</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Suscripciones</h1>
            <p class="mt-1.5 text-sm text-gray-500">Clientes, planes, pagos de Wompi y activación manual. El equipo interno (exento) no tiene límites.</p>
        </div>
        <form method="GET" class="flex gap-2"><input name="q" value="{{ $q }}" placeholder="Buscar nombre o correo" class="h-10 w-64 rounded-xl border-gray-200 text-sm shadow-sm"><button class="h-10 rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold">Buscar</button></form>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
        @foreach ([['Activas', $resumen['activas']], ['Básico', $resumen['basico']], ['Full', $resumen['full']], ['Vencen en 7 días', $resumen['vencen_7']], ['Ingresos del mes', $fmt($resumen['ingresos_mes'])]] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $et }}</div><div class="text-xl font-bold text-gray-900">{{ $v }}</div></div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-100"><th class="px-4 py-3">Usuario</th><th>Plan</th><th>Vence</th><th>Licencia</th><th class="text-right px-4">Acciones</th></tr></thead>
            <tbody>
            @foreach ($usuarios as $u)
                @php $s = $u->suscripcion; $activa = $s && $s->activa(); @endphp
                <tr class="border-b border-gray-100 last:border-0 align-top">
                    <td class="px-4 py-3"><div class="font-semibold text-gray-900">{{ $u->name }}</div><div class="text-xs text-gray-500">{{ $u->email }}</div>
                        @if ($u->isAdmin())<span class="text-[10px] font-semibold text-purple-700">administrador</span>@elseif ($u->exento_planes)<span class="text-[10px] font-semibold text-indigo-700">equipo interno · sin límites</span>@endif</td>
                    <td class="py-3">@if ($s)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $activa ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $s->nombrePlan() }} · {{ $s->periodo }}</span>@else<span class="text-xs text-gray-400">—</span>@endif</td>
                    <td class="py-3 text-xs {{ $activa ? 'text-gray-700' : 'text-rose-700' }}">{{ $s?->vence_en?->format('d/m/Y') ?? '—' }}</td>
                    <td class="py-3 text-xs text-gray-500">{{ $s?->licencia_prefijo ? 'ss_' . $s->licencia_prefijo . ' · ' . count($s->licencia_sitios ?? []) . ' sitio(s)' : '—' }}</td>
                    <td class="py-3 px-4">
                        @unless ($u->isAdmin())
                            <div class="flex flex-wrap justify-end gap-2">
                                <form method="POST" action="{{ route('admin.suscripciones.activar', $u) }}" class="flex gap-1">@csrf
                                    <select name="plan" class="h-8 rounded-lg border-gray-200 text-xs">@foreach ($planes as $k => $p)<option value="{{ $k }}">{{ $p['nombre'] }}</option>@endforeach</select>
                                    <select name="periodo" class="h-8 rounded-lg border-gray-200 text-xs"><option value="mensual">mes</option><option value="anual">año</option></select>
                                    <label class="inline-flex items-center gap-1 text-[11px] text-gray-500"><input type="checkbox" name="cobrado" value="1" class="h-3.5 w-3.5 rounded border-gray-300"> cobrado</label>
                                    <button class="h-8 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold" onclick="return confirm('¿Activar o extender este plan a {{ $u->name }}?')">Activar</button></form>
                                @if ($activa)<form method="POST" action="{{ route('admin.suscripciones.cancelar', $u) }}">@csrf<button class="h-8 rounded-lg border border-rose-200 px-3 text-xs font-semibold text-rose-700" onclick="return confirm('¿Cancelar la suscripción de {{ $u->name }}?')">Cancelar</button></form>@endif
                                <form method="POST" action="{{ route('admin.suscripciones.exento', $u) }}">@csrf<button class="h-8 rounded-lg border border-gray-200 px-3 text-xs font-semibold text-gray-700">{{ $u->exento_planes ? 'Quitar exento' : 'Marcar exento' }}</button></form>
                            </div>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="px-4 py-3">{{ $usuarios->links() }}</div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 overflow-x-auto">
        <h3 class="text-base font-bold text-gray-900 mb-2">Últimos pagos</h3>
        <table class="w-full text-sm"><thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="py-1.5">Fecha</th><th>Usuario</th><th>Plan</th><th>Valor</th><th>Estado</th><th>Origen</th></tr></thead><tbody>
            @forelse ($pagos as $p)
                <tr class="border-t border-gray-100"><td class="py-1.5">{{ $p->created_at->format('d/m/Y H:i') }}</td><td>{{ $p->user?->name }}</td><td>{{ $planes[$p->plan]['nombre'] ?? $p->plan }} · {{ $p->periodo }}</td><td>{{ $fmt($p->monto_centavos / 100) }}</td><td>{{ $p->estado }}</td><td class="text-xs text-gray-500">{{ $p->origen }}{{ $p->metodo ? ' · ' . $p->metodo : '' }}</td></tr>
            @empty
                <tr><td colspan="6" class="py-3 text-sm text-gray-500">Sin pagos todavía.</td></tr>
            @endforelse
        </tbody></table>
    </div>
</div>
@endsection
