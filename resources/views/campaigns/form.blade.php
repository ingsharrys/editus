@extends('layouts.app')

@section('content')
@php
    $editando = $c->exists;
    $mediosSel = old('medios', (array) ($c->medios ?? []));
    $extraSel = array_map('intval', old('paginas_extra', $extra));
    $sinMedio = $paginas->whereNull('medio_slug');
@endphp
<div class="max-w-4xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <a href="{{ route('campaigns.index') }}" class="text-sm text-indigo-700 hover:underline">← {{ __('Campañas') }}</a>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ $editando ? __('Editar campaña') : __('Nueva campaña') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('Los medios que elijas definen en qué páginas se publica. Al publicar podrás excluir páginas puntuales.') }}</p>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ $editando ? route('campaigns.update', $c) : route('campaigns.store') }}" class="space-y-5">
        @csrf

        {{-- 1. Datos --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
            <div class="flex items-center gap-2"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">1</span><h2 class="text-base font-bold text-gray-900">{{ __('Datos de la campaña') }}</h2></div>
            <div class="grid sm:grid-cols-[minmax(0,1fr)_14rem] gap-3">
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Nombre') }} *</label><input name="name" required maxlength="120" value="{{ old('name', $c->name) }}" placeholder="{{ __('Ej: Alcaldía Neiva 2027, Ferretería El Tornillo') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Tipo') }} *</label>
                    <select name="tipo" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                        @foreach ($tipos as $k => $et)<option value="{{ $k }}" @selected(old('tipo', $c->tipo) === $k)>{{ __($et) }}</option>@endforeach
                    </select></div>
            </div>
            <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Descripción corta') }}</label><input name="description" maxlength="500" value="{{ old('description', $c->description) }}" placeholder="{{ __('Para qué es la campaña, en una línea') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
            <div class="grid sm:grid-cols-3 gap-3">
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Territorio') }}</label><input name="territorio" maxlength="120" value="{{ old('territorio', $c->territorio) }}" placeholder="{{ __('Ej: Neiva y norte del Huila') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Desde') }}</label><input type="date" name="starts_on" value="{{ old('starts_on', $c->starts_on?->toDateString()) }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Hasta') }}</label><input type="date" name="ends_on" value="{{ old('ends_on', $c->ends_on?->toDateString()) }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
            </div>
        </div>

        {{-- 2. Medios --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <div class="flex items-center gap-2 mb-1"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">2</span><h2 class="text-base font-bold text-gray-900">{{ __('¿En qué medios se publica?') }} *</h2></div>
            <p class="text-xs text-gray-500 mb-4 ml-8">{{ __('Se usan las páginas de Facebook e Instagram de cada medio. Asigna el medio de cada página en App del editor.') }}</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach ($medios as $slug => $nombre)
                    @php $pags = $porMedio->get($slug, collect()); @endphp
                    <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-3 cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                        <input type="checkbox" name="medios[]" value="{{ $slug }}" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(in_array($slug, $mediosSel, true))>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">{{ $nombre }}</span>
                            <span class="block text-[11px] text-gray-500 truncate" title="{{ $pags->pluck('name')->implode(', ') }}">{{ $pags->count() ? __(':n página(s): :nombres', ['n' => $pags->count(), 'nombres' => $pags->pluck('name')->take(3)->implode(', ') . ($pags->count() > 3 ? '…' : '')]) : __('Sin páginas asignadas') }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <details class="mt-4 rounded-xl border border-gray-200" @if ($extraSel) open @endif>
                <summary class="cursor-pointer select-none px-3 py-2.5 text-sm font-semibold text-gray-700">{{ __('Páginas adicionales sin medio') }} <span class="text-gray-400 font-normal">({{ __(':n elegida(s)', ['n' => count($extraSel)]) }})</span></summary>
                <div class="border-t border-gray-100 p-3">
                    <input type="search" placeholder="{{ __('Buscar página') }}" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm mb-2" oninput="const q=this.value.toLowerCase(); this.parentElement.querySelectorAll('[data-nombre]').forEach(e=>e.style.display=!q||e.dataset.nombre.includes(q)?'':'none')">
                    <div class="max-h-60 overflow-y-auto grid sm:grid-cols-2 gap-1">
                        @forelse ($sinMedio as $p)
                            <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 text-sm" data-nombre="{{ mb_strtolower($p->name) }}">
                                <input type="checkbox" name="paginas_extra[]" value="{{ $p->id }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(in_array($p->id, $extraSel, true))>
                                <span class="truncate">{{ $p->name }}</span>@if ($p->instagram_business_account_id)<span class="text-[10px] text-pink-600">IG</span>@endif
                            </label>
                        @empty
                            <p class="text-xs text-gray-500">{{ __('Todas las páginas tienen medio asignado.') }}</p>
                        @endforelse
                    </div>
                </div>
            </details>
        </div>

        {{-- 3. IA --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
            <div class="flex items-center gap-2"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">3</span><h2 class="text-base font-bold text-gray-900">{{ __('Para el análisis con IA') }}</h2></div>
            <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Contexto que lee la IA') }}</label>
                <textarea name="contexto" rows="4" maxlength="3000" placeholder="{{ __('Candidato o marca, propuesta, público objetivo, tono, competencia…') }}" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('contexto', $c->contexto) }}</textarea></div>
            @if ($editando)
                <p class="text-xs text-gray-500">{{ __('Los temas de la campaña se gestionan en') }} <a href="{{ $c->perfil ? route('inteligencia.show', [$c->perfil, 'tab' => 'temas']) : '#' }}" class="text-indigo-700 underline">{{ __('Inteligencia → Temas') }}</a>.</p>
            @else
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Temas a seguir (uno por línea o separados por coma)') }}</label>
                    <textarea name="temas" rows="4" maxlength="2000" placeholder="{{ __('Seguridad') }}&#10;{{ __('Empleo') }}&#10;{{ __('Salud') }}&#10;{{ __('Vías') }}" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('temas') }}</textarea>
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('La IA clasifica cada publicación de la campaña en uno de estos temas.') }}</p></div>
            @endif
        </div>

        {{-- 4. Inscripción de medios y certificado --}}
        @php
            $plan = (bool) old('plan_medios', $c->plan_medios);
            $tiposTxt = old('cert_tipos_pieza', implode("\n", $cert['tipos_pieza']));
            $inscAct = (bool) old('inscripcion_activa', $c->inscripcion_activa);
            $obligatorio = (bool) old('cert_obligatorio', $c->exists ? $cert['obligatorio'] : true);
        @endphp
        <div id="inscripcion" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4 scroll-mt-24">
            <div class="flex items-center gap-2"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">4</span><h2 class="text-base font-bold text-gray-900">{{ __('Plan de medios') }}</h2></div>
            <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-3 cursor-pointer has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                <input type="hidden" name="plan_medios" value="0">
                <input type="checkbox" name="plan_medios" value="1" id="planMedios" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked($plan)>
                <span>
                    <span class="block text-sm font-semibold text-gray-900">{{ __('Esta campaña es un plan de medios') }}</span>
                    <span class="block text-xs text-gray-500">{{ __('Activa la inscripción de medios con enlace, el certificado firmado, las subcampañas, los cupos de piezas por página y el tablero de métricas. Las demás campañas funcionan como siempre.') }}</span>
                </span>
            </label>

            <div id="planCampos" class="space-y-4 {{ $plan ? '' : 'hidden' }}">
            @if ($c->exists && $c->plan_medios)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach ([['campaigns.plan', __('Tablero'), __('Métricas por subcampaña')], ['campaigns.plan.subcampanas', __('Subcampañas'), __(':n creada(s)', ['n' => $nSubs])], ['campaigns.plan.cupos', __('Cupos por página'), __('Límite por tipo de pieza')], ['campaigns.piezas', __('Clasificar'), __('Publicaciones ya hechas')]] as [$ruta, $et, $sub])
                        <a href="{{ route($ruta, $c) }}" class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 hover:border-indigo-300 hover:bg-indigo-50/40"><span class="block text-sm font-semibold text-[#00024f]">{{ $et }} →</span><span class="block text-[11px] text-gray-500">{{ $sub }}</span></a>
                    @endforeach
                </div>
            @elseif ($plan)
                <p class="text-xs text-gray-500">{{ __('Guarda la campaña para configurar sus subcampañas, cupos y tablero.') }}</p>
            @endif

            <h3 class="text-sm font-bold text-gray-900 pt-1">{{ __('Inscripción de medios y certificado') }}</h3>
            <p class="text-xs text-gray-500 -mt-3">{{ __('Envía el enlace a los medios: al entrar o registrarse con él quedan inscritos solo en esta campaña, llenan sus datos y logo, aceptan los términos y firman. Su certificado se llena solo con lo que publiques en sus páginas.') }}</p>

            <label class="flex items-start gap-2 text-sm font-medium text-gray-900">
                <input type="hidden" name="inscripcion_activa" value="0">
                <input type="checkbox" name="inscripcion_activa" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked($inscAct)>
                <span>{{ __('Abrir la inscripción de medios con enlace') }}</span>
            </label>
            <label class="flex items-start gap-2 text-sm text-gray-800">
                <input type="hidden" name="cert_obligatorio" value="0">
                <input type="checkbox" name="cert_obligatorio" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked($obligatorio)>
                <span>{{ __('Firmar el certificado es obligatorio (el medio inscrito no puede usar editus hasta firmarlo)') }}</span>
            </label>

            @if ($c->exists && $c->inscripcion_token)
                <div class="rounded-xl border {{ $c->inscripcion_activa ? 'border-indigo-200 bg-indigo-50/60' : 'border-gray-200 bg-gray-50' }} p-3">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 mb-1">{{ __('Enlace de inscripción') }} @unless ($c->inscripcion_activa)<span class="text-amber-700 normal-case">· {{ __('cerrado') }}</span>@endunless</p>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input id="enlaceInscripcion" readonly value="{{ $c->enlaceInscripcion() }}" class="flex-1 h-10 rounded-lg border-gray-200 bg-white text-xs font-mono" onclick="this.select()">
                        <button type="button" class="h-10 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold" onclick="navigator.clipboard.writeText(document.getElementById('enlaceInscripcion').value).then(() => { this.textContent = @js(__('¡Copiado!')); setTimeout(() => this.textContent = @js(__('Copiar enlace')), 1800); })">{{ __('Copiar enlace') }}</button>
                        <button type="submit" formaction="{{ route('campaigns.inscripcion.regenerar', $c) }}" formnovalidate class="h-10 rounded-lg border border-gray-200 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50" onclick="return confirm(@js(__('¿Crear un enlace nuevo? El anterior dejará de funcionar.')))">{{ __('Cambiar enlace') }}</button>
                    </div>
                </div>
            @elseif ($inscAct || !$c->exists)
                <p class="text-xs text-gray-500">{{ __('El enlace aparece aquí después de guardar con la inscripción abierta.') }}</p>
            @endif

            <div class="grid sm:grid-cols-2 gap-3">
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Entidad de las piezas') }}</label><input name="cert_entidad" maxlength="255" value="{{ old('cert_entidad', $cert['entidad']) }}" placeholder="{{ __('Ej: Secretaría de Salud Departamental del Huila') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Orden de servicios No.') }}</label><input name="cert_orden" maxlength="120" value="{{ old('cert_orden', $cert['orden']) }}" placeholder="{{ __('Ej: FM-1441-963') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Contratista') }}</label><input name="cert_contratista" maxlength="255" value="{{ old('cert_contratista', $cert['contratista']) }}" placeholder="{{ __('Ej: DIME MEDIA S.A.S.') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Contratante') }}</label><input name="cert_contratante" maxlength="255" value="{{ old('cert_contratante', $cert['contratante']) }}" placeholder="{{ __('Ej: Fondo Mixto de Cultura y Turismo del Huila – FOMCULTURA') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
            </div>

            <details class="rounded-xl border border-gray-200" @if ($errors->hasAny(['cert_texto', 'cert_cierre', 'cert_terminos', 'cert_fecha_expedicion'])) open @endif>
                <summary class="cursor-pointer select-none px-3 py-2.5 text-sm font-semibold text-gray-700">{{ __('Texto del certificado y términos') }}</summary>
                <div class="border-t border-gray-100 p-3 space-y-3">
                    <p class="text-[11px] text-gray-500">{{ __('Puedes usar: {medio}, {periodo}, {entidad}, {orden}, {contratista}, {contratante}, {campana}, {ciudad} y {fecha}. El periodo sale de las fechas de la campaña.') }}</p>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Texto principal (después de «CERTIFICA:»)') }}</label>
                        <textarea name="cert_texto" rows="4" maxlength="3000" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('cert_texto', $cert['texto']) }}</textarea></div>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Cierre (después de la tabla)') }}</label>
                        <textarea name="cert_cierre" rows="2" maxlength="1000" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('cert_cierre', $cert['cierre']) }}</textarea></div>
                    <div class="sm:max-w-xs"><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Fecha de expedición') }}</label>
                        <input type="date" name="cert_fecha_expedicion" value="{{ old('cert_fecha_expedicion', $cert['fecha_expedicion']) }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                        <p class="text-[11px] text-gray-400 mt-1">{{ __('Vacía = el día en que se descarga el certificado.') }}</p></div>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Términos y condiciones que acepta el medio') }}</label>
                        <textarea name="cert_terminos" rows="7" maxlength="8000" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('cert_terminos', $cert['terminos']) }}</textarea></div>
                </div>
            </details>

            <div class="sm:max-w-md"><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Tipos de pieza (uno por línea)') }}</label>
                <textarea name="cert_tipos_pieza" rows="5" maxlength="1000" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ $tiposTxt }}</textarea>
                <p class="text-[11px] text-gray-400 mt-1">{{ __('Al publicar en la campaña eliges la subcampaña y el tipo de pieza. Los cupos se definen por tipo.') }}</p></div>

            @if ($c->exists)
                <div>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <h3 class="text-sm font-bold text-gray-900">{{ __('Medios inscritos') }} <span class="font-normal text-gray-400">({{ $participantes->count() }})</span></h3>
                    </div>
                    @if ($participantes->isEmpty())
                        <p class="text-xs text-gray-500">{{ __('Todavía no se ha inscrito ningún medio.') }}</p>
                    @else
                        <div class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500"><tr><th class="px-3 py-2 text-left">{{ __('Medio') }}</th><th class="px-3 py-2 text-left">{{ __('Firma') }}</th><th class="px-3 py-2 text-left">{{ __('Estado') }}</th><th class="px-3 py-2 text-right">{{ __('Piezas') }}</th><th class="px-3 py-2"></th></tr></thead>
                                <tbody>
                                    @foreach ($participantes as $pt)
                                        <tr class="border-t border-gray-100">
                                            <td class="px-3 py-2"><div class="font-medium text-gray-900">{{ $pt->medio_nombre ?: '—' }}</div><div class="text-[11px] text-gray-500">{{ $pt->user?->email }}</div></td>
                                            <td class="px-3 py-2 text-xs text-gray-700">{{ $pt->representante ?: '—' }}@if ($pt->cargo)<div class="text-gray-400">{{ $pt->cargo }}</div>@endif</td>
                                            <td class="px-3 py-2 text-xs">@if ($pt->firmado())<span class="rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 font-semibold">{{ __('Firmado') }}</span><div class="text-gray-400 mt-0.5">{{ $pt->firmado_at->format('d/m/Y') }}</div>@else<span class="rounded-full bg-amber-50 text-amber-700 px-2 py-0.5 font-semibold">{{ __('Pendiente') }}</span>@endif</td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ $piezasPorParticipante[$pt->id] ?? 0 }}</td>
                                            <td class="px-3 py-2 text-right whitespace-nowrap text-xs">
                                                <a href="{{ route('certificados.ver', $pt) }}" class="font-semibold text-indigo-700 hover:underline">{{ __('Ver') }}</a>
                                                @if ($pt->firmado()) · <a href="{{ route('certificados.pdf', $pt) }}" class="font-semibold text-indigo-700 hover:underline">PDF</a>@endif
                                                · <button type="submit" form="quitar-{{ $pt->id }}" class="text-rose-600 hover:underline" onclick="return confirm(@js(__('¿Retirar este medio de la campaña? Se borran sus datos y su firma.')))">{{ __('Retirar') }}</button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
            </div>
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('campaigns.index') }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancelar') }}</a>
            @if ($editando)
                <button name="quedarse" value="1" class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Guardar y seguir editando') }}</button>
            @endif
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ $editando ? __('Guardar cambios') : __('Crear campaña') }}</button>
        </div>
    </form>
    @foreach ($participantes as $pt)
        <form id="quitar-{{ $pt->id }}" method="POST" action="{{ route('campaigns.participantes.quitar', [$c, $pt]) }}" class="hidden">@csrf @method('DELETE')</form>
    @endforeach
    <script>document.getElementById('planMedios')?.addEventListener('change', e => document.getElementById('planCampos').classList.toggle('hidden', !e.target.checked));</script>
    @if (session('success'))<script>document.addEventListener('DOMContentLoaded', () => { if (location.hash === '#inscripcion') document.getElementById('inscripcion')?.scrollIntoView(); });</script>@endif
</div>
@endsection
