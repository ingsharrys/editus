@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            @if ($esAdmin)
                <a href="{{ route('campaigns.edit', $campana) }}#inscripcion" class="text-sm text-indigo-700 hover:underline">← {{ $campana->name }}</a>
            @endif
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ __('Certificado de publicación') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $participante->medio_nombre }} · {{ $campana->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ((int) $participante->user_id === (int) auth()->id())
                <a href="{{ route('certificados.formulario', $campana) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Editar mis datos o firma') }}</a>
            @endif
            @if ($participante->firmado())
                <a href="{{ route('certificados.pdf', $participante) }}" class="h-10 inline-flex items-center rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Descargar PDF') }}</a>
            @endif
        </div>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @unless ($participante->firmado())
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{{ __('Este medio todavía no ha firmado el certificado.') }}</div>
    @endunless

    @php $contratoMedio = \App\Services\ContratosService::disponible() ? app(\App\Services\ContratosService::class)->contratoDe($participante) : null; @endphp
    @if ($contratoMedio)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-5 py-3 mb-5 flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="text-emerald-900">📄 {{ __('Contrato No. :n firmado el :f', ['n' => $contratoMedio->codigo, 'f' => $contratoMedio->firmado_at->format('d/m/Y')]) }}</span>
            <a href="{{ route('contratos.pdf', $contratoMedio) }}" class="h-9 inline-flex items-center rounded-lg bg-white border border-emerald-200 px-3 text-xs font-semibold text-emerald-800">{{ __('Descargar contrato') }}</a>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm px-6 sm:px-14 py-10 sm:py-14">
        @include('certificados._hoja')
    </div>

    @php
        $em = app(\App\Services\EmisionesRadioService::class);
        $opcionesRadio = $em->opciones($campana);
        // Solo la reporta el propio medio, y solo si tiene emisora entre sus canales (si no se saben sus canales, como antes)
        $esLaEmisora = (int) $participante->user_id === (int) auth()->id() && app(\App\Services\ContratosService::class)->tieneCanal($participante, 'radio');
        $reportes = \App\Services\EmisionesRadioService::disponible() ? \App\Models\EmisionRadio::where('participante_id', $participante->id)->with(['subcampana', 'tipo'])->latest('fecha_desde')->latest('id')->get() : collect();
    @endphp
    @if ($opcionesRadio->isNotEmpty() && ($esLaEmisora || $reportes->isNotEmpty()))
        <div id="radio" class="mt-8 rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <h2 class="text-lg font-bold text-gray-900">📻 {{ __('Cuñas y espacios de radio emitidos') }}</h2>
            <p class="text-sm text-gray-500 mb-4">{{ __('Reporta lo que emitió tu emisora para la campaña: cada reporte queda firmado y cuenta en el avance del plan de medios. Adjunta la certificación de la emisora, el audio o la pauta.') }}</p>
            @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-2 mb-3 text-sm">{{ $errors->first() }}</div>@endif

            @if ($esLaEmisora)
                <form method="POST" action="{{ route('emisiones.guardar', $participante) }}" enctype="multipart/form-data" id="formEmision" class="grid sm:grid-cols-2 gap-3 rounded-xl border border-indigo-100 bg-indigo-50/40 p-4 mb-5">
                    @csrf
                    <label class="text-xs font-semibold text-gray-600">{{ __('Subcampaña') }} *
                        <select name="subcampana_id" id="emSub" required class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm">
                            <option value="">—</option>
                            @foreach ($opcionesRadio as $o)<option value="{{ $o['sub']->id }}" @selected((int) old('subcampana_id') === $o['sub']->id)>{{ $o['sub']->programa ? $o['sub']->programa . ' › ' : '' }}{{ $o['sub']->nombre }}</option>@endforeach
                        </select></label>
                    <label class="text-xs font-semibold text-gray-600">{{ __('Tipo de cuña o espacio') }} *
                        <select name="tipo_id" id="emTipo" required class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm"><option value="">—</option></select></label>
                    <label class="text-xs font-semibold text-gray-600">{{ __('Emisiones (cuántas salieron)') }} *<input type="number" name="cantidad" min="1" required value="{{ old('cantidad') }}" class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm"></label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-xs font-semibold text-gray-600">{{ __('Desde') }} *<input type="date" name="fecha_desde" required value="{{ old('fecha_desde') }}" class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Hasta') }}<input type="date" name="fecha_hasta" value="{{ old('fecha_hasta') }}" class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm"></label>
                    </div>
                    <label class="text-xs font-semibold text-gray-600">{{ __('Programa o franja') }}<input name="programa" maxlength="190" value="{{ old('programa') }}" placeholder="{{ __('Ej: Noticiero del mediodía') }}" class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm"></label>
                    <label class="text-xs font-semibold text-gray-600">{{ __('Horario') }}<input name="horario" maxlength="120" value="{{ old('horario') }}" placeholder="{{ __('Ej: 6:00 a 9:00 a. m.') }}" class="mt-1 w-full h-10 rounded-lg border-gray-200 text-sm"></label>
                    <label class="text-xs font-semibold text-gray-600 sm:col-span-2">{{ __('Soporte (certificación de la emisora, audio o pauta)') }}<input type="file" name="soporte" class="mt-1 block w-full text-xs"></label>
                    <label class="text-xs font-semibold text-gray-600 sm:col-span-2">{{ __('Observaciones') }}<textarea name="observaciones" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-200 text-sm">{{ old('observaciones') }}</textarea></label>
                    <div class="sm:col-span-2">
                        <div class="flex items-center justify-between"><span class="text-xs font-semibold text-gray-600">{{ __('Firma') }}</span><button type="button" id="emFirmaBorrar" class="text-[11px] text-gray-500 hover:text-rose-600">{{ __('Borrar') }}</button></div>
                        <div class="relative mt-1 rounded-xl border-2 border-dashed border-gray-300 bg-white"><canvas id="emFirma" class="block w-full h-32 touch-none cursor-crosshair rounded-xl"></canvas>
                            <span id="emFirmaGuia" class="pointer-events-none absolute inset-x-0 bottom-6 mx-8 text-center text-[11px] text-gray-400">{{ $participante->firmado() ? __('Firma aquí, o déjalo en blanco para usar tu firma registrada') : __('Firma aquí con el dedo o el mouse') }}</span></div>
                        <input type="hidden" name="firma" id="emFirmaInput">
                    </div>
                    <label class="sm:col-span-2 flex items-start gap-2 text-xs text-gray-700"><input type="checkbox" name="acepto" value="1" required class="mt-0.5 h-4 w-4 rounded border-gray-300">
                        <span>{{ __('Certifico, como representante de :medio, que estas cuñas o espacios se emitieron en las fechas indicadas para la campaña :campana.', ['medio' => $participante->medio_nombre ?: __('la emisora'), 'campana' => $campana->name]) }}</span></label>
                    <div class="sm:col-span-2 flex justify-end"><button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold">{{ __('Firmar y guardar el reporte') }}</button></div>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-[11px] uppercase text-gray-500 bg-gray-50"><tr><th class="px-2 py-2 text-left">{{ __('Fechas') }}</th><th class="px-2 py-2 text-left">{{ __('Subcampaña') }}</th><th class="px-2 py-2 text-left">{{ __('Tipo') }}</th><th class="px-2 py-2 text-right">{{ __('Emisiones') }}</th><th class="px-2 py-2 text-left">{{ __('Programa / horario') }}</th><th class="px-2 py-2"></th></tr></thead>
                    <tbody>
                        @forelse ($reportes as $r)
                            <tr class="border-t border-gray-100">
                                <td class="px-2 py-2 whitespace-nowrap">{{ $r->fecha_desde->format('d/m/Y') }}@if ($r->fecha_hasta && $r->fecha_hasta->ne($r->fecha_desde)) – {{ $r->fecha_hasta->format('d/m/Y') }}@endif</td>
                                <td class="px-2 py-2">{{ $r->subcampana?->nombre }}</td>
                                <td class="px-2 py-2">{{ $r->tipo?->nombre }}</td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums">{{ $r->cantidad }}</td>
                                <td class="px-2 py-2 text-xs text-gray-500">{{ collect([$r->programa, $r->horario])->filter()->implode(' · ') ?: '—' }}</td>
                                <td class="px-2 py-2 text-right whitespace-nowrap text-xs">
                                    <span class="text-emerald-700" title="{{ __('Firmado el :f', ['f' => $r->firmado_at?->format('d/m/Y H:i')]) }}">✓ {{ __('Firmado') }}</span>
                                    @if ($r->soporte_path)<a href="{{ route('emisiones.soporte', $r) }}" class="ml-2 text-indigo-700 hover:underline">{{ __('Soporte') }}</a>@endif
                                    <form method="POST" action="{{ route('emisiones.borrar', $r) }}" class="inline" onsubmit="return confirm(@js(__('¿Borrar este reporte?')))">@csrf @method('DELETE')<button class="ml-2 text-rose-600">{{ __('Borrar') }}</button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-2 py-6 text-center text-gray-500">{{ __('Todavía no hay cuñas reportadas.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($esLaEmisora)
        <script>
        (function () {
            const OP = @js($opcionesRadio->mapWithKeys(fn($o) => [$o['sub']->id => $o['tipos']->map(fn($x) => ['id' => $x[0]->id, 'nombre' => $x[0]->nombre . ($x[1] ? ' (' . __(':n planeadas', ['n' => $x[1]]) . ')' : '')])]));
            const sub = document.getElementById('emSub'), tipo = document.getElementById('emTipo'), previo = @js((string) old('tipo_id'));
            const llenar = () => { tipo.innerHTML = '<option value="">—</option>'; (OP[sub.value] || []).forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.nombre; if (String(t.id) === previo) o.selected = true; tipo.appendChild(o); }); if ((OP[sub.value] || []).length === 1) tipo.selectedIndex = 1; };
            sub.addEventListener('change', llenar); llenar();
            const cv = document.getElementById('emFirma'), ctx = cv.getContext('2d'), guia = document.getElementById('emFirmaGuia'); let dib = false, hay = false, ult = null;
            const ajustar = () => { const r = cv.getBoundingClientRect(), d = window.devicePixelRatio || 1; cv.width = Math.round(r.width * d); cv.height = Math.round(r.height * d); ctx.setTransform(d, 0, 0, d, 0, 0); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.strokeStyle = '#0b1f5c'; hay = false; guia.classList.remove('hidden'); };
            const pt = e => { const r = cv.getBoundingClientRect(); return {x: e.clientX - r.left, y: e.clientY - r.top}; };
            cv.addEventListener('pointerdown', e => { dib = true; ult = pt(e); cv.setPointerCapture(e.pointerId); e.preventDefault(); });
            cv.addEventListener('pointermove', e => { if (!dib) return; const p = pt(e); ctx.beginPath(); ctx.moveTo(ult.x, ult.y); ctx.lineTo(p.x, p.y); ctx.stroke(); ult = p; hay = true; guia.classList.add('hidden'); });
            ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => cv.addEventListener(t, () => dib = false));
            document.getElementById('emFirmaBorrar').addEventListener('click', ajustar);
            ajustar();
            document.getElementById('formEmision').addEventListener('submit', e => {
                document.getElementById('emFirmaInput').value = hay ? cv.toDataURL('image/png') : '';
                if (!hay && !@js($participante->firmado())) { e.preventDefault(); alert(@js(__('Dibuja tu firma en el recuadro.'))); }
            });
        })();
        </script>
        @endif
    @endif

    @include('certificados._reportes_medio')

    @if ($participante->firmado())
        <p class="mt-3 text-[11px] text-gray-400">
            {{ __('Firmado el :fecha · términos aceptados el :terminos · IP :ip', ['fecha' => $participante->firmado_at->format('d/m/Y H:i'), 'terminos' => $participante->acepto_terminos_at?->format('d/m/Y H:i') ?? '—', 'ip' => $participante->ip ?: '—']) }}
        </p>
    @endif
</div>
@endsection
