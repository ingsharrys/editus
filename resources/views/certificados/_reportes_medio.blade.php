{{-- Portal del medio: pantallazos de TikTok con el alcance y capturas de los banners publicados en su web. --}}
@php
    $rep = app(\App\Services\ReportesMedioService::class);
    $esElMedio = (int) $participante->user_id === (int) auth()->id();
    $hechos = $rep->delMedio($participante);
    $inp = 'mt-1 w-full h-10 rounded-lg border-gray-200 text-sm';
@endphp
@foreach (['tiktok' => ['🎵', __('TikTok: pantallazos con el alcance'), __('Sube el pantallazo de las estadísticas de cada video de TikTok de la campaña, con su alcance. Cada reporte cuenta en el avance del plan de medios y sale en el informe con su pantallazo.')],
           'banner' => ['🖼️', __('Banners publicados en tu web'), __('Sube la captura del banner de la campaña publicado en tu página web, con la dirección y las fechas en que estuvo.')]] as $clase => [$icono, $titulo, $ayuda])
    @php
        $opc = $rep->opciones($campana, $clase);
        $mios = $hechos->where('clase_reporte', $clase);
    @endphp
    @if ($opc->isNotEmpty() && ($esElMedio || $mios->isNotEmpty()))
        <div id="{{ $clase }}" class="mt-8 rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <h2 class="text-lg font-bold text-gray-900">{{ $icono }} {{ $titulo }}</h2>
            <p class="text-sm text-gray-500 mb-4">{{ $ayuda }}</p>
            @if ($errors->any() && old('_reporte') === $clase)<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-2 mb-3 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
            @if ($esElMedio)
                <form method="POST" action="{{ route('reportes-medio.guardar', [$participante, $clase]) }}" enctype="multipart/form-data" class="grid sm:grid-cols-2 gap-3 rounded-xl border border-indigo-100 bg-indigo-50/40 p-4 mb-5" data-reporte="{{ $clase }}">
                    @csrf <input type="hidden" name="_reporte" value="{{ $clase }}">
                    @if ($opc->first()['sub'])
                        <label class="text-xs font-semibold text-gray-600">{{ __('Subcampaña') }} *
                            <select name="subcampana_id" required class="{{ $inp }}" data-sub>
                                <option value="">—</option>
                                @foreach ($opc as $o)<option value="{{ $o['sub']->id }}">{{ $o['sub']->nombreCompleto() }}</option>@endforeach
                            </select></label>
                    @endif
                    <label class="text-xs font-semibold text-gray-600">{{ __('Tipo de publicación') }} *<select name="tipo_id" required class="{{ $inp }}" data-tipo><option value="">—</option></select></label>
                    @if ($clase === 'tiktok')
                        <label class="text-xs font-semibold text-gray-600">{{ __('Enlace del video') }}<input type="url" name="url" maxlength="1000" placeholder="https://www.tiktok.com/@…/video/…" class="{{ $inp }}"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Fecha de publicación') }} *<input type="date" name="fecha" required class="{{ $inp }}"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Alcance (personas)') }} *<input type="number" name="alcance" min="0" required class="{{ $inp }}"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Visualizaciones') }}<input type="number" name="visualizaciones" min="0" class="{{ $inp }}"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Interacciones (me gusta, comentarios, compartidos)') }}<input type="number" name="interacciones" min="0" class="{{ $inp }}"></label>
                    @else
                        <label class="text-xs font-semibold text-gray-600 sm:col-span-2">{{ __('Dirección de la página donde está el banner') }} *<input type="url" name="url" required maxlength="1000" placeholder="https://…" class="{{ $inp }}"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Publicado desde') }} *<input type="date" name="fecha" required class="{{ $inp }}"></label>
                        <label class="text-xs font-semibold text-gray-600">{{ __('Hasta') }}<input type="date" name="fecha_fin" class="{{ $inp }}"></label>
                    @endif
                    <label class="text-xs font-semibold text-gray-600">{{ __('Descripción corta') }}<input name="descripcion" maxlength="120" placeholder="{{ $clase === 'tiktok' ? __('Ej: Video vacunación') : __('Ej: Banner portada') }}" class="{{ $inp }}"></label>
                    <label class="text-xs font-semibold text-gray-600 sm:col-span-2">{{ $clase === 'tiktok' ? __('Pantallazo de las estadísticas (con el alcance)') : __('Captura del banner publicado') }} *
                        <input type="file" name="captura" accept="image/*" required class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-sm file:font-semibold"></label>
                    <div class="sm:col-span-2 flex justify-end"><button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold">{{ __('Guardar reporte') }}</button></div>
                </form>
            @endif
            <div class="space-y-2">
                @forelse ($mios as $x)
                    @php $pub = $x->publicaciones->first(); @endphp
                    <div class="flex items-center gap-3 rounded-xl border border-gray-100 p-2">
                        @if ($pub?->evidencia_path)<a href="{{ route('reportes-medio.captura', $pub) }}" target="_blank"><img src="{{ route('reportes-medio.captura', $pub) }}" alt="" class="h-14 w-14 rounded-lg object-cover bg-gray-100"></a>@endif
                        <div class="min-w-0 flex-1 text-sm">
                            <div class="font-semibold text-gray-900 truncate">{{ $x->nombre }}</div>
                            <div class="text-xs text-gray-500">{{ $x->subcampana?->nombreCompleto() ?? '—' }} · {{ $x->tipo?->etiquetaSingular() }} · {{ $x->fecha_publicacion?->format('d/m/Y') }}{{ $x->fecha_fin ? ' – ' . $x->fecha_fin->format('d/m/Y') : '' }}
                                @if ($pub?->alcance !== null) · {{ __('alcance :n', ['n' => number_format($pub->alcance, 0, ',', '.')]) }}@endif
                                @if ($pub?->url) · <a href="{{ $pub->url }}" target="_blank" rel="noopener" class="text-indigo-700 hover:underline">{{ __('ver') }}</a>@endif</div>
                        </div>
                        @if ($esElMedio)
                            <form method="POST" action="{{ route('reportes-medio.borrar', $x) }}" onsubmit="return confirm(@js(__('¿Borrar este reporte?')))">@csrf @method('DELETE')<button class="text-xs text-rose-600">{{ __('Borrar') }}</button></form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500 text-center py-4">{{ $clase === 'tiktok' ? __('Todavía no hay pantallazos de TikTok.') : __('Todavía no hay banners reportados.') }}</p>
                @endforelse
            </div>
            @if ($esElMedio)
                <script>
                (function () {
                    const OP = @js($opc->mapWithKeys(fn($o) => [(string) ($o['sub']?->id ?? 0) => $o['tipos']->map(fn($t) => ['id' => $t->id, 'nombre' => $t->etiquetaSingular()])->values()]));
                    const f = document.querySelector('[data-reporte="{{ $clase }}"]'), sub = f.querySelector('[data-sub]'), tipo = f.querySelector('[data-tipo]');
                    const llenar = () => {
                        tipo.innerHTML = '<option value="">—</option>';
                        const lista = OP[sub ? sub.value : '0'] || [];
                        lista.forEach(t => { const o = document.createElement('option'); o.value = t.id; o.textContent = t.nombre; tipo.appendChild(o); });
                        if (lista.length === 1) tipo.selectedIndex = 1;
                    };
                    if (sub) sub.addEventListener('change', llenar);
                    llenar();
                })();
                </script>
            @endif
        </div>
    @endif
@endforeach
