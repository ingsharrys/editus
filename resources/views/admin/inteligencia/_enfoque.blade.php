{{-- Selector de enfoque: cambia indicadores, lectura de comentarios y recomendaciones. Requiere $enfoque y $urlEnfoque(fn) --}}
@php
    $iconos = ['politica' => '🗳️', 'medio' => '📰', 'comercio' => '🛍️'];
    $ayudas = [
        'politica' => 'Favorabilidad, emociones del electorado, temas que movilizan y riesgos.',
        'medio' => 'Alcance, viralidad, formatos, horarios y crecimiento de audiencia.',
        'comercio' => 'Interés, intención de compra, preguntas, quejas y ventas.',
    ];
@endphp
<div class="flex flex-wrap items-center gap-2 mb-5">
    <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-gray-400 mr-1">Analizar como</span>
    @foreach (\App\Services\Inteligencia\Enfoque::NOMBRES as $k => $n)
        <a href="{{ $urlEnfoque($k) }}" title="{{ $ayudas[$k] }}"
           class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-semibold border transition {{ $enfoque === $k ? 'bg-[#00024f] text-white border-[#00024f] shadow-sm' : 'bg-white text-gray-700 border-gray-200 hover:border-gray-300' }}">
            <span aria-hidden="true">{{ $iconos[$k] }}</span>{{ $n }}
        </a>
    @endforeach
    <span class="text-xs text-gray-500 ml-1 hidden md:inline">{{ $ayudas[$enfoque] }}</span>
</div>
