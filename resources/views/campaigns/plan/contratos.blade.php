@extends('layouts.app')

@section('content')
@php
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $inp = 'w-full h-9 rounded-lg border-gray-200 text-sm';
    $firmados = $vigentes->count();
    $activos = $medios->where('activo', true)->count();
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @include('campaigns.plan._nav')

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        @foreach ([[__('Medios en el listado'), $activos], [__('Contratos firmados'), $firmados], [__('Por firmar'), max(0, $activos - $firmados)], [__('Próximo número'), $siguiente]] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $et }}</div><div class="text-xl font-bold text-gray-900 truncate">{{ $v }}</div></div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-indigo-100 bg-indigo-50/50 px-4 py-3 mb-6 text-sm text-indigo-900">
        {{ __('Cómo funciona: tú cargas los medios con sus canales (página web, redes con o sin pauta, emisora…) y su tipo de contrato. Cada medio entra con el enlace de inscripción, elige cuál es de este listado, llena el encabezado (logo, membrete, representante legal, identificación, el dato de cada canal, RUT, certificado bancario, correo), firma el contrato que le asignaste y recibe la copia en PDF por correo. Solo después vincula su medio y llena el certificado. En su portal solo ve lo que le toca reportar según sus canales.') }}
        @if ($enlace)<div class="mt-2"><span class="font-semibold">{{ __('Enlace de inscripción:') }}</span> <a href="{{ $enlace }}" class="underline break-all" target="_blank">{{ $enlace }}</a></div>
        @else<div class="mt-2">{{ __('La campaña aún no tiene enlace de inscripción:') }} <a href="{{ route('campaigns.edit', $c) }}#inscripcion" class="underline">{{ __('actívalo en «Editar campaña»') }}</a>.</div>@endif
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_24rem] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            {{-- ================= Medios ================= --}}
            <section id="medios" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="px-5 py-4 border-b border-gray-100"><h2 class="text-base font-bold text-gray-900">{{ __('Medios de comunicación') }}</h2>
                    <p class="text-xs text-gray-500">{{ __('Cada medio tiene sus canales y su tipo de contrato; al inscribirse, el medio se elige de esta lista.') }}</p></div>
                @forelse ($medios as $m)
                    @php $k = $vigentes->get($m->id); @endphp
                    <details class="border-b border-gray-100 last:border-0 {{ $m->activo ? '' : 'bg-gray-50' }}">
                        <summary class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 cursor-pointer">
                            <span class="font-semibold text-gray-900">{{ $m->nombre }}</span>
                            <span class="text-xs text-gray-500">{{ $m->tipo?->nombre ?? __('sin tipo de contrato') }}</span>
                            @if ($m->valor)<span class="text-xs font-semibold text-gray-700">{{ $m->valorTexto() }}</span>@endif
                            @if ($m->faltaValor())<span class="inline-flex rounded-full bg-amber-50 text-amber-800 px-2 py-0.5 text-[11px] font-semibold">{{ $m->valor ? __('valor impar: no se divide en dos cuotas') : __('falta el valor') }}</span>@endif
                            <span class="flex flex-wrap gap-1">
                                @forelse ($m->canales() as $canal => $x)
                                    <span class="inline-flex rounded-full {{ $x['pauta'] ? 'bg-indigo-50 text-indigo-800' : 'bg-gray-100 text-gray-700' }} px-2 py-0.5 text-[11px] font-medium">{{ \App\Models\MedioCampana::nombreDe($canal, $x['pauta']) }}</span>
                                @empty
                                    <span class="inline-flex rounded-full bg-amber-50 text-amber-800 px-2 py-0.5 text-[11px] font-semibold">{{ __('sin canales') }}</span>
                                @endforelse
                            </span>
                            <span class="ml-auto">
                                @if ($k)<span class="inline-flex rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-[11px] font-semibold">{{ __('Contrato :n firmado', ['n' => $k->codigo]) }}</span>
                                @elseif ($m->participante)<span class="inline-flex rounded-full bg-amber-50 text-amber-800 px-2.5 py-0.5 text-[11px] font-semibold">{{ __('Inscrito, sin firmar') }}</span>
                                @elseif (!$m->activo)<span class="inline-flex rounded-full bg-gray-100 text-gray-500 px-2.5 py-0.5 text-[11px] font-semibold">{{ __('Inactivo') }}</span>
                                @else<span class="inline-flex rounded-full bg-gray-100 text-gray-600 px-2.5 py-0.5 text-[11px] font-semibold">{{ __('Sin inscribir') }}</span>@endif
                            </span>
                        </summary>
                        <div class="px-5 pb-4 space-y-3">
                            @if ($k)
                                <div class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-3 text-sm">
                                    <div class="font-semibold text-emerald-900">{{ __('Contrato No. :n', ['n' => $k->codigo]) }} · {{ $k->firmado_at->format('d/m/Y H:i') }}</div>
                                    <div class="text-xs text-emerald-900/80">{{ $k->dato('representante') }} · {{ $k->dato('documento_tipo') }} {{ $k->dato('documento') }} · {{ $k->dato('correo') }}
                                        · {{ $k->enviado_at ? __('copia enviada') : __('copia sin enviar') . ($k->envio_error ? ' (' . $k->envio_error . ')' : '') }}</div>
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                        <a href="{{ route('contratos.pdf', $k) }}" class="h-8 inline-flex items-center rounded-lg bg-[#00024f] text-white px-3 font-semibold">{{ __('PDF del contrato') }}</a>
                                        @if ($k->rut_path)<a href="{{ route('contratos.archivo', [$k, 'rut']) }}" class="h-8 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 font-semibold text-gray-700">{{ __('RUT') }}</a>@endif
                                        @if ($k->certificado_bancario_path)<a href="{{ route('contratos.archivo', [$k, 'certificado-bancario']) }}" class="h-8 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 font-semibold text-gray-700">{{ __('Certificado bancario') }}</a>@endif
                                        <form method="POST" action="{{ route('contratos.reenviar', $k) }}">@csrf<button class="h-8 rounded-lg border border-gray-200 bg-white px-3 font-semibold text-gray-700">{{ __('Reenviar copia') }}</button></form>
                                        <form method="POST" action="{{ route('campaigns.contratos.anular', [$c, $k->id]) }}" onsubmit="return confirm(@js(__('¿Anular el contrato :n? El medio tendrá que firmar uno nuevo, con otro número.', ['n' => $k->codigo])))">@csrf<button class="h-8 rounded-lg border border-rose-200 bg-white px-3 font-semibold text-rose-700">{{ __('Anular') }}</button></form>
                                    </div>
                                </div>
                            @elseif ($m->participante)
                                <p class="text-xs text-gray-600">{{ __('Lo eligió :u al inscribirse (aún no firma).', ['u' => $m->participante->user?->name . ' · ' . $m->participante->user?->email]) }}
                                    <form method="POST" action="{{ route('campaigns.contratos.medios.liberar', [$c, $m->id]) }}" class="inline">@csrf<button class="underline text-indigo-700">{{ __('Liberar') }}</button></form></p>
                            @endif
                            @foreach ($anulados->get($m->id, collect()) as $a)
                                <p class="text-[11px] text-gray-400">{{ __('Contrato :n anulado el :f', ['n' => $a->codigo, 'f' => $a->anulado_at->format('d/m/Y')]) }} · <a href="{{ route('contratos.pdf', $a) }}" class="underline">PDF</a></p>
                            @endforeach
                            <form method="POST" action="{{ route('campaigns.contratos.medios.guardar', $c) }}" class="grid sm:grid-cols-2 gap-2">
                                @csrf <input type="hidden" name="id" value="{{ $m->id }}">
                                <label class="text-xs text-gray-600">{{ __('Nombre') }}<input name="nombre" value="{{ $m->nombre }}" required maxlength="190" class="{{ $inp }} mt-0.5"></label>
                                <label class="text-xs text-gray-600">{{ __('Tipo de contrato') }}<select name="tipo_id" class="{{ $inp }} mt-0.5"><option value="">—</option>@foreach ($tipos as $t)<option value="{{ $t->id }}" @selected($m->tipo_id === $t->id)>{{ $t->nombre }}</option>@endforeach</select></label>
                                <label class="text-xs text-gray-600">{{ __('Valor del contrato ($)') }}<input name="valor" value="{{ $m->valor ? number_format($m->valor, 0, ',', '.') : '' }}" maxlength="30" inputmode="numeric" placeholder="2.500.000" class="{{ $inp }} mt-0.5"></label>
                                <label class="text-xs text-gray-600">{{ __('Correo (opcional)') }}<input type="email" name="correo" value="{{ $m->correo }}" maxlength="190" class="{{ $inp }} mt-0.5"></label>
                                <label class="text-xs text-gray-600">{{ __('Notas') }}<input name="notas" value="{{ $m->notas }}" maxlength="500" class="{{ $inp }} mt-0.5"></label>
                                <div class="sm:col-span-2 text-xs text-gray-600">{{ __('Canales (salen del tipo de contrato; ajústalos si este medio es distinto y marca la pauta en las redes que la lleven)') }}
                                    <div class="mt-1">@include('campaigns.plan._canales_medio', ['sel' => $m->canales()])</div>
                                    @if ($k)<p class="mt-1 text-[11px] text-gray-400">{{ __('El contrato firmado conserva los canales con que se firmó; un cambio aquí aplica al portal del medio y a un contrato nuevo.') }}</p>@endif
                                </div>
                                <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-2">
                                    <label class="flex items-center gap-2 text-xs text-gray-700"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" @checked($m->activo) class="h-4 w-4 rounded border-gray-300"> {{ __('Activo (aparece en la inscripción)') }}</label>
                                    <div class="flex gap-2"><button class="h-8 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">{{ __('Guardar') }}</button></div>
                                </div>
                            </form>
                            @unless ($k || $anulados->has($m->id))
                                <form method="POST" action="{{ route('campaigns.contratos.medios.borrar', [$c, $m->id]) }}" onsubmit="return confirm(@js(__('¿Borrar :n del listado?', ['n' => $m->nombre])))">@csrf @method('DELETE')<button class="text-xs text-rose-700 hover:underline">{{ __('Borrar del listado') }}</button></form>
                            @endunless
                        </div>
                    </details>
                @empty
                    <p class="px-5 py-8 text-sm text-gray-500 text-center">{{ __('Todavía no hay medios. Agrégalos abajo, uno a uno o en bloque.') }}</p>
                @endforelse
                <div class="grid md:grid-cols-2 gap-4 px-5 py-4 bg-gray-50 rounded-b-2xl border-t border-gray-100">
                    <form method="POST" action="{{ route('campaigns.contratos.medios.guardar', $c) }}" class="space-y-2">
                        @csrf
                        <p class="text-sm font-semibold text-gray-800">{{ __('Agregar un medio') }}</p>
                        <input name="nombre" required maxlength="190" placeholder="{{ __('Nombre del medio') }}" class="{{ $inp }}">
                        <select name="tipo_id" required class="{{ $inp }}"><option value="">{{ __('Tipo de contrato…') }}</option>@foreach ($tipos->where('activo', true) as $t)<option value="{{ $t->id }}">{{ $t->nombre }}</option>@endforeach</select>
                        <p class="text-xs text-gray-500">{{ __('Canales (se marcan solos con el tipo de contrato; ajústalos si hace falta)') }}</p>
                        @include('campaigns.plan._canales_medio', ['sel' => []])
                        <input name="valor" maxlength="30" inputmode="numeric" placeholder="{{ __('Valor del contrato ($), ej.: 2.500.000') }}" class="{{ $inp }}">
                        <input type="email" name="correo" maxlength="190" placeholder="{{ __('Correo (opcional)') }}" class="{{ $inp }}">
                        <button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Agregar') }}</button>
                    </form>
                    <form method="POST" action="{{ route('campaigns.contratos.medios.bloque', $c) }}" class="space-y-2">
                        @csrf
                        <p class="text-sm font-semibold text-gray-800">{{ __('Agregar varios (uno por línea)') }}</p>
                        <textarea name="lista" rows="4" required placeholder="{{ __("Opanoticias; contacto@opanoticias.com; 2.500.000\nNeiva 24; contacto@neiva24.com\nLa Voz de Pitalito") }}" class="w-full rounded-lg border-gray-200 text-sm"></textarea>
                        <p class="text-xs text-gray-500">{{ __('Una línea por medio: «Nombre; correo; valor» (el correo y el valor son opcionales). Todos quedan con este tipo de contrato y sus canales; después ajustas cada uno.') }}</p>
                        <select name="tipo_id" required class="{{ $inp }}"><option value="">{{ __('Tipo de contrato…') }}</option>@foreach ($tipos->where('activo', true) as $t)<option value="{{ $t->id }}">{{ $t->nombre }}</option>@endforeach</select>
                        @include('campaigns.plan._canales_medio', ['sel' => []])
                        <button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Agregar todos') }}</button>
                    </form>
                </div>
            </section>

            {{-- ================= Tipos de contrato ================= --}}
            <section id="tipos" class="rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-start justify-between gap-3">
                    <div><h2 class="text-base font-bold text-gray-900">{{ __('Tipos de contrato') }}</h2>
                        <p class="text-xs text-gray-500">{{ __('Los modelos del PIC 2026 ya están cargados: al agregar un medio solo eliges su modelo. Ábrelos con «Vista previa»; los datos entre llaves, como {medio} o {valor}, se llenan solos al firmar.') }}</p></div>
                    @if ($faltanModelos)
                        <form method="POST" action="{{ route('campaigns.contratos.modelos', $c) }}">@csrf<button class="h-8 rounded-lg border border-indigo-200 bg-indigo-50 px-3 text-xs font-semibold text-indigo-800">{{ __('Cargar los modelos del PIC 2026') }}</button></form>
                    @endif
                </div>
                @foreach ($tipos as $t)
                    <details id="tipo-{{ $t->id }}" class="border-b border-gray-100">
                        <summary class="flex flex-wrap items-center gap-3 px-5 py-3 cursor-pointer">
                            <span class="font-semibold text-gray-900">{{ $t->nombre }}</span>
                            <span class="text-xs text-gray-500">{{ trans_choice('{0} sin medios|{1} 1 medio|[2,*] :count medios', $t->medios_count, ['count' => $t->medios_count]) }}</span>
                            <span class="flex flex-wrap gap-1">@foreach ($t->canalesPorDefecto() as $canal => $x)<span class="inline-flex rounded-full bg-gray-100 text-gray-700 px-2 py-0.5 text-[11px] font-medium">{{ \App\Models\MedioCampana::nombreDe($canal, $x['pauta']) }}</span>@endforeach</span>
                            <a href="{{ route('campaigns.contratos.tipos.vista', [$c, $t->id]) }}" target="_blank" onclick="event.stopPropagation()" class="ml-auto text-xs font-semibold text-indigo-700 hover:underline">{{ __('Vista previa') }}</a>
                            @if (trim(strip_tags((string) $t->cuerpo)) === '')<span class="ml-auto inline-flex rounded-full bg-amber-50 text-amber-800 px-2.5 py-0.5 text-[11px] font-semibold">{{ __('Falta el texto') }}</span>@endif
                        </summary>
                        <form method="POST" action="{{ route('campaigns.contratos.tipos.guardar', $c) }}" class="px-5 pb-4 space-y-2">
                            @csrf <input type="hidden" name="id" value="{{ $t->id }}">
                            <label class="block text-xs text-gray-600">{{ __('Nombre') }}<input name="nombre" value="{{ $t->nombre }}" required maxlength="190" class="{{ $inp }} mt-0.5"></label>
                            <div class="text-xs text-gray-600">{{ __('Canales que cubre (al elegir este contrato, el medio queda con ellos)') }}
                                <div class="mt-1">@include('campaigns.plan._canales_medio', ['sel' => $t->canalesPorDefecto()])</div></div>
                            <div class="grid sm:grid-cols-2 gap-2">
                                <label class="text-xs text-gray-600">{{ __('El medio firma como') }}<input name="rol_medio" value="{{ $t->rol_medio }}" maxlength="60" placeholder="EL CONTRATISTA" class="{{ $inp }} mt-0.5"></label>
                                <label class="text-xs text-gray-600">{{ __('El contratante firma como') }}<input name="rol_contratante" value="{{ $t->rol_contratante }}" maxlength="60" placeholder="EL CONTRATANTE" class="{{ $inp }} mt-0.5"></label>
                            </div>
                            <label class="block text-xs text-gray-600">{{ __('Texto del contrato (HTML o texto: un párrafo por línea)') }}
                                <textarea name="cuerpo" rows="14" class="mt-0.5 w-full rounded-lg border-gray-200 text-sm font-mono">{{ $t->cuerpo }}</textarea></label>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <label class="flex items-center gap-2 text-xs text-gray-700"><input type="hidden" name="activo" value="0"><input type="checkbox" name="activo" value="1" @checked($t->activo) class="h-4 w-4 rounded border-gray-300"> {{ __('Activo') }}</label>
                                <div class="flex gap-2">
                                    <a href="{{ route('campaigns.contratos.tipos.vista', [$c, $t->id]) }}" target="_blank" class="h-8 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700">{{ __('Vista previa') }}</a>
                                    <button class="h-8 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">{{ __('Guardar') }}</button>
                                </div>
                            </div>
                        </form>
                        @if (!$t->medios_count)
                            <form method="POST" action="{{ route('campaigns.contratos.tipos.borrar', [$c, $t->id]) }}" class="px-5 pb-3" onsubmit="return confirm(@js(__('¿Borrar el tipo de contrato «:n»?', ['n' => $t->nombre])))">@csrf @method('DELETE')<button class="text-xs text-rose-700 hover:underline">{{ __('Borrar tipo') }}</button></form>
                        @endif
                    </details>
                @endforeach
                <details class="bg-gray-50 rounded-b-2xl">
                    <summary class="px-5 py-3 text-sm font-semibold text-gray-700 cursor-pointer">{{ __('Crear otro tipo de contrato') }}</summary>
                    <form method="POST" action="{{ route('campaigns.contratos.tipos.guardar', $c) }}" class="px-5 pb-4 space-y-2">
                        @csrf
                        <input name="nombre" required maxlength="190" placeholder="{{ __('Ej: Contrato emisoras') }}" class="{{ $inp }}">
                        @include('campaigns.plan._canales_medio', ['sel' => []])
                        <textarea name="cuerpo" rows="5" placeholder="{{ __('Pega aquí el texto del contrato (puedes completarlo después).') }}" class="w-full rounded-lg border-gray-200 text-sm"></textarea>
                        <button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Crear tipo') }}</button>
                    </form>
                </details>
            </section>
        </div>

        <div class="space-y-6">
            {{-- ================= Numeración y contratante ================= --}}
            <form method="POST" action="{{ route('campaigns.contratos.config', $c) }}" enctype="multipart/form-data" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                @csrf
                <h2 class="text-base font-bold text-gray-900">{{ __('Numeración') }}</h2>
                <div class="grid grid-cols-[minmax(0,1fr)_5rem_4.5rem] gap-2">
                    <label class="text-xs text-gray-600">{{ __('Prefijo') }}<input name="prefijo" value="{{ $cfg['prefijo'] }}" maxlength="40" placeholder="SALUD-2026-" class="{{ $inp }} mt-0.5"></label>
                    <label class="text-xs text-gray-600">{{ __('Desde') }}<input type="number" name="inicio" min="1" value="{{ $cfg['inicio'] }}" class="{{ $inp }} mt-0.5"></label>
                    <label class="text-xs text-gray-600">{{ __('Dígitos') }}<input type="number" name="digitos" min="1" max="6" value="{{ $cfg['digitos'] }}" class="{{ $inp }} mt-0.5"></label>
                </div>
                <p class="text-xs text-gray-500">{{ __('El próximo contrato será el No. :n. Los números no se repiten ni se reutilizan (un contrato anulado conserva el suyo).', ['n' => $siguiente]) }}</p>
                <h2 class="text-base font-bold text-gray-900 pt-2">{{ __('Contratante') }}</h2>
                <label class="block text-xs text-gray-600">{{ __('Entidad o empresa') }}<input name="contratante" value="{{ $cfg['contratante'] }}" maxlength="190" class="{{ $inp }} mt-0.5"></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-xs text-gray-600">{{ __('NIT') }}<input name="contratante_nit" value="{{ $cfg['contratante_nit'] }}" maxlength="40" class="{{ $inp }} mt-0.5"></label>
                    <label class="text-xs text-gray-600">{{ __('Documento del representante') }}<input name="contratante_documento" value="{{ $cfg['contratante_documento'] }}" maxlength="60" class="{{ $inp }} mt-0.5"></label>
                </div>
                <label class="block text-xs text-gray-600">{{ __('Representante legal') }}<input name="contratante_representante" value="{{ $cfg['contratante_representante'] }}" maxlength="190" class="{{ $inp }} mt-0.5"></label>
                <div class="text-xs text-gray-600">{{ __('Firma del contratante (imagen, sale en cada contrato)') }}
                    @if ($firmaContratante)<img src="{{ $firmaContratante }}" alt="" class="h-14 my-1"><label class="flex items-center gap-1"><input type="checkbox" name="quitar_firma" value="1" class="h-3.5 w-3.5 rounded border-gray-300"> {{ __('Quitar') }}</label>@endif
                    <input type="file" name="firma" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:h-8 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-xs file:font-semibold"></div>
                <div class="text-xs text-gray-600">{{ __('Hoja membreteada del contratante (fondo de cada página del contrato)') }}
                    @if ($membreteContratante)<img src="{{ $membreteContratante }}" alt="" class="h-28 my-1 rounded border border-gray-200"><label class="flex items-center gap-1"><input type="checkbox" name="quitar_membrete" value="1" class="h-3.5 w-3.5 rounded border-gray-300"> {{ __('Quitar') }}</label>@endif
                    <input type="file" name="membrete" accept="image/png,image/jpeg" class="mt-1 block w-full text-xs text-gray-600 file:mr-2 file:h-8 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-xs file:font-semibold">
                    <span class="block text-[11px] text-gray-400 mt-0.5">{{ __('Imagen de la hoja completa, tamaño carta (ej.: 2550 × 3300 px).') }}</span></div>
                <label class="block text-xs text-gray-600">{{ __('Enviar también una copia oculta a (correos separados por coma)') }}<input name="copia_a" value="{{ implode(', ', $cfg['copia_a']) }}" maxlength="1000" class="{{ $inp }} mt-0.5"></label>
                <button class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Guardar') }}</button>
                @unless (\App\Services\NotificadorService::correoConfigurado())
                    <p class="text-xs text-amber-800 bg-amber-50 rounded-lg px-3 py-2">{{ __('El correo de editus no está configurado (MAIL_MAILER en el .env): los contratos se firman igual, pero la copia no se envía.') }}</p>
                @endunless
            </form>

            {{-- ================= Variables ================= --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                <h2 class="text-base font-bold text-gray-900 mb-2">{{ __('Variables del contrato') }}</h2>
                <ul class="space-y-1 text-xs text-gray-600">
                    @foreach ($variables as $v => $d)<li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-800">{{ '{' . $v . '}' }}</code> {{ $d }}</li>@endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
<script>
// Al elegir el tipo de contrato, el medio queda con los canales que cubre ese contrato
(function () {
    const canales = @js($tipos->mapWithKeys(fn($t) => [$t->id => (object) $t->canalesPorDefecto()]));
    document.querySelectorAll('#medios select[name=tipo_id]').forEach(sel => sel.addEventListener('change', () => {
        const suyos = canales[sel.value]; if (!suyos || !Object.keys(suyos).length) return;
        const f = sel.closest('form');
        f.querySelectorAll('input[name^=canales]').forEach(c => { c.checked = c.value in suyos; });
        f.querySelectorAll('input[name^=pauta]').forEach(c => { c.checked = !!(suyos[c.value] && suyos[c.value].pauta); });
    }));
})();
// Al volver de guardar, se abre el tipo de contrato que se estaba editando
(function () { try { const d = location.hash && document.querySelector(location.hash); if (d && d.tagName === 'DETAILS') { d.open = true; d.scrollIntoView({ block: 'start' }); } } catch (e) {} })();
</script>
@endsection
