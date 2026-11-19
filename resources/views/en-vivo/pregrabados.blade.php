@extends('layouts.app')

@section('content')
{{--
  Pregrabados programados: se sube el video ya editado y sale EN VIVO solo a la fecha y hora
  elegidas. El archivo va del navegador directo al emisor del VPS, por partes.
--}}
@php
    $conLogo = empty($esCliente) || !empty($limites['plantillas_logo']);
    $minimo = now()->addMinutes(15)->format('Y-m-d\TH:i');
    $sugerido = now()->addHours(2)->minute(0)->format('Y-m-d\TH:i');
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">{{ __('Producción') }}</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">{{ __('Pregrabados programados') }}</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">{!! __('Sube un video ya editado y elige la fecha y la hora: sale <strong>en vivo</strong> solo en tus páginas de Facebook y canales de YouTube, aunque no estés conectado.') !!}</p>
        </div>
        @include('en-vivo._pestanas')
    </div>

    @unless ($configurado)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{!! __('El servidor de pregrabados no está configurado: instala el emisor en el VPS (<code>infra/en-vivo/instalar-emisor.sh</code>) y agrega <code>EMISOR_URL</code> y <code>EMISOR_SECRETO</code> al .env de editus.') !!}</div>
    @endunless
    @if (!empty($esCliente))
        @if ($limites)
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-900 px-4 py-3 mb-5 text-sm">{!! __('Plan <strong>:plan</strong>: videos de hasta :horas horas', ['plan' => e($limites['nombre']), 'horas' => e($limites['pregrabado_horas'])]) !!}{{ $limites['pregrabados_cola'] ? __(', :n programados a la vez', ['n' => $limites['pregrabados_cola']]) : __(', sin límite de programados') }}{{ empty($limites['plantillas_logo']) ? __(', sin logo ni marco') : '' }}. <a href="{{ route('suscripcion.index') }}" class="underline">{{ __('Mi suscripción') }}</a></div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{{ __('Necesitas un plan activo para programar pregrabados.') }} <a href="{{ route('suscripcion.index') }}" class="font-semibold underline">{{ __('Ver planes') }}</a></div>
        @endif
    @endif
    <div id="pg-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm"></div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start">
        {{-- ===================================================== FORMULARIO --}}
        <form id="pg-form" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-5 min-w-0" novalidate>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Video *') }}</label>
                <label id="pg-zona" class="flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-7 text-center cursor-pointer hover:border-indigo-300 hover:bg-indigo-50/40 transition">
                    <input type="file" id="pg-archivo" accept="video/mp4,video/quicktime,video/x-matroska,video/webm,.mp4,.mov,.mkv,.webm" class="sr-only">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="text-indigo-500"><path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                    <span class="text-sm font-semibold text-gray-800" id="pg-zona-texto">{{ __('Elige o arrastra el video editado') }}</span>
                    <span class="text-xs text-gray-500">{{ __('MP4, MOV, MKV o WEBM · hasta :gb GB', ['gb' => round($maxBytes / 1024 ** 3)]) }}{{ $limites ? __(' · hasta :h h', ['h' => $limites['pregrabado_horas']]) : '' }}</span>
                </label>
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Título *') }}</label>
                <input name="titulo" required maxlength="200" placeholder="{{ __('Ej: Especial: así avanza el plan de seguridad en Neiva') }}" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Descripción') }}</label>
                <textarea name="descripcion" rows="2" maxlength="5000" placeholder="{{ __('Lo que verán en Facebook y YouTube debajo del video') }}" class="w-full rounded-lg border-gray-200 text-sm shadow-sm"></textarea>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Fecha y hora de salida *') }}</label>
                    <input type="datetime-local" name="programada_en" required min="{{ $minimo }}" value="{{ $sugerido }}" step="60" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('Hora de Colombia (:zona). Deja al menos 30 min para un video de 1 hora: hay que subirlo y prepararlo.', ['zona' => $zona]) }}</p>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Calidad') }}</label>
                    <select name="calidad" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
                        <option value="auto">{{ __('Automática (1080p si el video lo es)') }}</option>
                        <option value="720">{{ __('720p (más liviano)') }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-2">{{ __('Páginas de Facebook') }}</label>
                <div class="grid sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto pr-1">
                    @forelse ($paginas as $p)
                        <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                            <input type="checkbox" name="meta_page_ids[]" value="{{ $p['id'] }}" data-page-id="{{ $p['page_id'] }}" data-nombre-pagina="{{ $p['nombre'] }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                            <img src="{{ $p['foto'] }}" alt="" class="h-8 w-8 rounded-full bg-gray-100 shrink-0">
                            <span class="text-sm font-medium text-gray-800 truncate">{{ $p['nombre'] }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-gray-500 sm:col-span-2">{{ __('No tienes páginas conectadas. Conéctalas en') }} <a href="{{ route('meta.pages.index') }}" class="text-indigo-700 underline">{{ __('Mis páginas') }}</a>.</p>
                    @endforelse
                </div>
            </div>
            @if (count($canales))
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-2">{{ __('Canales de YouTube') }}</label>
                    <div class="grid sm:grid-cols-2 gap-2">
                        @foreach ($canales as $c)
                            <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-rose-300 has-[:checked]:bg-rose-50/40">
                                <input type="checkbox" name="youtube_canal_ids[]" value="{{ $c['id'] }}" class="h-4 w-4 rounded border-gray-300 text-rose-600">
                                <span class="h-8 w-8 rounded-full bg-rose-600 text-white grid place-items-center text-xs font-bold shrink-0">▶</span>
                                <span class="text-sm font-medium text-gray-800 truncate">{{ $c['nombre'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (!empty($campanasVivo))
                {{-- Pregrabado de una campaña con plan de medios: al salir al aire queda como pieza y suma al informe --}}
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 space-y-2">
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-emerald-900">{{ __('Campaña (plan de medios)') }}</label>
                    <select id="pg-campana-sel" class="w-full sm:max-w-md h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                    <option value="">{{ __('Ninguna: sin campaña') }}</option>
                    @foreach ($campanasVivo as $cv)<option value="{{ $cv['id'] }}">{{ $cv['nombre'] }}</option>@endforeach
                </select>
                <div id="pg-campana-detalle" class="hidden grid sm:grid-cols-2 gap-2">
                    <div id="pg-campana-sub-caja"><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Subcampaña') }} <span class="text-red-500">*</span></label>
                        <select id="pg-campana-sub" class="w-full h-10 rounded-lg border-gray-200 text-sm"></select></div>
                    <div><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Tipo de en vivo') }} <span class="text-red-500">*</span></label>
                        <select id="pg-campana-tipo" class="w-full h-10 rounded-lg border-gray-200 text-sm"></select></div>
                </div>
                    <p id="pg-campana-cupos" class="hidden text-xs"></p>
                    <p class="text-[11px] text-emerald-900/80">{{ __('Al salir al aire, el video de cada página de Facebook queda como pieza de esta subcampaña y suma al informe de los medios y a los cupos.') }}</p>
                </div>
            @endif

            <div class="rounded-xl border border-gray-200 p-4 space-y-3">
                <div class="text-sm font-bold text-gray-800">{{ __('Plantilla') }}</div>
                @if ($conLogo)
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Logo') }}</label>
                            <select name="logo_id" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                                <option value="">{{ __('Sin logo') }}</option>
                                @foreach ($logos as $l)<option value="{{ $l['id'] }}">{{ $l['nombre'] }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Marco (PNG 1920×1080)') }}</label>
                            <select name="marco_id" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                                <option value="">{{ __('Sin marco') }}</option>
                                @foreach ($marcos as $m)<option value="{{ $m['id'] }}">{{ $m['nombre'] }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                @else
                    <p class="text-xs text-gray-500">{{ __('Logo y marco: disponibles en el plan Full.') }}</p>
                @endif
<div class="">
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Miniatura del anuncio (opcional)') }}</label>
                            <input type="file" name="miniatura_archivo" accept="image/jpeg,image/png" class="block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-200">
                            <p class="text-[11px] text-gray-400 mt-1">{{ __('JPG o PNG, ideal 1280×720 (si pesa más de 2 MB, editus la reduce). Va en la publicación de anuncio de Facebook y en YouTube antes de empezar (en YouTube, si el canal está verificado).') }}</p>
                        </div>
                <label class="flex items-start gap-2.5 text-sm text-gray-700"><input type="checkbox" name="anuncio_facebook" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span><strong>{{ __('Publicar anuncio en Facebook') }}</strong>. <span class="text-gray-500">{{ __('Meta ya no permite programar en vivos desde aplicaciones: publicamos un post con la fecha y la hora (se borra si cancelas). El en vivo se crea solo a la hora.') }}</span></span></label>
                <label class="flex items-start gap-2.5 text-sm text-gray-700"><input type="checkbox" name="etiqueta" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span><strong>{{ __('Etiqueta «PREGRABADO»') }}</strong> {{ __('en una esquina.') }} <span class="text-gray-500">{{ __('Facebook exige que el contenido pregrabado se distinga del que es en vivo.') }}</span></span></label>
                <label class="flex items-start gap-2.5 text-sm text-gray-700"><input type="checkbox" name="cuenta" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span><strong>{{ __('Cuenta regresiva') }}</strong> {{ __('de 1 minuto antes de la hora, con el título y el logo.') }}</span></label>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3 pt-1">
                <span class="text-xs text-gray-400">{{ __('No cierres esta pestaña mientras sube el video.') }}</span>
                <button type="submit" id="pg-boton" class="h-11 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm disabled:opacity-50" @disabled(!$configurado || (!empty($esCliente) && !$limites))>{{ __('Subir y programar') }}</button>
            </div>

            {{-- Avance de la subida --}}
            <div id="pg-subida" class="hidden rounded-xl border border-indigo-100 bg-indigo-50/50 p-4">
                <div class="flex items-center justify-between gap-3 text-sm"><span class="font-semibold text-gray-800" id="pg-subida-titulo">{{ __('Subiendo…') }}</span><span class="text-gray-500 whitespace-nowrap" id="pg-subida-dato"></span></div>
                <div class="mt-2 h-2.5 w-full rounded-full bg-white overflow-hidden"><div id="pg-subida-barra" class="h-2.5 rounded-full bg-[#00024f] transition-all" style="width:0%"></div></div>
            </div>
        </form>

        {{-- ================================================== CÓMO FUNCIONA --}}
        <aside class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 text-sm">
            <h3 class="font-bold text-gray-900 mb-3">{{ __('Cómo funciona') }}</h3>
            <ol class="space-y-3">
                @foreach ([
                    [__('Subes el video'), __('Va directo al servidor de transmisión, por partes. Si se corta, vuelves a elegir el mismo archivo y continúa.')],
                    [__('Se prepara una sola vez'), __('Formato de transmisión, logo, marco y etiqueta. Un video de 1 hora tarda unos 30 a 60 minutos.')],
                    [__('Se anuncia'), __('Apenas el video está listo se publica un anuncio en tu página de Facebook (con la miniatura, la fecha y la hora) y la transmisión próxima en YouTube.')],
                    [__('Sale en vivo solo'), __('Un minuto antes empieza la cuenta regresiva y, a la hora, el video. Al terminar se cierra y queda publicado.')],
                ] as $i => [$t, $d])
                    <li class="flex gap-3"><span class="shrink-0 h-6 w-6 rounded-full bg-[#00024f] text-white grid place-items-center text-xs font-bold">{{ $i + 1 }}</span><span><b class="text-gray-800">{{ $t }}.</b> <span class="text-gray-600">{{ $d }}</span></span></li>
                @endforeach
            </ol>
            <div class="mt-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 p-3 text-xs">{{ __('Facebook pide que la página tenga al menos 100 seguidores y 60 días para salir en vivo. Instagram no permite en vivos desde aplicaciones externas.') }}</div>
            <p class="mt-3 text-xs text-gray-400">{{ __('El archivo se borra del servidor :n días después de emitido; el video queda publicado en Facebook y YouTube.', ['n' => config('services.emisor.dias_borrado', 7)]) }}</p>
        </aside>
    </div>

    {{-- ===================================================== PROGRAMADOS --}}
    <div class="mt-8">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-lg font-bold text-gray-900">{{ __('Programados e historial') }}</h2>
            <span class="text-xs text-gray-400" id="pg-actualizado"></span>
        </div>
        <div id="pg-lista" class="grid md:grid-cols-2 xl:grid-cols-3 gap-4"></div>
    </div>
</div>

@if (!empty($campanasVivo))
    {{-- Elegir la campaña de un pregrabado que ya está programado o ya salió --}}
    <div id="pg-modal-campana" class="hidden fixed inset-0 z-50 bg-black/40 grid place-items-center p-4">
        <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl p-5 space-y-3">
            <div class="text-base font-bold text-gray-900">{{ __('Sumar a una campaña') }}</div>
            <p id="pg-modal-titulo" class="text-sm text-gray-600"></p>
            <select id="pg-modal-sel" class="w-full sm:max-w-md h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                    <option value="">{{ __('Ninguna: sin campaña') }}</option>
                    @foreach ($campanasVivo as $cv)<option value="{{ $cv['id'] }}">{{ $cv['nombre'] }}</option>@endforeach
                </select>
                <div id="pg-modal-detalle" class="hidden grid sm:grid-cols-2 gap-2">
                    <div id="pg-modal-sub-caja"><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Subcampaña') }} <span class="text-red-500">*</span></label>
                        <select id="pg-modal-sub" class="w-full h-10 rounded-lg border-gray-200 text-sm"></select></div>
                    <div><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Tipo de en vivo') }} <span class="text-red-500">*</span></label>
                        <select id="pg-modal-tipo" class="w-full h-10 rounded-lg border-gray-200 text-sm"></select></div>
                </div>
            <p class="text-[11px] text-gray-500">{{ __('Si ya salió al aire, el video de cada página de Facebook queda como pieza de la subcampaña y aparece en el informe de los medios.') }}</p>
            <p id="pg-modal-error" class="hidden rounded-lg bg-rose-50 text-rose-700 text-xs px-3 py-2"></p>
            <div class="flex justify-end gap-2">
                <button type="button" id="pg-modal-cerrar" class="h-9 rounded-lg border border-gray-200 px-4 text-sm text-gray-700">{{ __('Cancelar') }}</button>
                <button type="button" id="pg-modal-guardar" class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Sumar a la campaña') }}</button>
            </div>
        </div>
    </div>
@endif

@include('en-vivo._miniatura-js')
<script>
(function () {
  const RUTAS = {
    crear: @json(route('pregrabados.store')), estado: @json(route('pregrabados.estado')),
    base: @json(url('/en-vivo/pregrabados')),
  };
  const MAX = @json($maxBytes);
  const CAMPANAS = @json($campanasVivo ?? []);
  const TX = @js([
    'errorHttp' => __('Error :status'),
    'eligeArrastra' => __('Elige o arrastra el video editado'),
    'permisoVencio' => __('El permiso de subida venció.'),
    'rechazo' => __('El servidor rechazó la subida.'),
    'conexionPerdida' => __('Se perdió la conexión con el servidor de transmisión. Vuelve a elegir el mismo archivo para continuar.'),
    'avance' => __(':pct % · :hecho de :total'),
    'velocidad' => __(' · :vel/s · faltan :resta'),
    'reintentando' => __('Sin respuesta del servidor de transmisión: reintentando (:n de :max)…'),
    'subiendoVideo' => __('El video se está subiendo'),
    'subiendoArchivo' => __('Subiendo «:archivo»…'),
    'preparando' => __('Subido. Preparando el video en el servidor…'),
    'listo' => __('✓ Listo: el video se está preparando. Puedes cerrar esta página.'),
    'eligeVideo' => __('Elige el video que quieres programar.'),
    'pesaMucho' => __('El video pesa :tam y el máximo es :max.'),
    'escribeTitulo' => __('Escribe el título.'),
    'eligeDestino' => __('Elige al menos una página de Facebook o un canal de YouTube.'),
    'ahora' => __('ahora'),
    'enDia' => __('en :d día :h h'),
    'enDias' => __('en :d días :h h'),
    'enTiempo' => __('en :tiempo'),
    'subiendo' => __('Subiendo…'),
    'pausada' => __('Subida pausada: vuelve a elegir «:archivo» para continuar'),
    'preparandoPct' => __('Preparando el video · :pct %'),
    'sale' => __('Sale :cuando'),
    'anunciado' => __(' · anunciado'),
    'alAirePct' => __('Al aire · :pct %'),
    'emitido' => __('Emitido'),
    'emitidoEl' => __('Emitido el :fecha'),
    'anuncioPublicado' => __('anuncio publicado'),
    'continuar' => __('Continuar subida'),
    'salir2' => __('Salir en 2 minutos'),
    'detener' => __('Detener transmisión'),
    'cancelar' => __('Cancelar'),
    'quitar' => __('Quitar'),
    'sumarCampana' => __('Sumar a una campaña'),
    'eligeSub' => __('Elige la subcampaña del en vivo.'),
    'eligeTipo' => __('Elige el tipo de en vivo.'),
    'eligeSubVacio' => __('— Elige la subcampaña —'),
    'eligeTipoVacio' => __('— Elige el tipo —'),
    'eligeCampana' => __('Elige la campaña.'),
    'paginaCampana' => __('Para que sume al informe, elige al menos una página de Facebook de la campaña.'),
    'cupoLleno' => __('Ya completaron su cupo:'),
    'cupoUsado' => __('Cupo usado'),
    'sumadas' => __('Listo: quedó en la campaña con :n publicación(es) en el informe.'),
    'sumadaSinSalir' => __('Listo: cuando salga al aire sumará al informe de la campaña.'),
    'vacio' => __('Todavía no hay pregrabados programados.'),
    'actualizado' => __('Actualizado :hora'),
    'confDetener' => __('¿Detener la transmisión ahora? Se cierra en Facebook y YouTube.'),
    'confCancelar' => __('¿Cancelar este pregrabado? Se quita el anuncio y se borra el video del servidor.'),
    'confAhora' => __('¿Sacar este video al aire en 2 minutos?'),
    'confQuitar' => __('¿Quitar del historial?'),
  ]);
  const ESTADOS_TXT = @js(['subiendo' => __('Subiendo'), 'procesando' => __('Preparando el video'), 'listo' => __('Programado'), 'al_aire' => __('En vivo ahora'), 'terminado' => __('Emitido'), 'error' => __('Con error'), 'cancelado' => __('Cancelado')]);
  const DEST_TXT = @js(['pendiente' => __('pendiente'), 'anunciado' => __('anunciado'), 'emitiendo' => __('emitiendo'), 'terminado' => __('terminado'), 'error' => __('error'), 'cancelado' => __('cancelado')]);
  const LOCALE = @js(app()->getLocale() === 'en' ? 'en-US' : 'es-CO');
  const tx = (k, v) => String(TX[k]).replace(/:(\w+)/g, (m, n) => (v && n in v) ? v[n] : m);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const g = (id) => document.getElementById(id);
  const esc = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const tam = (b) => b >= 1024 ** 3 ? (b / 1024 ** 3).toFixed(2) + ' GB' : (b / 1024 ** 2).toFixed(0) + ' MB';
  const dur = (s) => { s = Math.round(s || 0); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); return h ? `${h} h ${m} min` : `${m} min ${s % 60} s`; };
  const post = async (url, body) => { const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body || {}) }); const d = await r.json().catch(() => ({})); if (!r.ok || d.success === false) throw new Error(d.error || d.message || tx('errorHttp', { status: r.status })); return d; };
  const error = (t) => { const e = g('pg-error'); e.textContent = t || ''; e.classList.toggle('hidden', !t); if (t) e.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
  let lista = @json($emisiones);
  let subiendo = null; // id de la emisión que se está subiendo desde esta pestaña

  // ------------------------------------------------------------ archivo
  const entrada = g('pg-archivo'), zona = g('pg-zona');
  const mostrarArchivo = () => { const f = entrada.files[0]; g('pg-zona-texto').textContent = f ? `${f.name} · ${tam(f.size)}` : TX.eligeArrastra;
    if (f && !g('pg-form').titulo.value) g('pg-form').titulo.value = f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').slice(0, 200); };
  entrada.addEventListener('change', mostrarArchivo);
  ['dragover', 'dragenter'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('border-indigo-400'); }));
  ['dragleave', 'drop'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.remove('border-indigo-400'); }));
  zona.addEventListener('drop', e => { if (e.dataTransfer.files.length) { entrada.files = e.dataTransfer.files; mostrarArchivo(); } });

  // --------------------------------------------- subida por partes (XHR)
  const PARTE = 16 * 1024 * 1024;
  const enviarParte = (url, archivo, ini, fin, alAvance) => new Promise((resolve) => {
    const x = new XMLHttpRequest();
    x.open('PUT', url);
    x.setRequestHeader('Content-Range', `bytes ${ini}-${fin}/${archivo.size}`);
    x.upload.onprogress = (e) => alAvance(ini + e.loaded);
    x.onload = () => { let d = {}; try { d = JSON.parse(x.responseText); } catch {} resolve({ status: x.status, d }); };
    x.onerror = () => resolve({ status: 0, d: {} });
    x.send(archivo.slice(ini, fin + 1));
  });
  async function subir(url, archivo, alAvance, alReintento = () => {}) {
    let pos = 0, r = null, d = {};
    try { r = await fetch(url); d = await r.json(); } catch { /* sin respuesta: se empieza por la primera parte */ }
    if (r && r.status === 403) throw new Error(d.error || TX.permisoVencio);
    if (r && r.ok) pos = d.recibido || 0;
    while (pos < archivo.size) {
      const fin = Math.min(archivo.size, pos + PARTE) - 1;
      let intento = 0;
      for (;;) {
        const { status, d } = await enviarParte(url, archivo, pos, fin, alAvance);
        if (status === 200) { pos = d.recibido; break; }
        if (status === 409 && typeof d.recibido === 'number') { pos = d.recibido; break; }
        if ([403, 413, 507].includes(status)) throw new Error(d.error || TX.rechazo);
        if (++intento > 8) throw new Error(d.error || TX.conexionPerdida);
        alReintento(intento, 8);
        await new Promise(r => setTimeout(r, Math.min(20000, 1500 * intento)));
      }
      alAvance(pos);
    }
  }
  // Avance de la subida en curso: se ve en el panel del formulario y en la tarjeta del video
  let avance = null;
  function panelSubida(titulo, hecho, total, inicio, nota = '') {
    g('pg-subida').classList.remove('hidden');
    g('pg-subida-titulo').textContent = titulo;
    const pct = total ? Math.min(100, Math.round(100 * hecho / total)) : 0;
    g('pg-subida-barra').style.width = pct + '%';
    const seg = (Date.now() - inicio) / 1000, vel = seg > 2 ? hecho / seg : 0;
    const dato = nota || tx('avance', { pct, hecho: tam(hecho), total: tam(total) }) + (vel ? tx('velocidad', { vel: tam(vel), resta: dur((total - hecho) / vel) }) : '');
    g('pg-subida-dato').textContent = dato;
    g('pg-subida-dato').classList.toggle('text-rose-600', !!nota);
    avance = { pct, texto: dato, nota: !!nota };
    const tarjeta = subiendo && document.querySelector(`#pg-lista [data-id="${subiendo}"]`);
    if (tarjeta) {
      const b = tarjeta.querySelector('[data-barra]'), t = tarjeta.querySelector('[data-texto]');
      if (b) b.style.width = pct + '%';
      if (t) { t.textContent = dato; t.classList.toggle('text-rose-600', !!nota); }
    }
  }
  async function subirYPreparar(emision, url, archivo) {
    subiendo = emision.id; avance = { pct: 0, texto: TX.subiendo, nota: false };
    window.onbeforeunload = () => TX.subiendoVideo;
    const inicio = Date.now();
    let hecho = 0;
    pintar();
    g('pg-subida').scrollIntoView({ behavior: 'smooth', block: 'center' });
    try {
      await subir(url, archivo, (h) => { hecho = h; panelSubida(tx('subiendoArchivo', { archivo: archivo.name }), h, archivo.size, inicio); },
        (n, max) => panelSubida(tx('subiendoArchivo', { archivo: archivo.name }), hecho, archivo.size, inicio, tx('reintentando', { n, max })));
      panelSubida(TX.preparando, archivo.size, archivo.size, inicio);
      await post(`${RUTAS.base}/${emision.id}/subido`);
      g('pg-subida-titulo').textContent = TX.listo;
      g('pg-form').reset(); mostrarArchivo();
    } finally { subiendo = null; avance = null; window.onbeforeunload = null; refrescar(); }
  }

  g('pg-form').addEventListener('submit', async (ev) => {
    ev.preventDefault(); error('');
    const f = new FormData(ev.target), archivo = entrada.files[0];
    if (!archivo) return error(TX.eligeVideo);
    if (archivo.size > MAX) return error(tx('pesaMucho', { tam: tam(archivo.size), max: tam(MAX) }));
    const datos = { titulo: f.get('titulo'), descripcion: f.get('descripcion'), programada_en: f.get('programada_en'), calidad: f.get('calidad'),
      meta_page_ids: f.getAll('meta_page_ids[]').map(Number), youtube_canal_ids: f.getAll('youtube_canal_ids[]').map(Number),
      logo_id: f.get('logo_id') ? Number(f.get('logo_id')) : null, marco_id: f.get('marco_id') ? Number(f.get('marco_id')) : null,
      etiqueta: f.get('etiqueta') === '1', cuenta: f.get('cuenta') === '1', anuncio_facebook: f.get('anuncio_facebook') === '1', archivo_nombre: archivo.name, archivo_bytes: archivo.size };
    if (!datos.titulo) return error(TX.escribeTitulo);
    if (!datos.meta_page_ids.length && !datos.youtube_canal_ids.length) return error(TX.eligeDestino);
    const elegida = leerCampana('pg-campana');
    if (elegida) {
      if (elegida.error) return error(elegida.error);
      if (!datos.meta_page_ids.length) return error(TX.paginaCampana);
      Object.assign(datos, elegida.datos);
    }
    const img = f.get('miniatura_archivo');
    if (img && img.size) {
      // Si pesa más de 2 MB o es muy grande, se reduce aquí mismo (_miniatura-js)
      try { datos.miniatura = await window.prepararMiniatura(img); } catch (e) { return error(e.message); }
    }
    const b = g('pg-boton'); b.disabled = true;
    try {
      const r = await post(RUTAS.crear, datos);
      lista.unshift(r.emision); pintar();
      await subirYPreparar(r.emision, r.subida, archivo);
    } catch (e) { error(e.message); g('pg-subida').classList.add('hidden'); }
    finally { b.disabled = false; }
  });

  // ---------------------------------------------------------- lista
  const COLORES = { subiendo: 'bg-sky-50 text-sky-800', procesando: 'bg-amber-50 text-amber-800', listo: 'bg-indigo-50 text-indigo-800', al_aire: 'bg-rose-600 text-white', terminado: 'bg-emerald-50 text-emerald-800', error: 'bg-rose-50 text-rose-700', cancelado: 'bg-gray-100 text-gray-600' };
  const DEST = { pendiente: 'text-gray-500', anunciado: 'text-indigo-700', emitiendo: 'text-rose-600', terminado: 'text-emerald-700', error: 'text-rose-600', cancelado: 'text-gray-400' };
  const faltaPara = (iso) => { const s = (new Date(iso) - Date.now()) / 1000; if (s <= 0) return TX.ahora; const d = Math.floor(s / 86400); return d ? tx(d > 1 ? 'enDias' : 'enDia', { d, h: Math.floor(s % 86400 / 3600) }) : tx('enTiempo', { tiempo: dur(s) }); };
  function tarjeta(e) {
    const barra = ['subiendo', 'procesando', 'al_aire'].includes(e.estado);
    const local = e.estado === 'subiendo' && subiendo === e.id && avance;
    const texto = e.estado === 'subiendo' ? (local ? esc(avance.texto) : tx('pausada', { archivo: esc(e.archivo) }))
      : e.estado === 'procesando' ? tx('preparandoPct', { pct: e.progreso })
      : e.estado === 'listo' ? tx('sale', { cuando: faltaPara(e.programada_en) }) + (e.anunciada ? TX.anunciado : '')
      : e.estado === 'al_aire' ? tx('alAirePct', { pct: e.progreso }) : e.estado === 'terminado' ? (e.inicio_real ? tx('emitidoEl', { fecha: e.inicio_real }) : TX.emitido) : '';
    return `<div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 flex flex-col min-w-0" data-id="${e.id}">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0"><div class="font-semibold text-gray-900 leading-snug">${esc(e.titulo)}</div><div class="text-xs text-gray-500 mt-0.5">${esc(e.programada_texto)}${e.duracion ? ' · ' + dur(e.duracion) : ''}</div></div>
        <span class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold ${COLORES[e.estado] || ''}">${e.estado === 'al_aire' ? '● ' : ''}${esc(ESTADOS_TXT[e.estado] || e.estado_texto)}</span>
      </div>
      ${texto ? `<div data-texto class="text-xs mt-2 ${local && avance.nota ? 'text-rose-600' : 'text-gray-600'}">${texto}</div>` : ''}
      ${barra ? `<div class="mt-2 h-1.5 rounded-full bg-gray-100 overflow-hidden"><div data-barra class="h-1.5 rounded-full transition-all ${e.estado === 'al_aire' ? 'bg-rose-500' : 'bg-[#00024f]'}" style="width:${local ? avance.pct : e.progreso}%"></div></div>` : ''}
      ${e.error ? `<div class="mt-2 rounded-lg bg-rose-50 text-rose-700 text-xs px-2.5 py-1.5">${esc(e.error)}</div>` : ''}
      ${e.campana ? `<div class="mt-2 rounded-lg bg-emerald-50 text-emerald-800 text-[11px] px-2.5 py-1">📊 ${esc(e.campana)}</div>` : ''}
      <div class="mt-3 space-y-1">${e.destinos.map(d => `<div class="flex items-center justify-between gap-2 text-xs"><span class="truncate">${d.red === 'youtube' ? '▶' : 'f'} ${esc(d.nombre)}</span><span class="shrink-0 ${DEST[d.estado] || ''}">${d.permalink ? `<a href="${esc(d.permalink)}" target="_blank" rel="noopener" class="underline">${esc(DEST_TXT[d.estado] || d.estado)}</a>` : d.anuncio && d.estado === 'pendiente' ? `<span class="text-indigo-700">${d.anuncio_permalink ? `<a href="${esc(d.anuncio_permalink)}" target="_blank" rel="noopener" class="underline">${esc(TX.anuncioPublicado)}</a>` : esc(TX.anuncioPublicado)}</span>` : esc(DEST_TXT[d.estado] || d.estado)}</span></div>${d.error ? `<div class="text-[11px] text-rose-600">${esc(d.error)}</div>` : ''}`).join('')}</div>
      <div class="mt-auto pt-3 flex flex-wrap gap-2">
        ${e.estado === 'subiendo' && subiendo !== e.id ? `<label class="h-8 inline-flex items-center rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold cursor-pointer">${esc(TX.continuar)}<input type="file" class="sr-only" data-reanudar="${e.id}" accept="video/*,.mkv"></label>` : ''}
        ${e.puede_campana && CAMPANAS.length ? `<button type="button" data-campana="${e.id}" class="h-8 rounded-lg border border-emerald-200 px-3 text-xs text-emerald-800 hover:bg-emerald-50">${esc(TX.sumarCampana)}</button>` : ''}
        ${e.estado === 'listo' ? `<button type="button" data-ahora="${e.id}" class="h-8 rounded-lg border border-gray-200 px-3 text-xs text-gray-700 hover:bg-gray-50">${esc(TX.salir2)}</button>` : ''}
        ${e.puede_cancelar ? `<button type="button" data-cancelar="${e.id}" class="h-8 rounded-lg border border-rose-200 px-3 text-xs text-rose-700 hover:bg-rose-50">${esc(e.estado === 'al_aire' ? TX.detener : TX.cancelar)}</button>` : `<button type="button" data-quitar="${e.id}" class="h-8 rounded-lg border border-gray-200 px-3 text-xs text-gray-500 hover:bg-gray-50">${esc(TX.quitar)}</button>`}
      </div></div>`;
  }
  function pintar() {
    g('pg-lista').innerHTML = lista.length ? lista.map(tarjeta).join('') : '<div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-3">' + esc(TX.vacio) + '</div>';
    g('pg-actualizado').textContent = tx('actualizado', { hora: new Date().toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', second: '2-digit' }) });
  }
  async function refrescar() {
    try { const r = await fetch(RUTAS.estado, { headers: { Accept: 'application/json' } }); if (r.ok) { lista = (await r.json()).emisiones; pintar(); } } catch {}
  }
  g('pg-lista').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    try {
      if (b.dataset.cancelar) { if (!confirm(b.textContent.includes(TX.detener) ? TX.confDetener : TX.confCancelar)) return; await post(`${RUTAS.base}/${b.dataset.cancelar}/cancelar`); }
      if (b.dataset.campana) { abrirModalCampana(lista.find(x => String(x.id) === b.dataset.campana)); return; }
      if (b.dataset.ahora) { if (!confirm(TX.confAhora)) return; await post(`${RUTAS.base}/${b.dataset.ahora}/ahora`); }
      if (b.dataset.quitar) { if (!confirm(TX.confQuitar)) return; await fetch(`${RUTAS.base}/${b.dataset.quitar}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } }); }
      refrescar();
    } catch (e) { error(e.message); }
  });
  g('pg-lista').addEventListener('change', async (ev) => {
    const i = ev.target.closest('input[data-reanudar]'); if (!i || !i.files[0]) return;
    error('');
    try { const r = await post(`${RUTAS.base}/${i.dataset.reanudar}/reanudar`, { archivo_bytes: i.files[0].size }); await subirYPreparar(r.emision, r.subida, i.files[0]); }
    catch (e) { error(e.message); }
  });
  // ---------------------------------------------------------- campaña con plan de medios (suma al informe)
  function llenarSel(el, items, vacio, previo) {
    el.innerHTML = '';
    if (vacio) { const o = document.createElement('option'); o.value = ''; o.textContent = vacio; el.appendChild(o); }
    items.forEach(([v, t]) => { const o = document.createElement('option'); o.value = v; o.textContent = t; if (previo != null && String(previo) === String(v)) o.selected = true; el.appendChild(o); });
  }
  const campanaDe = (pre) => { const s = g(pre + '-sel'); return s && s.value ? CAMPANAS.find(c => String(c.id) === s.value) || null : null; };
  const tiposDe = (pre, c) => { const s = c.subs.find(x => String(x.id) === g(pre + '-sub').value); return c.subs.length ? (s ? s.tipos : []) : c.tipos; };
  function llenarTipos(pre) {
    const c = campanaDe(pre); if (!c) return;
    const t = tiposDe(pre, c);
    llenarSel(g(pre + '-tipo'), t.map(x => [x, x]), t.length === 1 ? null : TX.eligeTipoVacio, g(pre + '-tipo').value);
  }
  function aplicarCampana(pre, marcarPaginas) {
    const c = campanaDe(pre);
    g(pre + '-detalle').classList.toggle('hidden', !c);
    if (!c) return;
    // Las páginas de la campaña quedan marcadas (se pueden desmarcar)
    if (marcarPaginas) cajasPagina().forEach(cb => { cb.checked = c.paginas.includes(cb.dataset.pageId); });
    g(pre + '-sub-caja').classList.toggle('hidden', !c.subs.length);
    llenarSel(g(pre + '-sub'), c.subs.map(s => [s.id, s.nombre]), TX.eligeSubVacio, null);
    llenarTipos(pre);
  }
  /** null sin campaña; {error} si falta algo; {datos} para enviar. */
  function leerCampana(pre) {
    const c = campanaDe(pre);
    if (!c) return null;
    const sub = g(pre + '-sub').value, tipo = g(pre + '-tipo').value;
    if (c.subs.length && !sub) return { error: TX.eligeSub };
    if (!tipo) return { error: TX.eligeTipo };
    return { datos: { campaign_id: c.id, subcampana_id: sub ? Number(sub) : null, tipo_pieza: tipo } };
  }
  const cajasPagina = () => Array.from(document.querySelectorAll('input[name="meta_page_ids[]"]'));
  function pintarCupos() {
    const c = campanaDe('pg-campana'), p = g('pg-campana-cupos');
    if (!p) return;
    const tipo = c ? g('pg-campana-tipo').value : '';
    if (!c || !tipo) { p.classList.add('hidden'); return; }
    const k = tipo.toLowerCase(), llenas = [], quedan = [];
    cajasPagina().filter(cb => cb.checked).forEach(cb => { const x = (c.cupos[cb.dataset.pageId] || {})[k]; if (x) (x[0] >= x[1] ? llenas : quedan).push(cb.dataset.nombrePagina + ' ' + x[0] + '/' + x[1]); });
    p.innerHTML = '';
    if (llenas.length) { const s = document.createElement('span'); s.className = 'block text-rose-700 font-semibold'; s.textContent = TX.cupoLleno + ' ' + llenas.join(', '); p.appendChild(s); }
    if (quedan.length) { const s = document.createElement('span'); s.className = 'block text-emerald-800'; s.textContent = TX.cupoUsado + ' (' + tipo + '): ' + quedan.join(', '); p.appendChild(s); }
    p.classList.toggle('hidden', !llenas.length && !quedan.length);
  }
  if (g('pg-campana-sel')) {
    g('pg-campana-sel').addEventListener('change', () => { aplicarCampana('pg-campana', true); pintarCupos(); });
    g('pg-campana-sub').addEventListener('change', () => { llenarTipos('pg-campana'); pintarCupos(); });
    g('pg-campana-tipo').addEventListener('change', pintarCupos);
    cajasPagina().forEach(cb => cb.addEventListener('change', pintarCupos));
  }
  let modalId = null;
  function abrirModalCampana(e) {
    if (!e || !g('pg-modal-campana')) return;
    modalId = e.id;
    g('pg-modal-titulo').textContent = e.titulo;
    g('pg-modal-sel').value = ''; aplicarCampana('pg-modal', false);
    g('pg-modal-error').classList.add('hidden');
    g('pg-modal-campana').classList.remove('hidden');
  }
  if (g('pg-modal-campana')) {
    const cerrar = () => { g('pg-modal-campana').classList.add('hidden'); modalId = null; };
    g('pg-modal-sel').addEventListener('change', () => aplicarCampana('pg-modal', false));
    g('pg-modal-sub').addEventListener('change', () => llenarTipos('pg-modal'));
    g('pg-modal-cerrar').addEventListener('click', cerrar);
    g('pg-modal-guardar').addEventListener('click', async () => {
      const el = leerCampana('pg-modal'), msg = g('pg-modal-error');
      const falla = (t) => { msg.textContent = t; msg.classList.remove('hidden'); };
      if (!el) return falla(TX.eligeCampana);
      if (el.error) return falla(el.error);
      const b = g('pg-modal-guardar'); b.disabled = true;
      try {
        const r = await post(`${RUTAS.base}/${modalId}/campana`, el.datos);
        cerrar();
        alert(r.emision.inicio_real ? tx('sumadas', { n: r.publicaciones }) : TX.sumadaSinSalir);
        refrescar();
      } catch (e) { falla(e.message); }
      finally { b.disabled = false; }
    });
  }

  pintar();
  setInterval(() => { if (!document.hidden) refrescar(); }, 6000);
})();
</script>
@endsection
