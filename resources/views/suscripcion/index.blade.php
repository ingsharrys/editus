@extends('layouts.app')

@section('content')
@php
    $fmt = fn($n) => '$' . number_format((int) $n, 0, ',', '.');
    $activa = $suscripcion && $suscripcion->activa();
    $planActual = $activa ? $suscripcion->plan : null;
    $max = $activa ? (int) ($suscripcion->config()['paginas'] ?? 0) : 0;
@endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Cuenta</div>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Mi suscripción</h1>
        <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">Tu plan define en cuántas páginas publicas, cuántas cámaras usas en vivo y la licencia del plugin SharryStreem para WordPress.</p>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    @if ($sinLimites)
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50 text-indigo-900 px-5 py-4 mb-6 text-sm">Tu cuenta es del <strong>equipo interno</strong>: no tiene límites de plan.</div>
    @else
        {{-- Estado actual --}}
        <div class="rounded-2xl {{ $activa ? 'bg-[#00024f] text-white' : 'border border-amber-200 bg-amber-50 text-amber-900' }} p-5 shadow-sm mb-6">
            @if ($activa)
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-200">Plan activo</div>
                        <div class="text-2xl font-bold mt-0.5">{{ $suscripcion->nombrePlan() }} <span class="text-sm font-medium text-indigo-200">· {{ $suscripcion->periodo }}</span></div>
                        <div class="text-sm text-indigo-100 mt-1">Vence el {{ $suscripcion->vence_en->format('d/m/Y') }} · quedan {{ $suscripcion->diasRestantes() }} día(s)</div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        @foreach ([['Páginas', $suscripcion->config()['paginas'] ?? 0], ['Cámaras', $suscripcion->config()['camaras'] ?? 0], ['Sitios WP', $suscripcion->config()['sitios'] ?? 0]] as [$et, $v])
                            <div class="rounded-xl bg-white/10 px-4 py-2"><div class="text-xl font-bold">{{ $v }}</div><div class="text-[11px] text-indigo-200">{{ $et }}</div></div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="font-semibold">{{ $suscripcion ? 'Tu plan venció el ' . $suscripcion->vence_en?->format('d/m/Y') . '.' : 'Aún no tienes un plan.' }}</div>
                <div class="text-sm mt-1">Para publicar, transmitir y usar el plugin, elige un plan. Puedes conectar tus páginas de Facebook desde ya.</div>
            @endif
        </div>
    @endif

    {{-- Planes --}}
    @unless ($sinLimites)
        <div class="grid md:grid-cols-2 gap-4 mb-6">
            @foreach ($planes as $clave => $p)
                <div class="rounded-2xl border {{ $planActual === $clave ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-gray-200' }} bg-white shadow-sm p-5 flex flex-col">
                    <div class="flex items-center justify-between"><h2 class="text-lg font-bold text-gray-900">{{ $p['nombre'] }}</h2>
                        @if ($planActual === $clave)<span class="rounded-full bg-indigo-50 text-indigo-700 px-2 py-0.5 text-[11px] font-semibold">tu plan</span>@endif</div>
                    <div class="mt-2"><span class="text-3xl font-bold text-[#00024f]">{{ $fmt($p['mensual']) }}</span> <span class="text-sm text-gray-500">COP / mes</span></div>
                    <div class="text-xs text-emerald-700 font-semibold mt-0.5">Anual {{ $fmt($p['anual']) }} · un mes gratis</div>
                    <ul class="mt-4 space-y-1.5 text-sm text-gray-700 flex-1">
                        @foreach ($p['resumen'] as $r)<li class="flex gap-2"><span class="text-emerald-600">✓</span>{{ $r }}</li>@endforeach
                        @unless ($p['plantillas_logo'])<li class="flex gap-2 text-gray-400"><span>✕</span>Sin plantillas con logo en el en vivo</li>@endunless
                    </ul>
                    <div class="grid grid-cols-2 gap-2 mt-5">
                        @foreach (['mensual' => 'Pagar mes', 'anual' => 'Pagar año'] as $per => $et)
                            <form method="POST" action="{{ route('suscripcion.pagar') }}">@csrf<input type="hidden" name="plan" value="{{ $clave }}"><input type="hidden" name="periodo" value="{{ $per }}">
                                <button class="w-full h-10 rounded-xl {{ $per === 'anual' ? 'bg-[#00024f] text-white' : 'border border-gray-200 bg-white text-gray-800' }} text-sm font-semibold hover:opacity-90 disabled:opacity-50" @disabled(!$wompiListo)>{{ $et }}</button></form>
                        @endforeach
                    </div>
                    @if ($planActual && $planActual !== $clave)<p class="text-[11px] text-gray-400 mt-2">Cambiar de plan inicia un periodo nuevo desde hoy.</p>@elseif ($planActual === $clave)<p class="text-[11px] text-gray-400 mt-2">Renovar suma el periodo a tu fecha de vencimiento.</p>@endif
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mb-6">Pagos seguros con <strong>Wompi</strong> (Bancolombia): tarjeta, PSE, Nequi o botón Bancolombia. @unless ($wompiListo)<span class="text-amber-700">Los pagos en línea aún no están configurados.</span>@endunless</p>
    @endunless

    @if ($activa && !$sinLimites)
        <div class="grid lg:grid-cols-2 gap-4 mb-6">
            {{-- Páginas del plan --}}
            <form method="POST" action="{{ route('suscripcion.paginas') }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                @csrf
                <h3 class="text-base font-bold text-gray-900">Páginas de tu plan <span class="text-sm font-medium text-gray-400">({{ count($permitidas) }} de {{ $max }})</span></h3>
                <p class="text-xs text-gray-500 mt-1 mb-3">Elige en cuáles de tus páginas conectadas publicas (app, web y plugin). Conecta más en <a href="{{ route('meta.pages.index') }}" class="text-indigo-700 underline">Mis páginas</a>.</p>
                <div class="space-y-1.5 max-h-64 overflow-y-auto" id="lista-plan">
                    @forelse ($conectadas as $p)
                        <label class="flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                            <input type="checkbox" name="paginas[]" value="{{ $p->id }}" @checked(in_array((int) $p->id, $permitidas, true)) class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                            <span class="text-sm text-gray-800 truncate">{{ $p->name }}</span>@if ($p->instagram_business_account_id)<span class="text-[10px] font-semibold text-pink-600">+ Instagram</span>@endif
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">Aún no has conectado páginas.</p>
                    @endforelse
                </div>
                @if (count($conectadas))<button class="mt-3 h-9 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold">Guardar páginas</button>@endif
            </form>

            {{-- Licencia del plugin --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                <h3 class="text-base font-bold text-gray-900">Licencia del plugin SharryStreem</h3>
                <p class="text-xs text-gray-500 mt-1 mb-3">Instala el plugin en tu WordPress (Plugins → Añadir nuevo → Subir plugin) y pega la llave en Ajustes → SharryStreem. Funciona mientras tu plan esté activo.</p>
                <a href="{{ asset('descargas/sharrystreem-1.0.0.zip') }}" class="inline-flex items-center gap-2 h-9 rounded-lg bg-indigo-50 text-indigo-800 px-3 text-xs font-semibold hover:bg-indigo-100 mb-3" download>⬇ Descargar plugin SharryStreem 1.0.0</a>
                @if ($licenciaNueva)
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 mb-3">
                        <div class="text-xs font-semibold text-emerald-800 mb-1">Tu llave (cópiala ahora; no se vuelve a mostrar completa):</div>
                        <div class="flex gap-2"><input id="llave" readonly value="{{ $licenciaNueva }}" class="flex-1 min-w-0 h-9 rounded-lg border-gray-200 font-mono text-xs bg-white">
                            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('llave').value); this.textContent='¡Copiada!'" class="h-9 rounded-lg bg-emerald-700 text-white px-3 text-xs font-semibold">Copiar</button></div>
                    </div>
                @elseif ($suscripcion->licencia_prefijo)
                    <div class="font-mono text-sm text-gray-700 rounded-lg bg-gray-50 px-3 py-2 mb-3">ss_{{ $suscripcion->licencia_prefijo }}_••••••••••••</div>
                @endif
                <div class="text-xs text-gray-600 mb-2">Sitios vinculados ({{ count($suscripcion->licencia_sitios ?? []) }} de {{ $suscripcion->config()['sitios'] ?? 1 }}):</div>
                @forelse ($suscripcion->licencia_sitios ?? [] as $sitio)
                    <form method="POST" action="{{ route('suscripcion.sitio.liberar') }}" class="flex items-center justify-between gap-2 py-1 border-b border-gray-100 last:border-0">@csrf
                        <input type="hidden" name="sitio" value="{{ $sitio['url'] ?? '' }}"><span class="text-sm text-gray-800 truncate">{{ $sitio['url'] ?? '' }}</span>
                        <button class="text-[11px] font-semibold text-rose-700 hover:underline" onclick="return confirm('¿Desvincular este sitio?')">Desvincular</button></form>
                @empty
                    <p class="text-xs text-gray-400">Ninguno todavía.</p>
                @endforelse
                <form method="POST" action="{{ route('suscripcion.licencia') }}" class="mt-4" onsubmit="return confirm('Se creará una llave nueva y la actual dejará de funcionar en todos los sitios. ¿Continuar?')">@csrf
                    <button class="h-9 rounded-lg border border-gray-200 bg-white px-4 text-xs font-semibold text-gray-700 hover:bg-gray-50">{{ $suscripcion->licencia_prefijo ? 'Crear llave nueva' : 'Crear llave' }}</button></form>
            </div>
        </div>
    @endif

    @if (!$sinLimites && $pagos->isNotEmpty())
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 overflow-x-auto">
            <h3 class="text-base font-bold text-gray-900 mb-2">Pagos</h3>
            <table class="w-full text-sm"><thead><tr class="text-left text-[11px] uppercase tracking-wide text-gray-400"><th class="py-1.5">Fecha</th><th>Plan</th><th>Valor</th><th>Estado</th><th>Referencia</th></tr></thead><tbody>
                @foreach ($pagos as $p)
                    <tr class="border-t border-gray-100"><td class="py-1.5">{{ $p->created_at->format('d/m/Y H:i') }}</td><td>{{ $planes[$p->plan]['nombre'] ?? $p->plan }} · {{ $p->periodo }}</td><td>{{ $fmt($p->monto_centavos / 100) }}</td>
                        <td><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $p->estado === 'APPROVED' ? 'bg-emerald-50 text-emerald-700' : ($p->estado === 'PENDING' ? 'bg-amber-50 text-amber-800' : 'bg-rose-50 text-rose-700') }}">{{ ['APPROVED' => 'aprobado', 'PENDING' => 'pendiente', 'DECLINED' => 'rechazado', 'VOIDED' => 'anulado', 'ERROR' => 'error'][$p->estado] ?? $p->estado }}</span></td>
                        <td class="font-mono text-[11px] text-gray-500">{{ $p->referencia }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    @endif
</div>
<script>
  // No dejar marcar más páginas de las que permite el plan
  (function () { const max = {{ (int) $max }}; const l = document.getElementById('lista-plan'); if (!l || !max) return;
    const sync = () => { const c = l.querySelectorAll('input:checked').length; l.querySelectorAll('input').forEach(i => { i.disabled = !i.checked && c >= max; }); };
    l.addEventListener('change', sync); sync(); })();
</script>
@endsection
