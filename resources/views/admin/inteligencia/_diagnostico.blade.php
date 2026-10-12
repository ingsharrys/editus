{{-- Diagnóstico estratégico con IA. Requiere $av, $diagnosticos, $iaLista. El contenido lo dibuja _avanzado_js. --}}
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm mb-5 print:hidden">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <h3 class="font-bold text-gray-900 text-lg">✦ {{ __('Diagnóstico estratégico — :enfoque', ['enfoque' => __($av['enfoque_nombre'])]) }}</h3>
            <p class="text-sm text-gray-600 mt-1">{{ __('La IA interpreta los indicadores calculados de este periodo (emociones, comportamiento, impulsores, tendencias y pronósticos) y entrega:') }}
                {{ __('estado general, lectura emocional, escenarios a 4 semanas, oportunidades, riesgos, acciones con su KPI y meta, y un plan de publicaciones para la semana.') }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('Tarda entre 30 y 90 segundos.') }} {{ __('Calidad de los datos del periodo:') }} <b>{{ __($av['calidad']['nivel']) }}</b>{{ $av['calidad']['nivel'] === 'baja' ? ' ' . __('(el diagnóstico será orientativo)') : '' }}.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($diagnosticos)
                <select id="diag-historial" class="h-10 rounded-xl border-gray-200 text-sm shadow-sm max-w-[16rem]">
                    @foreach ($diagnosticos as $d)<option value="{{ $d['id'] }}">{{ $d['fecha'] }} · {{ __($d['enfoque_nombre']) }} · {{ __(':desde a :hasta', ['desde' => !empty($d['ambito']['desde']) ? \Carbon\Carbon::parse($d['ambito']['desde'])->format('d/m') : '', 'hasta' => !empty($d['ambito']['hasta']) ? \Carbon\Carbon::parse($d['ambito']['hasta'])->format('d/m/Y') : '']) }}</option>@endforeach
                </select>
                <button type="button" onclick="window.print()" class="h-10 rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-700 shadow-sm hover:bg-gray-50" title="{{ __('Imprimir o guardar en PDF') }}">⎙</button>
            @endif
            <button type="button" id="diag-boton" class="h-10 rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm disabled:opacity-50" @disabled(!$iaLista)>✦ {{ __('Generar diagnóstico') }}</button>
        </div>
    </div>
    @unless ($iaLista)<p class="text-xs text-amber-700 mt-2">{{ __('IA no configurada (ANTHROPIC_API_KEY en el .env).') }}</p>@endunless
    <div id="diag-estado" class="hidden mt-4 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3 text-sm text-indigo-900"></div>
</div>
<div id="diag-contenido">
    @unless ($diagnosticos)
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500">{{ __('Todavía no hay diagnósticos para este alcance. Pulsa «Generar diagnóstico».') }}</div>
    @endunless
</div>
