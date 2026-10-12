@extends('layouts.app')

@section('content')
@php
    // Monto en centavos con su moneda: COP sin decimales (60.000), USD con dos (19.00)
    $fmtMonto = fn($centavos, $moneda = 'COP') => strtoupper((string) ($moneda ?: 'COP')) === 'USD'
        ? '$' . number_format($centavos / 100, 2) . ' USD'
        : '$' . number_format($centavos / 100, 0, ',', '.') . ' ' . strtoupper((string) ($moneda ?: 'COP'));
    $ingresosMes = collect($resumen['ingresos_mes'])->map(fn($c, $m) => $fmtMonto($c, $m))->implode(' · ') ?: $fmtMonto(0);
    $periodos = ['mensual' => __('mensual'), 'anual' => __('anual')];
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">{{ __('Administración') }}</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">{{ __('Suscripciones') }}</h1>
            <p class="mt-1.5 text-sm text-gray-500">{{ __('Clientes, planes, pagos de Wompi y activación manual. El equipo interno (exento) no tiene límites.') }}</p>
        </div>
        <form method="GET" class="flex gap-2"><input name="q" value="{{ $q }}" placeholder="{{ __('Buscar nombre o correo') }}" class="h-10 w-64 rounded-xl border-gray-200 text-sm shadow-sm"><button class="h-10 rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Buscar') }}</button></form>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
        @foreach ([[__('Activas'), $resumen['activas']], [__('Básico'), $resumen['basico']], ['Full', $resumen['full']], [__('Vencen en 7 días'), $resumen['vencen_7']], [__('Ingresos del mes'), $ingresosMes]] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $et }}</div><div class="text-xl font-bold text-gray-900">{{ $v }}</div></div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-x-auto mb-6">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-100"><th class="px-4 py-3">{{ __('Usuario') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Vence') }}</th><th>{{ __('Licencia') }}</th><th class="text-right px-4">{{ __('Acciones') }}</th></tr></thead>
            <tbody>
            @foreach ($usuarios as $u)
                @php $s = $u->suscripcion; $activa = $s && $s->activa(); @endphp
                <tr class="border-b border-gray-100 last:border-0 align-top">
                    <td class="px-4 py-3"><div class="font-semibold text-gray-900">{{ $u->name }}</div><div class="text-xs text-gray-500">{{ $u->email }}</div>
                        @if ($u->isAdmin())<span class="text-[10px] font-semibold text-purple-700">{{ __('administrador') }}</span>@elseif ($u->exento_planes)<span class="text-[10px] font-semibold text-indigo-700">{{ __('equipo interno · sin límites') }}</span>@endif</td>
                    <td class="py-3">@if ($s)<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $activa ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $s->nombrePlan() }} · {{ $periodos[$s->periodo] ?? $s->periodo }}</span>@else<span class="text-xs text-gray-400">—</span>@endif</td>
                    <td class="py-3 text-xs {{ $activa ? 'text-gray-700' : 'text-rose-700' }}">{{ $s?->vence_en?->format('d/m/Y') ?? '—' }}</td>
                    <td class="py-3 text-xs text-gray-500">{{ $s?->licencia_prefijo ? 'ss_' . $s->licencia_prefijo . ' · ' . __(':n sitio(s)', ['n' => count($s->licencia_sitios ?? [])]) : '—' }}</td>
                    <td class="py-3 px-4">
                        @unless ($u->isAdmin())
                            <div class="flex flex-wrap justify-end gap-2">
                                <form method="POST" action="{{ route('admin.suscripciones.activar', $u) }}" class="flex gap-1">@csrf
                                    <select name="plan" class="h-8 rounded-lg border-gray-200 text-xs">@foreach ($planes as $k => $p)<option value="{{ $k }}">{{ $p['nombre'] }}</option>@endforeach</select>
                                    <select name="periodo" class="h-8 rounded-lg border-gray-200 text-xs"><option value="mensual">{{ __('mes') }}</option><option value="anual">{{ __('año') }}</option></select>
                                    <label class="inline-flex items-center gap-1 text-[11px] text-gray-500"><input type="checkbox" name="cobrado" value="1" class="h-3.5 w-3.5 rounded border-gray-300"> {{ __('cobrado') }}</label>
                                    <button class="h-8 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold" onclick="return confirm(@js(__('¿Activar o extender este plan a :nombre?', ['nombre' => $u->name])))">{{ __('Activar') }}</button></form>
                                @if ($activa)<form method="POST" action="{{ route('admin.suscripciones.cancelar', $u) }}">@csrf<button class="h-8 rounded-lg border border-rose-200 px-3 text-xs font-semibold text-rose-700" onclick="return confirm(@js(__('¿Cancelar la suscripción de :nombre?', ['nombre' => $u->name])))">{{ __('Cancelar') }}</button></form>@endif
                                <form method="POST" action="{{ route('admin.suscripciones.exento', $u) }}">@csrf<button class="h-8 rounded-lg border border-gray-200 px-3 text-xs font-semibold text-gray-700">{{ $u->exento_planes ? __('Quitar exento') : __('Marcar exento') }}</button></form>
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
        <h3 class="text-base font-bold text-gray-900 mb-2">{{ __('Últimos pagos') }}</h3>
        <table class="w-full text-sm"><thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="py-1.5">{{ __('Fecha') }}</th><th>{{ __('Usuario') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Monto') }}</th><th>{{ __('Estado') }}</th><th>{{ __('Origen') }}</th></tr></thead><tbody>
            @forelse ($pagos as $p)
                <tr class="border-t border-gray-100"><td class="py-1.5">{{ $p->created_at->format('d/m/Y H:i') }}</td><td>{{ $p->user?->name }}</td><td>{{ $planes[$p->plan]['nombre'] ?? $p->plan }} · {{ $periodos[$p->periodo] ?? $p->periodo }}</td><td>{{ $fmtMonto((int) $p->monto_centavos, $p->moneda) }}</td><td>{{ $p->estado }}</td><td class="text-xs text-gray-500">{{ $p->origen }}{{ $p->metodo ? ' · ' . $p->metodo : '' }}</td></tr>
            @empty
                <tr><td colspan="6" class="py-3 text-sm text-gray-500">{{ __('Sin pagos todavía.') }}</td></tr>
            @endforelse
        </tbody></table>
    </div>
</div>
@endsection
