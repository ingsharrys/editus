@extends('layouts.app')

@section('content')
{{--
  Estudio en vivo (web): misma producción que la app del editor. La cámara de este navegador
  entra a la sala como "Cámara principal"; la escena (plantilla, rótulos, diseño) se compone en el
  servidor y sale a la vez a las páginas de Facebook y canales de YouTube elegidos.
--}}
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Producción</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Estudio en vivo</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">Transmite desde este navegador a tus páginas de Facebook y canales de YouTube a la vez, con plantilla, rótulos, cámaras invitadas y cortinillas.</p>
        </div>
        @include('en-vivo._pestanas')
    </div>

    @unless ($configurado)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">El servidor de transmisión (LiveKit) no está configurado en editus: faltan LIVEKIT_URL, LIVEKIT_API_KEY y LIVEKIT_API_SECRET en el .env.</div>
    @endunless
    @if (!empty($esCliente))
        @if ($limites)
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-900 px-4 py-3 mb-5 text-sm">Plan <strong>{{ $limites['nombre'] }}</strong>: hasta {{ $limites['camaras'] }} cámaras por transmisión, en las páginas de tu plan{{ empty($limites['plantillas_logo']) ? ', sin plantillas con logo' : '' }}. <a href="{{ route('suscripcion.index') }}" class="underline">Mi suscripción</a></div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">Necesitas un plan activo para transmitir. <a href="{{ route('suscripcion.index') }}" class="font-semibold underline">Ver planes</a></div>
        @endif
    @endif
    <div id="ev-error" class="hidden rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm"></div>

    {{-- =============================================== PRÓXIMOS EN VIVO (programados) --}}
    @if (count($programadas) && !$activa)
        <div id="ev-aviso" class="hidden rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-rose-900"><strong id="ev-aviso-texto"></strong> <span class="text-rose-800/80">Abre el estudio, conecta las cámaras y sal al aire.</span></div>
            <button type="button" id="ev-aviso-abrir" class="h-10 rounded-xl bg-rose-600 text-white px-4 text-sm font-bold shadow-sm hover:bg-rose-700">Abrir el estudio</button>
        </div>
        <div class="mb-6">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-bold text-gray-900">Próximos en vivo</h2>
                <button type="button" id="ev-permiso-avisos" class="hidden text-xs font-semibold text-indigo-700 hover:underline">🔔 Avisarme en este navegador</button>
            </div>
            <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4" id="ev-programadas">
                @foreach ($programadas as $p)
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-4 flex flex-col min-w-0" data-programada="{{ $p['id'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0"><div class="font-semibold text-gray-900 leading-snug">{{ $p['titulo'] }}</div><div class="text-xs text-gray-500 mt-0.5">{{ $p['programada_texto'] }}</div></div>
                            <span class="shrink-0 rounded-md bg-indigo-50 text-indigo-800 px-2 py-0.5 text-[11px] font-semibold" data-falta="{{ $p['programada_en'] }}">Programado</span>
                        </div>
                        <div class="mt-3 space-y-1">
                            @foreach ($p['destinos'] as $d)
                                <div class="flex items-center justify-between gap-2 text-xs"><span class="truncate">{{ ($d['red'] ?? 'facebook') === 'youtube' ? '▶' : 'f' }} {{ $d['pagina'] }}</span>
                                    <span class="shrink-0 {{ ($d['estado'] ?? '') === 'anunciado' || !empty($d['anuncio_post_id']) ? 'text-indigo-700' : 'text-gray-500' }}">@if (!empty($d['permalink']) && ($d['estado'] ?? '') === 'anunciado')<a href="{{ $d['permalink'] }}" target="_blank" rel="noopener" class="underline">anunciado</a>@elseif (!empty($d['anuncio_post_id']))@if (!empty($d['anuncio_permalink']))<a href="{{ $d['anuncio_permalink'] }}" target="_blank" rel="noopener" class="underline">anuncio publicado</a>@else anuncio publicado @endif · el en vivo se crea a la hora @else{{ ($d['estado'] ?? '') === 'pendiente' ? (($d['red'] ?? 'facebook') === 'facebook' ? 'el en vivo se crea a la hora' : ($p['anunciada'] ? 'se crea a la hora' : 'se anuncia en un momento')) : $d['estado'] }}@endif</span></div>
                                @if (!empty($d['error']))<div class="text-[11px] text-amber-700">{{ $d['error'] }} · saldrá igual a la hora.</div>@endif
                            @endforeach
                        </div>
                        @if (!empty($p['invitaciones'][0]['url']))
                            <div class="mt-3 rounded-lg bg-gray-50 px-2.5 py-2">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Enlace para invitados</div>
                                <div class="flex items-center gap-2 mt-0.5"><code class="text-[11px] text-gray-700 truncate flex-1">{{ $p['invitaciones'][0]['url'] }}</code><button type="button" data-copiar="{{ $p['invitaciones'][0]['url'] }}" class="shrink-0 text-[11px] font-semibold text-indigo-700 hover:underline">Copiar</button></div>
                            </div>
                        @endif
                        <div class="mt-auto pt-3 flex flex-wrap gap-2">
                            <button type="button" data-abrir="{{ $p['id'] }}" class="h-8 rounded-lg bg-[#00024f] text-white px-3 text-xs font-semibold">Abrir el estudio</button>
                            <button type="button" data-cancelar="{{ $p['id'] }}" class="h-8 rounded-lg border border-rose-200 px-3 text-xs text-rose-700 hover:bg-rose-50">Cancelar</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- =============================================== 1. NUEVA TRANSMISIÓN --}}
    <div id="vista-nueva" class="{{ $activa ? 'hidden' : '' }}">
        <div class="grid lg:grid-cols-[minmax(0,1fr)_22rem] gap-5 items-start">
            <form id="form-nueva" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-5 min-w-0">
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Título de la transmisión *</label>
                    <input name="titulo" required maxlength="200" placeholder="Ej: Rueda de prensa de la Gobernación" class="w-full h-11 rounded-lg border-gray-200 text-sm shadow-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Descripción</label>
                    <textarea name="descripcion" rows="2" maxlength="5000" placeholder="Lo que verán en Facebook y YouTube debajo del video" class="w-full rounded-lg border-gray-200 text-sm shadow-sm"></textarea>
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <label class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Páginas de Facebook</label>
                        @if (count($paginas) > 6)<input type="search" id="buscar-pagina" placeholder="Buscar página" class="h-8 w-44 rounded-lg border-gray-200 text-xs shadow-sm">@endif
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2 max-h-72 overflow-y-auto pr-1" id="lista-paginas">
                        @forelse ($paginas as $p)
                            <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40" data-nombre="{{ mb_strtolower($p['nombre']) }}">
                                <input type="checkbox" name="page_ids[]" value="{{ $p['page_id'] }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600">
                                <img src="{{ $p['foto'] }}" alt="" class="h-8 w-8 rounded-full bg-gray-100 shrink-0">
                                <span class="text-sm font-medium text-gray-800 truncate">{{ $p['nombre'] }}</span>
                            </label>
                        @empty
                            <p class="text-sm text-gray-500 sm:col-span-2">No tienes páginas conectadas. Conéctalas en <a href="{{ route('meta.pages.index') }}" class="text-indigo-700 underline">Mis páginas</a>.</p>
                        @endforelse
                    </div>
                </div>

                @if (count($canales))
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-2">Canales de YouTube</label>
                        <div class="grid sm:grid-cols-2 gap-2">
                            @foreach ($canales as $c)
                                <label class="flex items-center gap-3 rounded-xl border border-gray-200 p-2.5 cursor-pointer hover:border-gray-300 has-[:checked]:border-rose-300 has-[:checked]:bg-rose-50/40">
                                    <input type="checkbox" name="youtube_canal_ids[]" value="{{ $c['id'] }}" class="h-4 w-4 rounded border-gray-300 text-rose-600">
                                    <span class="h-8 w-8 rounded-full bg-rose-600 text-white text-xs font-bold flex items-center justify-center shrink-0">▶</span>
                                    <span class="text-sm font-medium text-gray-800 truncate">{{ $c['titulo'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-2">¿Cuándo?</label>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <label class="flex items-start gap-2.5 rounded-xl border border-gray-200 p-3 cursor-pointer has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40"><input type="radio" name="cuando" value="ahora" checked class="mt-0.5 h-4 w-4 text-indigo-600"><span><span class="block text-sm font-semibold text-gray-800">Ahora</span><span class="block text-xs text-gray-500">Abre el estudio y sal al aire cuando quieras.</span></span></label>
                        <label class="flex items-start gap-2.5 rounded-xl border border-gray-200 p-3 cursor-pointer has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40"><input type="radio" name="cuando" value="programar" class="mt-0.5 h-4 w-4 text-indigo-600"><span><span class="block text-sm font-semibold text-gray-800">Programar</span><span class="block text-xs text-gray-500">Se anuncia en tu página de Facebook (publicación) y en YouTube (próxima transmisión); a la hora entras y sales al aire.</span></span></label>
                    </div>
                    <div id="ev-programar" class="hidden mt-3 grid sm:grid-cols-2 gap-3 items-end">
                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Fecha y hora</label>
                            <input type="datetime-local" name="programada_en" step="60" min="{{ now()->addMinutes(15)->format('Y-m-d\TH:i') }}" value="{{ now()->addDay()->setTime(19, 0)->format('Y-m-d\TH:i') }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                        </div>
                        <p class="text-[11px] text-gray-500">Hora de Colombia. Te avisamos 10 minutos antes (celular, correo y aquí). Si nadie sale al aire en 30 minutos, se quita el anuncio.</p>
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Miniatura del anuncio (opcional)</label>
                            <input type="file" name="miniatura_archivo" accept="image/jpeg,image/png" class="block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-200">
                            <p class="text-[11px] text-gray-400 mt-1">JPG o PNG de 1280×720, hasta 2 MB. Va en la publicación de anuncio de Facebook y en YouTube antes de empezar (en YouTube, si el canal está verificado).</p>
                        </div>
                        <label class="sm:col-span-2 flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" name="anuncio_facebook" value="1" checked class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600"><span>Publicar anuncio en Facebook <span class="block text-[11px] text-gray-500">Meta ya no permite programar en vivos desde aplicaciones: publicamos un post con la miniatura, la fecha y la hora (se borra si cancelas). El en vivo se crea a la hora, al salir al aire.</span></span></label>
                    </div>
                </div>

                <div class="grid sm:grid-cols-[minmax(0,1fr)_auto] gap-3 items-end rounded-xl border border-gray-200 p-3">
                    <div>
                        <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Marca</label>
                        <select name="marca_id" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                            @foreach ($marcas as $m)<option value="{{ $m['id'] }}">{{ $m['nombre'] }}</option>@endforeach
                        </select>
                    </div>
                    <p class="text-[11px] text-gray-500 sm:max-w-[16rem]">Logo, colores, fondo, banners y superposiciones. Se ajustan dentro del estudio y quedan guardados para la próxima.</p>
                </div>

                <div class="flex items-center justify-between gap-3 pt-1">
                    <p class="text-xs text-gray-500" id="ev-pista">Primero se abre el estudio (sala de espera). Sales al aire cuando pulses <strong>«Salir al aire»</strong>.</p>
                    <button id="btn-preparar" class="h-11 shrink-0 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90 disabled:opacity-50" @disabled(!$configurado || (!empty($esCliente) && !$limites))>Abrir el estudio</button>
                </div>
            </form>

            <div class="space-y-4 min-w-0">
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-3">Cómo funciona</h3>
                    @foreach ([['1', 'Abre el estudio', 'Elige las páginas y canales. Se prepara la sala sin salir al aire.'], ['2', 'Prepara la escena', 'Tu cámara, cámaras invitadas por enlace, diseño, rótulos y cortinillas. Lo ves en el monitor de programa.'], ['3', 'Sal al aire', 'Se crea el en vivo en cada página y canal, y la escena sale a todos a la vez.'], ['4', 'Termina', 'El video queda publicado en cada página con su enlace.']] as [$n, $t, $d])
                        <div class="flex gap-3 mb-3 last:mb-0"><span class="h-6 w-6 shrink-0 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">{{ $n }}</span><div><div class="text-sm font-semibold text-gray-800">{{ $t }}</div><div class="text-xs text-gray-500">{{ $d }}</div></div></div>
                    @endforeach
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                    <h3 class="text-sm font-bold text-gray-900 mb-2">Transmisiones anteriores</h3>
                    @forelse ($historial as $h)
                        <div class="py-2 border-b border-gray-100 last:border-0">
                            <div class="flex items-center justify-between gap-2"><span class="text-sm font-medium text-gray-800 truncate">{{ $h['titulo'] }}</span>
                                <span class="text-[11px] font-semibold {{ $h['estado'] === 'error' ? 'text-rose-700' : 'text-gray-500' }}">{{ $h['estado'] === 'error' ? 'error' : 'terminada' }}</span></div>
                            <div class="text-[11px] text-gray-400">{{ $h['fecha'] }} · {{ implode(', ', $h['paginas']) }}</div>
                            @foreach ($h['destinos'] as $d)@if (!empty($d['permalink']))<a href="{{ $d['permalink'] }}" target="_blank" rel="noopener" class="inline-block mr-2 text-[11px] text-indigo-700 hover:underline">Ver en {{ $d['pagina'] }}</a>@endif @endforeach
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Aún no hay transmisiones desde la web.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- =============================================== 2. ESTUDIO (pantalla completa, al estilo StreamYard) --}}
    <div id="vista-estudio" class="{{ $activa ? '' : 'hidden' }} fixed inset-0 z-[70] bg-[#f3f4f8] text-gray-900 flex flex-col">
        {{-- Barra superior --}}
        <header class="h-14 shrink-0 bg-white border-b border-gray-200 flex items-center gap-3 px-3 sm:px-4">
            <span class="h-9 w-9 shrink-0 rounded-xl bg-[#00024f] text-white grid place-items-center font-black text-lg">e</span>
            <div class="min-w-0 flex-1">
                <div id="ev-titulo" class="text-sm sm:text-base font-semibold text-gray-900 truncate"></div>
                <div id="ev-destinos" class="hidden sm:flex gap-1.5 mt-0.5 overflow-hidden"></div>
            </div>
            <div id="ev-espectadores" class="hidden text-right leading-tight"><div class="text-[10px] uppercase tracking-wide text-gray-400">Espectadores</div><div class="text-base font-bold" id="ev-espectadores-n">0</div></div>
            <span id="ev-estado" class="hidden sm:inline-flex items-center gap-1.5 rounded-full bg-gray-100 text-gray-700 px-3 py-1 text-xs font-bold tracking-wide">SALA DE ESPERA</span>
            <span id="ev-reloj" class="hidden font-mono text-sm font-bold text-rose-600">00:00</span>
            <button id="btn-aire" class="h-10 shrink-0 rounded-xl bg-[#1d4ed8] text-white px-3 sm:px-5 text-sm font-bold shadow-sm hover:bg-blue-700 disabled:opacity-50">Salir al aire</button>
            <button id="btn-terminar-top" class="hidden h-10 shrink-0 rounded-xl bg-rose-600 text-white px-3 sm:px-4 text-sm font-bold shadow-sm hover:bg-rose-700">Terminar</button>
        </header>

        <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-y-auto lg:overflow-hidden pb-20 lg:pb-0">
            {{-- Zona central: escenario, diseños y bastidores --}}
            <section class="order-1 flex-1 min-w-0 lg:overflow-y-auto">
                <div class="mx-auto w-full max-w-[1120px] px-2 sm:px-5 pt-3 sm:pt-5">
                    <div id="escenario-caja" class="relative w-full aspect-video rounded-lg sm:rounded-xl overflow-hidden bg-black shadow-sm">
                        <iframe id="ev-monitor" class="absolute inset-0 w-full h-full" allow="autoplay" title="Lo que ve la audiencia"></iframe>
                        <span class="absolute left-2 bottom-2 sm:left-3 sm:bottom-3 rounded-md bg-black/60 text-white text-[10px] sm:text-[11px] font-bold px-1.5 py-0.5">720p</span>
                        <span id="ev-salida" class="absolute right-2 bottom-2 rounded-md bg-black/50 text-white/80 text-[10px] px-2 py-0.5 hidden"></span>
                        <div id="ev-teleprompter" class="hidden absolute inset-x-0 top-0 h-1/2 bg-black/70 text-white overflow-hidden">
                            <div id="tp-texto" class="px-6 sm:px-12 py-4 text-xl sm:text-3xl font-semibold leading-snug whitespace-pre-wrap"></div>
                            <button type="button" id="tp-cerrar" class="absolute right-2 top-2 h-8 w-8 rounded-full bg-white/15 hover:bg-white/25 grid place-items-center" data-ic="x"></button>
                        </div>
                    </div>
                    {{-- Diseños --}}
                    <div class="mt-2 sm:mt-3 flex items-center gap-2">
                        <div id="ev-layouts" class="flex-1 min-w-0 flex gap-1.5 overflow-x-auto pb-1 sm:justify-center"></div>
                        <button type="button" id="btn-nombres" title="Mostrar los nombres sobre las cámaras" class="h-11 w-11 shrink-0 rounded-lg border border-gray-200 bg-white text-gray-700 grid place-items-center hover:bg-gray-50" data-ic="etiqueta"></button>
                        <button type="button" data-abrir-modal="ajustes" title="Ajustes" class="hidden sm:grid h-11 w-11 shrink-0 rounded-lg border border-gray-200 bg-white text-gray-700 place-items-center hover:bg-gray-50" data-ic="ajustes"></button>
                    </div>
                    {{-- Bastidores: cámaras listas para entrar al escenario --}}
                    <div class="mt-3 sm:mt-4 mb-4 flex gap-3 overflow-x-auto pb-2" id="ev-bastidores"></div>
                </div>
            </section>

            {{-- Panel lateral --}}
            <aside class="order-3 lg:order-2 w-full lg:w-[372px] shrink-0 bg-white lg:border-l border-gray-200 flex flex-col min-h-0">
                <div class="h-14 shrink-0 border-b border-gray-200 px-4 flex items-center justify-between gap-2">
                    <div id="panel-marca" class="relative min-w-0">
                        <button type="button" id="btn-marca" class="flex items-center gap-2 min-w-0 rounded-lg px-1.5 py-1 hover:bg-gray-50">
                            <span class="h-7 w-7 shrink-0 rounded-full grid place-items-center text-white" id="marca-punto" data-ic="estilo"></span>
                            <span id="marca-nombre" class="font-semibold truncate">Marca 1</span><span class="text-gray-500" data-ic="abajo"></span>
                        </button>
                        <div id="menu-marca" class="hidden absolute left-0 top-11 z-30 w-64 rounded-xl border border-gray-200 bg-white shadow-xl p-1.5"></div>
                    </div>
                    <div id="panel-titulo" class="hidden font-semibold"></div>
                </div>
                <div id="panel" class="flex-1 min-h-0 lg:overflow-y-auto px-4 py-4"></div>
            </aside>

            {{-- Pestañas del panel (riel a la derecha; en el celular, fila deslizable) --}}
            <nav id="riel" class="order-2 lg:order-3 shrink-0 bg-white border-y lg:border-y-0 lg:border-l border-gray-200 flex lg:flex-col gap-1 overflow-x-auto lg:overflow-y-auto px-2 py-1.5 lg:py-3 lg:w-[92px] sticky top-0 z-20 lg:static"></nav>
        </div>

        {{-- Controles --}}
        <footer class="fixed lg:static bottom-0 inset-x-0 z-30 h-[72px] shrink-0 bg-white border-t border-gray-200 flex items-center justify-center gap-1.5 sm:gap-3 px-2" style="padding-bottom: env(safe-area-inset-bottom)">
            <div class="flex rounded-xl overflow-hidden">
                <button type="button" id="btn-mic" title="Micrófono" class="ctl h-12 w-12 sm:w-14 grid place-items-center bg-gray-100 hover:bg-gray-200" data-ic="mic"></button>
                <button type="button" data-abrir-modal="ajustes" class="hidden sm:grid h-12 w-8 place-items-center bg-gray-100 hover:bg-gray-200 border-l border-gray-200 text-gray-500" data-ic="arriba"></button>
            </div>
            <div class="flex rounded-xl overflow-hidden">
                <button type="button" id="btn-cam" title="Cámara" class="ctl h-12 w-12 sm:w-14 grid place-items-center bg-gray-100 hover:bg-gray-200" data-ic="video"></button>
                <button type="button" data-abrir-modal="ajustes" class="hidden sm:grid h-12 w-8 place-items-center bg-gray-100 hover:bg-gray-200 border-l border-gray-200 text-gray-500" data-ic="arriba"></button>
            </div>
            <button type="button" id="btn-pantalla" title="Presentar (compartir pantalla)" class="ctl hidden sm:grid h-12 w-12 sm:w-14 rounded-xl place-items-center bg-gray-100 hover:bg-gray-200" data-ic="pantalla"></button>
            <button type="button" data-abrir-modal="invitar" title="Invitar" class="h-12 w-12 sm:w-14 rounded-xl grid place-items-center bg-gray-100 hover:bg-gray-200" data-ic="invitar"></button>
            <button type="button" id="btn-tp" title="Teleprompter con tus notas" class="h-12 w-12 sm:w-14 rounded-xl grid place-items-center bg-gray-100 hover:bg-gray-200" data-ic="notas"></button>
            <button type="button" data-abrir-modal="ajustes" title="Ajustes" class="h-12 w-12 sm:w-14 rounded-xl grid place-items-center bg-gray-100 hover:bg-gray-200" data-ic="ajustes"></button>
            <button type="button" id="btn-terminar" title="Salir del estudio" class="h-12 w-14 sm:w-20 rounded-xl grid place-items-center bg-rose-600 text-white hover:bg-rose-700" data-ic="salir"></button>
        </footer>
    </div>

    {{-- Ventanas --}}
    <div id="modal" class="hidden fixed inset-0 z-[90] bg-black/40 flex items-end sm:items-center justify-center p-0 sm:p-4">
        <div class="w-full sm:max-w-lg max-h-[88vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl bg-white shadow-2xl">
            <div class="sticky top-0 bg-white flex items-center justify-between px-5 py-4 border-b border-gray-100"><h3 id="modal-titulo" class="font-bold text-gray-900"></h3><button type="button" id="modal-cerrar" class="h-9 w-9 rounded-full hover:bg-gray-100 grid place-items-center" data-ic="x"></button></div>
            <div id="modal-cuerpo" class="p-5"></div>
        </div>
    </div>
    <div id="avisos" class="fixed z-[95] left-1/2 -translate-x-1/2 bottom-24 lg:bottom-6 flex flex-col items-center gap-2 pointer-events-none w-[min(92vw,30rem)]"></div>
    <div id="audio-invitados" class="hidden"></div>
    <input type="file" id="subir-archivo" class="hidden">
</div>

<script src="https://cdn.jsdelivr.net/npm/livekit-client@2/dist/livekit-client.umd.min.js"></script>
<script src="{{ route('en-vivo.sonidos') }}"></script>
<script>
(function () {
  const RUTAS = {
    preparar: @json(route('en-vivo.web.preparar')),
    programar: @json(route('en-vivo.web.programar')),
    base: @json(url('/en-vivo')),
  };
  const PERMITE_LOGO = @json(empty($esCliente) || !empty($limites['plantillas_logo']));
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const g = (id) => document.getElementById(id);
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pedir = async (metodo, url, body) => {
    let r;
    try { r = await fetch(url, { method: metodo, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined }); }
    catch (e) { throw new Error('Sin conexión con el servidor de editus. Revisa tu internet y vuelve a intentar.'); }
    let j = null; try { j = await r.json(); } catch (e) {}
    if (!j) throw new Error('El servidor no respondió (HTTP ' + r.status + ')');
    if (j.success === false) throw new Error(j.error || 'Error');
    if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status));
    return j;
  };
  const error = (msg) => { const e = g('ev-error'); if (!msg) { e.classList.add('hidden'); return; } e.textContent = msg; e.classList.remove('hidden'); e.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
  function aviso(msg, tipo) {
    const d = document.createElement('div');
    d.className = 'pointer-events-auto rounded-xl px-4 py-2.5 text-sm font-medium shadow-lg ' + (tipo === 'error' ? 'bg-rose-600 text-white' : 'bg-gray-900 text-white');
    d.textContent = msg; g('avisos').appendChild(d);
    setTimeout(() => { d.style.transition = 'opacity .4s'; d.style.opacity = '0'; setTimeout(() => d.remove(), 450); }, tipo === 'error' ? 6500 : 2600);
  }
  const fallo = (e) => aviso(e && e.message ? e.message : String(e), 'error');

  // ------------------------------------------------ íconos (trazos estilo Lucide)
  const P = {
    mic: '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>',
    micOff: '<path d="m2 2 20 20"/><path d="M18.89 13.23A7.12 7.12 0 0 0 19 12v-2"/><path d="M5 10v2a7 7 0 0 0 12 5"/><path d="M15 9.34V5a3 3 0 0 0-5.68-1.33"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12"/><path d="M12 19v3"/>',
    video: '<path d="m16 13 5.22 3.48a.5.5 0 0 0 .78-.42V7.87a.5.5 0 0 0-.75-.43L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    videoOff: '<path d="M10.66 6H14a2 2 0 0 1 2 2v2.5l5.25-3.06a.5.5 0 0 1 .75.43v8.2"/><path d="M16 16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2"/><path d="m2 2 20 20"/>',
    pantalla: '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M12 17v4M8 21h8"/><path d="m9 10 3-3 3 3"/><path d="M12 7v6"/>',
    invitar: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    ajustes: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    salir: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    comentarios: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    banners: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 15h18"/>',
    activos: '<path d="M18 22H4a2 2 0 0 1-2-2V6"/><path d="m22 13-1.3-1.3a2.41 2.41 0 0 0-3.4 0L11 18"/><circle cx="12" cy="8" r="2"/><rect width="16" height="16" x="6" y="2" rx="2"/>',
    estilo: '<circle cx="13.5" cy="6.5" r="1" fill="currentColor"/><circle cx="17.5" cy="10.5" r="1" fill="currentColor"/><circle cx="8.5" cy="7.5" r="1" fill="currentColor"/><circle cx="6.5" cy="12.5" r="1" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.93 0 1.65-.75 1.65-1.69 0-.44-.18-.84-.44-1.13-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 0 1 1.67-1.67h2c3.05 0 5.55-2.5 5.55-5.55C21.97 6.01 17.46 2 12 2z"/>',
    widgets: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    notas: '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
    personas: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    chat: '<path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/>',
    mas: '<path d="M5 12h14M12 5v14"/>', menos: '<path d="M5 12h14"/>',
    arriba: '<path d="m18 15-6-6-6 6"/>', abajo: '<path d="m6 9 6 6 6-6"/>',
    play: '<path d="M7 4v16l13-8z" fill="currentColor"/>', stop: '<rect x="6" y="6" width="12" height="12" rx="1.5" fill="currentColor"/>',
    volumen: '<path d="M11 4.7a.7.7 0 0 0-1.2-.5L6.41 7.59A1.4 1.4 0 0 1 5.42 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.42a1.4 1.4 0 0 1 1 .41l3.38 3.39a.7.7 0 0 0 1.2-.5z"/><path d="M16 9a5 5 0 0 1 0 6M19.36 18.36a9 9 0 0 0 0-12.73"/>',
    oir: '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
    basura: '<path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    lapiz: '<path d="M21.17 6.81a1 1 0 0 0-3.99-3.99L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5z"/>',
    copiar: '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>', puntos: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    estrella: '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
    reloj: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>', subir: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
    repetir: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
    etiqueta: '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1" fill="currentColor"/>',
    enviar: '<path d="M14.54 21.69a.5.5 0 0 0 .94-.03l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.03.94l7.93 3.18a2 2 0 0 1 1.11 1.11z"/><path d="m21.85 2.15-10.94 10.94"/>',
  };
  const ic = (n, cls) => '<svg class="' + (cls || 'h-5 w-5') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (P[n] || '') + '</svg>';
  const pintarIconos = (raiz) => (raiz || document).querySelectorAll('[data-ic]').forEach(e => { if (!e.dataset.icListo) { e.innerHTML = ic(e.dataset.ic, e.dataset.icCls || 'h-5 w-5'); e.dataset.icListo = '1'; } });
  pintarIconos();

  // ------------------------------------------------ catálogos (mismos nombres que la escena)
  const FONDOS = {
    noche: ['Noche', 'linear-gradient(160deg, #18345a, #0b1c33)'],
    'olas-azules': ['Olas azules', 'radial-gradient(120% 80% at 10% 110%, #60a5fa 0 30%, transparent 31%), radial-gradient(110% 70% at 90% -10%, #1d4ed8 0 35%, transparent 36%), linear-gradient(135deg, #1e3a8a, #2563eb 55%, #93c5fd)'],
    'montanas-negras': ['Montañas negras', 'linear-gradient(160deg, transparent 0 55%, rgba(255,255,255,.08) 55% 57%, transparent 57%), linear-gradient(20deg, transparent 0 60%, rgba(255,255,255,.1) 60% 62%, transparent 62%), linear-gradient(180deg, #111, #000)'],
    'olas-moradas': ['Olas moradas', 'radial-gradient(90% 60% at 80% 100%, #c4b5fd 0 35%, transparent 36%), radial-gradient(80% 60% at 10% 0%, #a78bfa 0 30%, transparent 31%), linear-gradient(135deg, #7c3aed, #a78bfa 60%, #ddd6fe)'],
    'cielo-cian': ['Cielo cian', 'linear-gradient(160deg, #a5f3fc, #22d3ee 45%, #0ea5e9)'],
    'olas-cian': ['Olas cian', 'repeating-linear-gradient(115deg, rgba(255,255,255,.12) 0 8px, transparent 8px 22px), linear-gradient(135deg, #0891b2, #22d3ee)'],
    'azul-rosa': ['Azul y rosa', 'linear-gradient(120deg, #3b82f6, #a855f7 50%, #f43f5e)'],
    'morado-fucsia': ['Morado fucsia', 'linear-gradient(120deg, #4c1d95, #c026d3 55%, #db2777)'],
    'cian-verde': ['Cian y verde', 'linear-gradient(120deg, #22d3ee, #34d399 60%, #a3e635)'],
    estudio: ['Estudio', 'radial-gradient(70% 70% at 50% 40%, #3f3f46, #09090b)'],
    blanco: ['Blanco', 'linear-gradient(160deg, #ffffff, #e5e7eb)'],
  };
  const SUPERPOSICIONES = { suscribete: 'Me gusta y comparte', bienvenidos: 'Bienvenidos', preguntas: 'Preguntas y respuestas', ultima: 'Última hora', logro: 'Logro', redes: 'Síguenos' };
  const PANTALLAS = [
    { preset: 'temporizador', duracion: 10, nombre: 'Temporizador 10 s' }, { preset: 'temporizador', duracion: 30, nombre: 'Temporizador 30 s' },
    { preset: 'temporizador', duracion: 60, nombre: 'Temporizador 1 min' }, { preset: 'pronto', duracion: 0, nombre: 'Comenzará pronto' },
    { preset: 'enseguida', duracion: 0, nombre: 'Volvemos enseguida' }, { preset: 'gracias', duracion: 0, nombre: 'Gracias por acompañarnos' },
  ];
  const TEMAS = { clasico: 'Clásico', burbuja: 'Burbuja', minimal: 'Minimal', bloque: 'Bloque' };
  const FUENTES = { inter: ['Inter + Playfair', 'Inter, sans-serif'], montserrat: ['Montserrat', 'Montserrat, sans-serif'], oswald: ['Oswald', 'Oswald, sans-serif'], poppins: ['Poppins', 'Poppins, sans-serif'], slab: ['Roboto Slab', "'Roboto Slab', serif"], playfair: ['Playfair', "'Playfair Display', serif"] };
  const COLORES = ['#C8102E', '#E11D48', '#F59E0B', '#16A34A', '#059669', '#0891B2', '#2563EB', '#1D4ED8', '#7C3AED', '#DB2777', '#111827', '#00024F'];
  const LAYOUTS = [['solo', 'Una persona'], ['dos', 'Dos lado a lado'], ['cuadricula', 'Grupo'], ['lider', 'Principal y laterales'], ['lateral', 'Pantalla y presentador'], ['barra', 'Principal y fila'], ['pip', 'Imagen en imagen'], ['cine', 'Pantalla completa']];
  const CUPOS = { solo: 1, dos: 2, pip: 2, cuadricula: 6, lider: 4, lateral: 2, barra: 5, cine: 1 };
  const EFECTOS = (window.EditusAudio && window.EditusAudio.EFECTOS) || {};
  const MUSICAS = (window.EditusAudio && window.EditusAudio.MUSICAS) || {};

  let MARCAS = @json($marcas);
  let ACTIVOS = @json($activos);
  const RECURSOS = @json($recursos);
  let T = @json($activa);          // transmisión actual
  let room = null, camOn = true, micOn = true, pantallaOn = false, oirInvitados = true, espejo = true;
  let participantes = [], relojInicio = null, sondeo = null;
  let pestana = 'activos', chat = [], sinLeer = 0, comentarios = [], avisosComentarios = [], sondeoComentarios = null, bucle = false;

  // ------------------------------------------------ búsqueda de páginas
  const bp = g('buscar-pagina');
  if (bp) bp.addEventListener('input', () => { const q = bp.value.toLowerCase(); document.querySelectorAll('#lista-paginas [data-nombre]').forEach(e => e.style.display = !q || e.dataset.nombre.includes(q) ? '' : 'none'); });

  // ------------------------------------------------ 1. preparar / programar
  g('form-nueva').addEventListener('submit', async (ev) => {
    ev.preventDefault(); error(null);
    const f = new FormData(ev.target);
    const datos = {
      titulo: f.get('titulo'), descripcion: f.get('descripcion') || '', marca_id: f.get('marca_id') ? Number(f.get('marca_id')) : null,
      page_ids: f.getAll('page_ids[]'), youtube_canal_ids: f.getAll('youtube_canal_ids[]').map(Number), plantilla: {},
    };
    if (!datos.page_ids.length && !datos.youtube_canal_ids.length) return error('Elige al menos una página de Facebook o un canal de YouTube.');
    if (f.get('cuando') === 'programar') {
      const b = g('btn-preparar'); b.disabled = true; b.textContent = 'Programando…';
      try {
        const img = f.get('miniatura_archivo');
        let miniatura = null;
        if (img && img.size) {
          if (img.size > 2 * 1024 * 1024) throw new Error('La miniatura pesa más de 2 MB. Usa una imagen JPG de 1280×720.');
          miniatura = await new Promise((ok, mal) => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.onerror = mal; fr.readAsDataURL(img); });
        }
        await pedir('POST', RUTAS.programar, { ...datos, programada_en: f.get('programada_en'), miniatura, anuncio_facebook: f.get('anuncio_facebook') === '1' });
        if ('Notification' in window && Notification.permission === 'default') { try { await Notification.requestPermission(); } catch (e) {} }
        location.reload();
      } catch (e) { error(e.message); b.disabled = false; b.textContent = 'Programar el en vivo'; }
      return;
    }
    const b = g('btn-preparar'); b.disabled = true; b.textContent = 'Abriendo el estudio…';
    try {
      const j = await pedir('POST', RUTAS.preparar, datos);
      T = j.transmision; abrirEstudio(j.livekit, j.monitor);
    } catch (e) { error(e.message); }
    b.disabled = false; b.textContent = 'Abrir el estudio';
  });

  // ------------------------------------------------ programados
  document.querySelectorAll('input[name="cuando"]').forEach(r => r.addEventListener('change', () => {
    const prog = document.querySelector('input[name="cuando"]:checked').value === 'programar';
    g('ev-programar').classList.toggle('hidden', !prog);
    g('btn-preparar').textContent = prog ? 'Programar el en vivo' : 'Abrir el estudio';
    g('ev-pista').innerHTML = prog ? 'Se crea el anuncio y el <strong>enlace para invitados</strong>. A la hora abres el estudio desde «Próximos en vivo».' : 'Primero se abre el estudio (sala de espera). Sales al aire cuando pulses <strong>«Salir al aire»</strong>.';
  }));
  const PROGRAMADAS = @json($programadas);
  const abrirProgramada = async (id, boton) => {
    error(null); if (boton) { boton.disabled = true; boton.textContent = 'Abriendo…'; }
    try { const j = await pedir('POST', RUTAS.base + '/' + id + '/abrir'); T = j.transmision; g('ev-aviso')?.classList.add('hidden'); abrirEstudio(j.livekit, j.monitor); }
    catch (e) { error(e.message); if (boton) { boton.disabled = false; boton.textContent = 'Abrir el estudio'; } }
  };
  const listaProg = g('ev-programadas');
  if (listaProg) listaProg.addEventListener('click', async (ev) => {
    const b = ev.target.closest('button'); if (!b) return;
    if (b.dataset.copiar) { try { await navigator.clipboard.writeText(b.dataset.copiar); b.textContent = 'Copiado ✓'; setTimeout(() => b.textContent = 'Copiar', 1600); } catch (e) { prompt('Copia el enlace:', b.dataset.copiar); } }
    if (b.dataset.abrir) abrirProgramada(b.dataset.abrir, b);
    if (b.dataset.cancelar) { if (!confirm('¿Cancelar este en vivo? Se quita el anuncio de Facebook y YouTube.')) return; try { await pedir('POST', RUTAS.base + '/' + b.dataset.cancelar + '/cancelar'); location.reload(); } catch (e) { error(e.message); } }
  });
  const avisadas = new Set();
  const falta = (iso) => (new Date(iso) - Date.now()) / 1000;
  const textoFalta = (s) => s <= 0 ? 'es ahora' : s < 3600 ? 'en ' + Math.ceil(s / 60) + ' min' : s < 86400 ? 'en ' + Math.floor(s / 3600) + ' h ' + Math.floor(s % 3600 / 60) + ' min' : 'en ' + Math.floor(s / 86400) + ' día(s)';
  function revisarProgramadas() {
    document.querySelectorAll('[data-falta]').forEach(e => { const s = falta(e.dataset.falta); e.textContent = s <= 600 ? '● ' + textoFalta(s) : textoFalta(s); e.className = 'shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold ' + (s <= 600 ? 'bg-rose-600 text-white' : 'bg-indigo-50 text-indigo-800'); });
    const prox = PROGRAMADAS.find(p => falta(p.programada_en) <= 600 && falta(p.programada_en) > -1800);
    const av = g('ev-aviso');
    if (av && prox && !T) {
      av.classList.remove('hidden');
      g('ev-aviso-texto').textContent = '«' + prox.titulo + '» ' + (falta(prox.programada_en) > 0 ? 'empieza ' + textoFalta(falta(prox.programada_en)) + '.' : 'ya debía empezar.');
      g('ev-aviso-abrir').onclick = () => abrirProgramada(prox.id, g('ev-aviso-abrir'));
      if (!avisadas.has(prox.id) && 'Notification' in window && Notification.permission === 'granted') {
        avisadas.add(prox.id);
        try { const n = new Notification('En vivo ' + textoFalta(falta(prox.programada_en)) + ': ' + prox.titulo, { body: 'Abre el estudio de editus y sal al aire.' }); n.onclick = () => window.focus(); } catch (e) {}
      }
    }
  }
  if (PROGRAMADAS.length) {
    const bpa = g('ev-permiso-avisos');
    if (bpa && 'Notification' in window && Notification.permission === 'default') { bpa.classList.remove('hidden'); bpa.onclick = async () => { await Notification.requestPermission(); bpa.classList.add('hidden'); }; }
    revisarProgramadas(); setInterval(revisarProgramadas, 20000);
  }

  // ================================================ 2. ESTUDIO
  const url = (accion) => RUTAS.base + '/' + T.id + '/' + accion;
  const E = () => (T && T.escena) || {};
  const PL = () => (T && T.plantilla) || {};
  const marca = () => MARCAS.find(m => m.id === (T && T.marca_id)) || MARCAS[0] || { id: null, nombre: 'Marca', datos: {} };

  async function abrirEstudio(livekit, monitor) {
    g('vista-estudio').classList.remove('hidden');
    document.documentElement.style.overflow = 'hidden';
    pintarTransmision();
    if (!livekit) { try { const j = await pedir('GET', url('estado')); livekit = j.livekit; monitor = j.monitor; aplicarEstado(j); } catch (e) { fallo(e); } }
    if (monitor && monitor.url) g('ev-monitor').src = monitor.url;
    // Si la transmisión aún no tiene marca, se usa la primera (logo, colores y fondo guardados)
    if (T && !T.marca_id && MARCAS[0]) { try { const j = await pedir('POST', url('marca'), { marca_id: MARCAS[0].id }); T.plantilla = j.plantilla; T.marca_id = MARCAS[0].id; } catch (e) {} }
    pintarTodo();
    if (livekit) conectar(livekit);
    clearInterval(sondeo); sondeo = setInterval(sondear, 5000); sondear();
  }

  // ------------------------------------------------ conexión con la sala (este navegador = cámara principal)
  async function conectar(lk) {
    const { Room, RoomEvent } = LivekitClient;
    try {
      room = new Room({ adaptiveStream: true, dynacast: true, videoCaptureDefaults: { resolution: { width: 1920, height: 1080, frameRate: 30 } }, publishDefaults: { videoEncoding: { maxBitrate: 3500000, maxFramerate: 30 }, simulcast: false } });
      const repintar = () => { pintarBastidores(); if (pestana === 'personas') pintarPanel(); };
      ['TrackSubscribed', 'TrackUnsubscribed', 'TrackMuted', 'TrackUnmuted', 'ParticipantConnected', 'ParticipantDisconnected', 'LocalTrackPublished', 'LocalTrackUnpublished'].forEach(ev => room.on(RoomEvent[ev], repintar));
      room.on(RoomEvent.TrackSubscribed, (track, pub, p) => { if (track.kind === 'audio') sonarInvitado(track, p); });
      room.on(RoomEvent.ActiveSpeakersChanged, (lista) => { hablando = new Set(lista.map(s => s.identity)); document.querySelectorAll('[data-tile]').forEach(t => t.classList.toggle('ring-4', hablando.has(t.dataset.tile.replace(/#pantalla$/, '')) && !t.dataset.tile.endsWith('#pantalla'))); });
      room.on(RoomEvent.DataReceived, (payload, p, k, tema) => {
        if (tema !== 'chat') return;
        try { const m = JSON.parse(new TextDecoder().decode(payload)); recibirChat(m.nombre || (p && p.name) || 'Invitado', m.texto, false); } catch (e) {}
      });
      room.on(RoomEvent.Disconnected, () => aviso('Se desconectó tu cámara del estudio. Recarga la página para volver a entrar.', 'error'));
      await room.connect(lk.url, lk.token);
      try { await room.localParticipant.enableCameraAndMicrophone(); }
      catch (e) { fallo(new Error('No se pudo abrir la cámara o el micrófono: ' + e.message + '. Revisa los permisos del navegador.')); }
      pintarControles(); pintarBastidores();
    } catch (e) { fallo(new Error('No se pudo conectar con el estudio: ' + e.message)); }
  }
  let hablando = new Set();
  function sonarInvitado(track, p) {
    if (!p || p.identity.startsWith('EG_')) return;
    const el = track.attach(); el.dataset.de = p.identity; el.muted = !oirInvitados; g('audio-invitados').appendChild(el);
    el.play && el.play().catch(() => {});
  }
  const marcarCtl = (b, on, icOn, icOff) => { b.innerHTML = ic(on ? icOn : icOff); b.classList.toggle('bg-rose-100', !on); b.classList.toggle('text-rose-700', !on); b.classList.toggle('bg-gray-100', on); };
  function pintarControles() {
    marcarCtl(g('btn-mic'), micOn, 'mic', 'micOff'); marcarCtl(g('btn-cam'), camOn, 'video', 'videoOff');
    const bp = g('btn-pantalla'); bp.classList.toggle('bg-blue-100', pantallaOn); bp.classList.toggle('text-blue-700', pantallaOn);
  }
  g('btn-mic').onclick = async () => { if (!room) return; micOn = !micOn; await room.localParticipant.setMicrophoneEnabled(micOn).catch(fallo); pintarControles(); };
  g('btn-cam').onclick = async () => { if (!room) return; camOn = !camOn; await room.localParticipant.setCameraEnabled(camOn).catch(fallo); pintarControles(); pintarBastidores(); };
  async function alternarPantalla() {
    if (!room) return;
    try { pantallaOn = !pantallaOn; await room.localParticipant.setScreenShareEnabled(pantallaOn, { audio: true, resolution: { width: 1920, height: 1080, frameRate: 15 } }); }
    catch (e) { pantallaOn = false; if (e && e.name !== 'NotAllowedError') fallo(new Error('No se pudo compartir la pantalla: ' + e.message)); }
    pintarControles(); pintarBastidores();
  }
  g('btn-pantalla').onclick = alternarPantalla;

  // ------------------------------------------------ fuentes (cámaras y pantallas de la sala)
  function fuentes() {
    const out = [];
    const pubLocal = (src) => room && room.localParticipant.getTrackPublication(src);
    const S = LivekitClient.Track.Source;
    out.push({ id: 'camara-principal', nombre: 'Tú (este navegador)', track: room && camOn ? pubLocal(S.Camera)?.track : null, local: true, video: camOn, audio: micOn });
    if (pantallaOn) out.push({ id: 'camara-principal#pantalla', nombre: 'Tu pantalla', track: pubLocal(S.ScreenShare)?.track, local: true, pantalla: true, video: true });
    if (room) for (const p of room.remoteParticipants.values()) {
      if (p.identity.startsWith('EG_') || p.identity.startsWith('monitor-')) continue;
      const cam = p.getTrackPublication(S.Camera), mic = p.getTrackPublication(S.Microphone), sc = p.getTrackPublication(S.ScreenShare);
      out.push({ id: p.identity, nombre: p.name || p.identity, track: cam && !cam.isMuted ? cam.track : null, video: !!(cam && !cam.isMuted), audio: !!(mic && !mic.isMuted), invitado: true });
      if (sc && sc.track) out.push({ id: p.identity + '#pantalla', nombre: 'Pantalla de ' + (p.name || p.identity), track: sc.track, pantalla: true, video: true });
    }
    return out;
  }
  // Mismo criterio que la escena para saber quién está en el escenario
  function enEscenario() {
    const e = E(); if (e.vacio) return [];
    const disp = fuentes().filter(f => f.video).map(f => f.id), cupos = CUPOS[e.layout] || 1, lista = [];
    const meter = (id) => { if (disp.includes(id) && !lista.includes(id) && lista.length < cupos) lista.push(id); };
    meter(e.principal || 'camara-principal'); (e.visibles || []).forEach(meter);
    if (!(e.visibles || []).length || !lista.length) disp.forEach(meter);
    return lista;
  }
  async function alternarEscenario(id) {
    const actual = enEscenario();
    let lista = actual.includes(id) ? actual.filter(x => x !== id) : [...actual, id];
    const cupos = CUPOS[E().layout] || 1;
    let layout = E().layout || 'solo';
    if (lista.length > cupos) { layout = lista.length <= 2 ? 'dos' : (lista.length <= 6 ? 'cuadricula' : layout); }
    if (!lista.length) return escena({ vacio: true });
    await escena({ vacio: false, layout, visibles: lista, principal: lista[0] });
  }
  function pintarBastidores() {
    const cont = g('ev-bastidores'); if (!cont) return;
    const enE = enEscenario(), lista = fuentes(), principal = E().principal || 'camara-principal';
    const viejos = new Map(Array.from(cont.querySelectorAll('[data-tile]')).map(t => [t.dataset.tile, t]));
    cont.innerHTML = '';
    lista.forEach(f => {
      const enAire = enE.includes(f.id);
      let t = viejos.get(f.id);
      if (!t) {
        t = document.createElement('div'); t.dataset.tile = f.id;
        t.innerHTML = '<div class="relative aspect-video bg-gray-800 rounded-t-xl overflow-hidden"><video autoplay playsinline muted class="w-full h-full object-cover"></video><div class="sin-video absolute inset-0 grid place-items-center text-white/70 text-xs">Sin video</div>'
          + '<div class="absolute right-1.5 top-1.5 flex gap-1"><span class="ind-mic h-7 w-7 rounded-full bg-white/90 text-gray-700 grid place-items-center"></span><button type="button" class="menu-tile h-7 w-7 rounded-full bg-white/90 text-gray-700 grid place-items-center">' + ic('puntos', 'h-4 w-4') + '</button></div>'
          + '<button type="button" class="btn-escenario absolute inset-x-0 bottom-0 py-1.5 text-xs font-semibold text-white opacity-0 hover:opacity-100 focus:opacity-100 transition"></button></div>'
          + '<div class="flex items-center gap-1.5 px-2 py-1.5"><span class="nm text-[13px] font-medium text-gray-800 truncate flex-1"></span><span class="aire hidden text-[10px] font-bold text-white bg-rose-600 rounded px-1.5 py-0.5">EN ESCENA</span></div>';
      }
      t.className = 'shrink-0 w-44 sm:w-52 rounded-xl bg-white border shadow-sm ring-emerald-400 ' + (enAire ? 'border-rose-400' : 'border-gray-200') + (hablando.has(f.id) ? ' ring-4' : '');
      const v = t.querySelector('video');
      if (f.track && v._track !== f.track) { try { v._track && v._track.detach(v); } catch (e) {} f.track.attach(v); v._track = f.track; }
      if (!f.track && v._track) { try { v._track.detach(v); } catch (e) {} v._track = null; }
      v.style.transform = f.local && !f.pantalla && espejo ? 'scaleX(-1)' : '';
      v.style.objectFit = f.pantalla ? 'contain' : 'cover';
      t.querySelector('.sin-video').classList.toggle('hidden', !!f.track);
      t.querySelector('.ind-mic').innerHTML = f.pantalla ? ic('pantalla', 'h-4 w-4') : ic(f.audio ? 'mic' : 'micOff', 'h-4 w-4' + (f.audio ? '' : ' text-rose-600'));
      const persona = personaDe(f.id);
      t.querySelector('.nm').textContent = (persona && persona.nombre) || f.nombre;
      t.querySelector('.aire').classList.toggle('hidden', !enAire);
      const be = t.querySelector('.btn-escenario');
      be.textContent = enAire ? 'Quitar del escenario' : 'Añadir al escenario'; be.className = be.className.replace(/bg-\S+/g, '') + (enAire ? ' bg-rose-600/90' : ' bg-[#1d4ed8]/90');
      if (!f.video) { be.disabled = true; be.textContent = 'Sin video'; } else be.disabled = false;
      if (matchMedia('(hover: none)').matches) be.classList.remove('opacity-0');
      be.onclick = () => alternarEscenario(f.id);
      t.querySelector('.menu-tile').onclick = (ev) => { ev.stopPropagation(); menuTile(f, principal === f.id); };
      cont.appendChild(t);
    });
    const inv = document.createElement('button');
    inv.type = 'button'; inv.className = 'shrink-0 w-44 sm:w-52 rounded-xl border-2 border-dashed border-gray-300 bg-white/60 hover:bg-white grid place-items-center text-gray-600 py-4';
    inv.innerHTML = '<span class="flex flex-col items-center gap-2"><span class="h-12 w-20 rounded-full bg-white shadow-sm border border-gray-200 flex items-center justify-center gap-2">' + ic('pantalla') + ic('invitar') + '</span><span class="text-sm font-medium">Presentar o invitar</span></span>';
    inv.onclick = () => abrirModal('presentar');
    cont.appendChild(inv);
  }
  function menuTile(f, esPrincipal) {
    const opciones = [];
    if (!esPrincipal && f.video) opciones.push(['estrella', 'Hacer principal (la grande)', () => { const l = enEscenario().filter(x => x !== f.id); escena({ vacio: false, principal: f.id, visibles: [f.id, ...l] }); }]);
    opciones.push(['etiqueta', 'Mostrar su rótulo', () => { const p = personaDe(f.id); if (!p || !p.nombre) { pestana = 'personas'; pintarRiel(); pintarPanel(); return aviso('Escribe su nombre y cargo en Personas.'); } escena({ rotulo_de: f.id.replace(/#pantalla$/, '') }); }]);
    opciones.push(['lapiz', 'Editar nombre y cargo', () => { pestana = 'personas'; pintarRiel(); pintarPanel(); }]);
    if (f.local && !f.pantalla) opciones.push(['repetir', espejo ? 'Quitar el espejo de mi vista' : 'Ver en espejo', () => { espejo = !espejo; pintarBastidores(); }]);
    if (f.id === 'camara-principal#pantalla') opciones.push(['x', 'Dejar de presentar', alternarPantalla]);
    if (f.invitado) opciones.push(['x', 'Sacar de la transmisión', async () => { if (!confirm('¿Sacar a ' + f.nombre + ' de la transmisión?')) return; try { await pedir('POST', url('participantes/' + encodeURIComponent(f.id) + '/expulsar')); } catch (e) { fallo(e); } }]);
    abrirModal('opciones', { titulo: f.nombre, opciones });
  }

  // ------------------------------------------------ diseños
  function rectangulos(layout, n) {
    const R = (x, y, w, h) => ({ x, y, w, h });
    if (n <= 0) return [];
    if (layout === 'solo' || layout === 'cine' || n === 1) return [R(0, 0, 1280, 720)];
    if (layout === 'pip') return [R(0, 0, 1280, 720), R(888, 104, 352, 198)];
    if (layout === 'dos') return [R(16, 172, 618, 348), R(646, 172, 618, 348)];
    if (layout === 'lateral') return [R(19, 105, 850, 478), R(881, 105, 380, 478)];
    if (layout === 'lider') { const out = [R(56, 91, 900, 506)]; for (let i = 0; i < n - 1; i++) out.push(R(968, 91 + i * 172, 285, 160)); return out; }
    if (layout === 'barra') { const out = [R(222, 14, 836, 470)]; const k = n - 1, x0 = (1280 - (k * 210 + (k - 1) * 10)) / 2; for (let i = 0; i < k; i++) out.push(R(x0 + i * 220, 494, 210, 118)); return out; }
    const cols = n <= 4 ? 2 : 3, filas = Math.ceil(n / cols), w = cols === 2 ? 544 : 408, h = cols === 2 ? 306 : 230, out = [];
    for (let f = 0; f < filas; f++) { const enFila = Math.min(cols, n - f * cols), x0 = (1280 - (enFila * w + (enFila - 1) * 12)) / 2; for (let c = 0; c < enFila; c++) out.push(R(x0 + c * (w + 12), (720 - filas * h - (filas - 1) * 12) / 2 + f * (h + 12), w, h)); }
    return out;
  }
  const muestras = { solo: 1, dos: 2, cuadricula: 4, lider: 3, lateral: 2, barra: 4, pip: 2, cine: 1 };
  function pintarLayouts() {
    const actual = E().layout || 'solo';
    g('ev-layouts').innerHTML = LAYOUTS.map(([k, nombre]) => {
      const on = k === actual, rs = rectangulos(k, muestras[k]);
      const svg = '<svg viewBox="0 0 1280 720" class="h-7 w-12">' + rs.map((r, i) => '<rect x="' + r.x + '" y="' + r.y + '" width="' + r.w + '" height="' + r.h + '" rx="' + (r.w > 1000 ? 0 : 40) + '" fill="' + (on ? (i ? '#93c5fd' : '#1d4ed8') : (i ? '#d1d5db' : '#9ca3af')) + '" stroke="#fff" stroke-width="' + (k === 'pip' && i ? 24 : 0) + '"/>').join('') + (k === 'cine' ? '<path d="M560 260l200 100-200 100z" fill="#fff"/>' : '') + '</svg>';
      return '<button type="button" data-layout="' + k + '" title="' + esc(nombre) + '" class="shrink-0 h-11 px-2.5 rounded-lg border-2 ' + (on ? 'border-[#1d4ed8] bg-blue-50' : 'border-transparent bg-white hover:border-gray-300') + ' grid place-items-center shadow-sm">' + svg + '</button>';
    }).join('');
    g('ev-layouts').querySelectorAll('[data-layout]').forEach(b => b.onclick = () => {
      const k = b.dataset.layout, l = enEscenario();
      escena({ layout: k, vacio: false, visibles: l.length ? l : fuentes().filter(f => f.video).map(f => f.id).slice(0, CUPOS[k]), principal: l[0] || E().principal || 'camara-principal' });
    });
    const bn = g('btn-nombres'), on = PL().mostrar_nombres !== false;
    bn.classList.toggle('bg-blue-50', on); bn.classList.toggle('text-[#1d4ed8]', on); bn.classList.toggle('border-[#1d4ed8]', on);
    bn.title = on ? 'Ocultar los nombres sobre las cámaras' : 'Mostrar los nombres sobre las cámaras';
  }
  g('btn-nombres').onclick = () => guardarMarca({ mostrar_nombres: PL().mostrar_nombres === false });

  // ------------------------------------------------ escena, plantilla y marca
  async function escena(cambios) {
    try { const j = await pedir('POST', url('escena'), cambios); T.escena = j.escena; pintarTodo(); return j; } catch (e) { fallo(e); }
  }
  async function plantilla(cambios) {
    try { const j = await pedir('POST', url('plantilla'), { plantilla: cambios }); T.plantilla = j.plantilla; pintarTodo(); } catch (e) { fallo(e); }
  }
  let guardando = Promise.resolve();
  function guardarMarca(cambios) {
    const m = marca(); if (!m.id) return;
    m.datos = Object.assign({}, m.datos, cambios);
    T.plantilla = Object.assign({}, T.plantilla, cambios);
    pintarTodo();
    guardando = guardando.then(async () => {
      try { const j = await pedir('POST', RUTAS.base + '/marcas/' + m.id, { datos: cambios, transmision_id: T.id }); Object.assign(m, j.marca); if (j.plantilla) T.plantilla = j.plantilla; pintarTodo(); }
      catch (e) { fallo(e); }
    });
    return guardando;
  }
  function pintarMarca() {
    const m = marca();
    g('marca-nombre').textContent = m.nombre;
    g('marca-punto').style.background = (m.datos && m.datos.color_etiqueta) || '#C8102E';
    const visible = ['banners', 'activos', 'estilo'].includes(pestana);
    g('panel-marca').classList.toggle('hidden', !visible); g('panel-titulo').classList.toggle('hidden', visible);
    g('panel-titulo').textContent = (PESTANAS.find(p => p[0] === pestana) || [])[1] || '';
  }
  g('btn-marca').onclick = (ev) => {
    ev.stopPropagation();
    const menu = g('menu-marca'), actual = marca();
    menu.innerHTML = MARCAS.map(m => '<button type="button" data-usar="' + m.id + '" class="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-left hover:bg-gray-50"><span class="h-3 w-3 rounded-full" style="background:' + esc(m.datos.color_etiqueta || '#C8102E') + '"></span><span class="flex-1 truncate">' + esc(m.nombre) + '</span>' + (m.id === actual.id ? '<span class="text-[#1d4ed8] text-xs font-bold">EN USO</span>' : '') + '</button>').join('')
      + '<div class="border-t border-gray-100 my-1"></div>'
      + '<button type="button" data-accion="nueva" class="w-full rounded-lg px-3 py-2 text-sm text-left hover:bg-gray-50">+ Nueva marca</button>'
      + '<button type="button" data-accion="duplicar" class="w-full rounded-lg px-3 py-2 text-sm text-left hover:bg-gray-50">Duplicar «' + esc(actual.nombre) + '»</button>'
      + '<button type="button" data-accion="renombrar" class="w-full rounded-lg px-3 py-2 text-sm text-left hover:bg-gray-50">Cambiar el nombre</button>'
      + (MARCAS.length > 1 ? '<button type="button" data-accion="borrar" class="w-full rounded-lg px-3 py-2 text-sm text-left text-rose-700 hover:bg-rose-50">Borrar esta marca</button>' : '');
    menu.classList.toggle('hidden');
    menu.onclick = async (e) => {
      const b = e.target.closest('button'); if (!b) return;
      menu.classList.add('hidden');
      try {
        if (b.dataset.usar) { const j = await pedir('POST', url('marca'), { marca_id: Number(b.dataset.usar) }); T.plantilla = j.plantilla; T.marca_id = Number(b.dataset.usar); }
        const ac = b.dataset.accion;
        if (ac === 'nueva' || ac === 'duplicar') {
          const nombre = prompt('Nombre de la marca:', ac === 'duplicar' ? actual.nombre + ' (copia)' : 'Marca ' + (MARCAS.length + 1)); if (nombre === null) return;
          const j = await pedir('POST', RUTAS.base + '/marcas', { nombre, copiar_de: ac === 'duplicar' ? actual.id : null }); MARCAS.push(j.marca);
          const k = await pedir('POST', url('marca'), { marca_id: j.marca.id }); T.plantilla = k.plantilla; T.marca_id = j.marca.id;
        }
        if (ac === 'renombrar') { const nombre = prompt('Nuevo nombre:', actual.nombre); if (!nombre) return; const j = await pedir('POST', RUTAS.base + '/marcas/' + actual.id, { nombre }); Object.assign(actual, j.marca); }
        if (ac === 'borrar') {
          if (!confirm('¿Borrar la marca «' + actual.nombre + '»?')) return;
          await pedir('DELETE', RUTAS.base + '/marcas/' + actual.id); MARCAS = MARCAS.filter(m => m.id !== actual.id);
          const k = await pedir('POST', url('marca'), { marca_id: MARCAS[0].id }); T.plantilla = k.plantilla; T.marca_id = MARCAS[0].id;
        }
        pintarTodo();
      } catch (er) { fallo(er); }
    };
  };
  document.addEventListener('click', (e) => { if (!e.target.closest('#panel-marca')) g('menu-marca').classList.add('hidden'); });

  // ------------------------------------------------ subir archivos propios
  let subiendo = null;
  function subirArchivo(tipo) {
    const inp = g('subir-archivo');
    inp.accept = { logo: 'image/*', superposicion: 'image/png,image/webp,image/gif', fondo: 'image/*', clip: 'video/mp4,video/webm,video/quicktime', sonido: 'audio/*', musica: 'audio/*' }[tipo];
    inp.value = '';
    inp.onchange = async () => {
      const f = inp.files[0]; if (!f) return;
      let duracion = null;
      if (tipo === 'clip' || tipo === 'musica' || tipo === 'sonido') duracion = await new Promise(ok => { const m = document.createElement(tipo === 'clip' ? 'video' : 'audio'); m.preload = 'metadata'; m.onloadedmetadata = () => { ok(isFinite(m.duration) ? Math.round(m.duration) : null); URL.revokeObjectURL(m.src); }; m.onerror = () => ok(null); m.src = URL.createObjectURL(f); });
      const fd = new FormData(); fd.append('archivo', f); fd.append('tipo', tipo); if (duracion) fd.append('duracion', duracion);
      subiendo = { tipo, nombre: f.name, avance: 0 }; pintarPanel();
      const x = new XMLHttpRequest(); x.open('POST', RUTAS.base + '/activos'); x.setRequestHeader('X-CSRF-TOKEN', csrf); x.setRequestHeader('Accept', 'application/json');
      x.upload.onprogress = (e) => { if (e.lengthComputable && subiendo) { subiendo.avance = Math.round(100 * e.loaded / e.total); const b = g('barra-subida'); if (b) b.style.width = subiendo.avance + '%'; } };
      x.onload = () => {
        subiendo = null; let j = null; try { j = JSON.parse(x.responseText); } catch (e) {}
        if (x.status === 413) fallo(new Error('El archivo supera el tamaño que permite el servidor.'));
        else if (!j || j.success === false || x.status >= 400) fallo(new Error((j && (j.error || j.message)) || ('No se pudo subir (HTTP ' + x.status + ')')));
        else { ACTIVOS.unshift(j.activo); aviso('Archivo subido'); if (tipo === 'logo') guardarMarca({ logo_url: j.activo.url }); if (tipo === 'fondo') guardarMarca({ fondo: { tipo: 'imagen', valor: j.activo.url } }); }
        pintarPanel();
      };
      x.onerror = () => { subiendo = null; fallo(new Error('Se cortó la subida. Revisa tu conexión.')); pintarPanel(); };
      x.send(fd);
    };
    inp.click();
  }
  async function borrarActivo(id) {
    if (!confirm('¿Borrar este archivo?')) return;
    try { await pedir('DELETE', RUTAS.base + '/activos/' + id); ACTIVOS = ACTIVOS.filter(a => a.id !== id); pintarPanel(); } catch (e) { fallo(e); }
  }

  // ------------------------------------------------ vista previa local de sonidos y música (no sale al aire)
  let actx = null, previa = null;
  const ctxLocal = () => { if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)(); if (actx.state === 'suspended') actx.resume(); return actx; };
  function escuchar(tipo, clave, urlArchivo) {
    pararPrevia();
    if (urlArchivo) { const a = new Audio(urlArchivo); a.volume = 0.6; a.play().catch(() => {}); previa = { parar: () => a.pause() }; setTimeout(pararPrevia, 12000); return; }
    if (!window.EditusAudio) return;
    if (tipo === 'efecto') { const c = ctxLocal(), gn = c.createGain(); gn.gain.value = 0.6; gn.connect(c.destination); window.EditusAudio.efecto(c, gn, clave); return; }
    const c = ctxLocal(), gn = c.createGain(); gn.gain.value = 0.5; gn.connect(c.destination);
    const m = new window.EditusAudio.Musica(c, gn, clave); m.iniciar(); previa = { parar: () => m.detener() }; setTimeout(pararPrevia, 10000);
  }
  function pararPrevia() { if (previa) { previa.parar(); previa = null; } }

  // ------------------------------------------------ panel lateral
  const PESTANAS = [['comentarios', 'Comentarios'], ['banners', 'Banners'], ['activos', 'Activos multimedia'], ['estilo', 'Estilo'], ['widgets', 'Widgets'], ['notas', 'Notas'], ['personas', 'Personas'], ['chat', 'Chat privado']];
  function pintarRiel() {
    g('riel').innerHTML = PESTANAS.map(([k, nombre]) => {
      const on = k === pestana, badge = k === 'chat' && sinLeer ? '<span class="absolute right-2 top-1 h-4 min-w-4 px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold grid place-items-center">' + sinLeer + '</span>' : '';
      return '<button type="button" data-pestana="' + k + '" class="relative shrink-0 lg:w-full flex flex-col items-center justify-center gap-1 rounded-xl px-3 lg:px-1 py-2 text-[11px] lg:text-xs leading-tight text-center ' + (on ? 'bg-blue-50 text-[#1d4ed8] font-semibold' : 'text-gray-600 hover:bg-gray-50') + '">' + ic(k, 'h-6 w-6') + '<span class="whitespace-nowrap lg:whitespace-normal">' + esc(nombre) + '</span>' + badge + '</button>';
    }).join('');
    g('riel').querySelectorAll('[data-pestana]').forEach(b => b.onclick = () => { pestana = b.dataset.pestana; if (pestana === 'chat') sinLeer = 0; pintarRiel(); pintarPanel(); revisarComentarios(); });
  }
  const seccion = (titulo, contenido, ayuda) => '<section class="mb-6"><h4 class="flex items-center gap-1.5 text-[15px] font-bold text-gray-900 mb-3">' + esc(titulo) + (ayuda ? '<span class="text-gray-400 cursor-help" title="' + esc(ayuda) + '">ⓘ</span>' : '') + '</h4>' + contenido + '</section>';
  const mosaico = (items) => '<div class="grid grid-cols-3 gap-2">' + items.join('') + '</div>';
  const tarjeta = ({ attrs = '', on = false, fondo = '', cont = '', nombre = '', extra = '' }) => '<div class="relative min-w-0"><button type="button" ' + attrs + ' class="relative w-full aspect-video rounded-lg overflow-hidden border-2 ' + (on ? 'border-[#1d4ed8] ring-2 ring-blue-200' : 'border-gray-200 hover:border-gray-400') + '" style="' + fondo + '">' + cont + (on ? '<span class="absolute left-1 top-1 rounded bg-[#1d4ed8] text-white text-[9px] font-bold px-1">EN USO</span>' : '') + '</button>' + (nombre ? '<div class="mt-1 text-[11px] text-gray-600 truncate" title="' + esc(nombre) + '">' + esc(nombre) + '</div>' : '') + extra + '</div>';
  const mas = (tipo, texto) => '<div class="min-w-0"><button type="button" data-subir="' + tipo + '" class="w-full aspect-video rounded-lg border-2 border-dashed border-gray-300 hover:border-[#1d4ed8] hover:text-[#1d4ed8] text-gray-500 grid place-items-center">' + ic('mas', 'h-6 w-6') + '</button><div class="mt-1 text-[11px] text-gray-500 truncate">' + esc(texto || 'Subir') + '</div></div>';
  const borrar = (id) => '<button type="button" data-borrar-activo="' + id + '" class="absolute -right-1 -top-1 h-5 w-5 rounded-full bg-white border border-gray-200 text-gray-500 hover:text-rose-600 grid place-items-center shadow-sm" title="Borrar">' + ic('x', 'h-3 w-3') + '</button>';
  const propios = (tipo) => ACTIVOS.filter(a => a.tipo === tipo);
  const barraSubida = (tipo) => subiendo && subiendo.tipo === tipo ? '<div class="mb-3 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-900">Subiendo ' + esc(subiendo.nombre) + '…<div class="mt-1 h-1.5 rounded bg-blue-100"><div id="barra-subida" class="h-1.5 rounded bg-[#1d4ed8]" style="width:' + subiendo.avance + '%"></div></div></div>' : '';
  const interruptor = (id, on, texto, sub) => '<label class="flex items-start gap-3 py-1.5 cursor-pointer"><input type="checkbox" id="' + id + '" ' + (on ? 'checked' : '') + ' class="mt-0.5 h-5 w-5 rounded border-gray-300 text-[#1d4ed8]"><span><span class="block text-sm text-gray-800">' + texto + '</span>' + (sub ? '<span class="block text-xs text-gray-500">' + sub + '</span>' : '') + '</span></label>';

  function panelActivos() {
    const p = PL(), m = marca().datos || {}, e = E(), rec = e.recurso || null, color = m.color_etiqueta || '#C8102E';
    let h = '';
    // Logo
    const logos = propios('logo');
    h += seccion('Logo', PERMITE_LOGO
      ? barraSubida('logo') + mosaico([...logos.map(a => tarjeta({ attrs: 'data-logo="' + esc(a.url) + '"', on: p.logo_url === a.url, fondo: 'background:#f3f4f6', cont: '<img src="' + esc(a.url) + '" class="absolute inset-0 w-full h-full object-contain p-2" alt="">', extra: borrar(a.id) })), mas('logo', 'Subir logo')])
        + '<div class="mt-3 flex items-center gap-3"><span class="text-sm text-gray-700">Ubicación</span><div class="inline-flex rounded-lg border border-gray-200 p-0.5">'
        + ['izq', 'der'].map(k => '<button type="button" data-logo-pos="' + k + '" class="h-9 w-12 rounded-md grid place-items-center ' + ((p.logo_pos || 'izq') === k ? 'bg-blue-50 text-[#1d4ed8]' : 'text-gray-500') + '"><svg viewBox="0 0 32 20" class="h-5 w-8"><rect x="1" y="1" width="30" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><rect x="' + (k === 'izq' ? 4 : 20) + '" y="4" width="8" height="5" rx="1" fill="currentColor"/></svg></button>').join('') + '</div></div>'
        + '<div class="mt-3"><label class="block text-xs text-gray-500 mb-1">O un logo de texto (si no hay imagen)</label><input id="logo-texto" maxlength="40" value="' + esc(p.logo_texto || '') + '" placeholder="OPA Noticias" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div>'
      : '<p class="text-xs text-gray-500 rounded-lg bg-amber-50 border border-amber-200 p-3">El logo está disponible en el plan Full.</p>', 'Va en una esquina durante toda la transmisión');
    // Superposición
    const sp = p.superposicion || null;
    const preSp = Object.entries(SUPERPOSICIONES).map(([k, n]) => tarjeta({ attrs: 'data-sp="' + k + '"', on: sp && sp.tipo === 'preset' && sp.valor === k, fondo: 'background:linear-gradient(135deg,#e5e7eb,#d1d5db)', nombre: n, cont: miniSuperposicion(k, color) }));
    const pngSp = propios('superposicion').map(a => tarjeta({ attrs: 'data-sp-img="' + esc(a.url) + '"', on: sp && sp.tipo === 'imagen' && sp.valor === a.url, fondo: 'background:repeating-conic-gradient(#e5e7eb 0 25%,#fff 0 50%) 0 0/16px 16px', nombre: a.nombre, cont: '<img src="' + esc(a.url) + '" class="absolute inset-0 w-full h-full object-contain" alt="">', extra: borrar(a.id) }));
    h += seccion('Superposición', interruptor('sp-suave', p.desvanecer !== false, 'Desvanecer al mostrar u ocultar') + barraSubida('superposicion') + '<div class="mt-2">' + mosaico([...preSp, ...pngSp, PERMITE_LOGO ? mas('superposicion', 'Subir PNG') : '']) + '</div>'
      + (sp && sp.tipo === 'preset' ? '<div class="mt-3"><label class="block text-xs text-gray-500 mb-1">Texto de la superposición</label><div class="flex gap-2"><input id="sp-texto" maxlength="80" value="' + esc(sp.texto || '') + '" placeholder="' + esc(SUPERPOSICIONES[sp.valor]) + '" class="flex-1 min-w-0 h-9 rounded-lg border-gray-200 text-sm"><button type="button" id="sp-texto-ok" class="h-9 rounded-lg bg-gray-900 text-white px-3 text-xs font-semibold">Aplicar</button></div></div>' : ''),
      'Capa encima de las cámaras. Toca una para mostrarla; tócala otra vez para quitarla');
    // Clips de video y pantallas
    const clips = [
      ...PANTALLAS.map((c, i) => tarjeta({ attrs: 'data-pantalla="' + i + '"', on: rec && rec.tipo === 'pantalla' && rec.preset === c.preset && (rec.duracion || 0) === (c.duracion || (c.preset === 'temporizador' ? 10 : 0)) && !finRecurso(rec), fondo: 'background:linear-gradient(140deg,' + color + ',#0b1c33 80%)', nombre: c.nombre,
        cont: '<span class="absolute inset-0 grid place-items-center text-white text-[10px] font-bold px-1 text-center leading-tight">' + (c.preset === 'temporizador' ? '<span class="text-lg">' + c.duracion + '</span>' : esc(c.nombre)) + '</span>' + (c.duracion ? '<span class="absolute left-1 bottom-1 rounded bg-black/60 text-white text-[10px] px-1">' + (c.duracion >= 60 ? (c.duracion / 60) + ':00' : '0:' + String(c.duracion).padStart(2, '0')) + '</span>' : '') })),
      ...propios('clip').map(a => tarjeta({ attrs: 'data-clip="' + a.id + '"', on: rec && rec.id === 'activo-' + a.id && !finRecurso(rec), fondo: 'background:#111', nombre: a.nombre,
        cont: '<video src="' + esc(a.url) + '#t=1" muted preload="metadata" class="absolute inset-0 w-full h-full object-cover"></video>' + (a.duracion ? '<span class="absolute left-1 bottom-1 rounded bg-black/60 text-white text-[10px] px-1">' + Math.floor(a.duracion / 60) + ':' + String(a.duracion % 60).padStart(2, '0') + '</span>' : ''), extra: borrar(a.id) })),
      ...RECURSOS.filter(r => r.uso !== 'plantilla').map(r => tarjeta({ attrs: 'data-recurso="' + r.id + '"', on: rec && rec.id === r.id && !finRecurso(rec), fondo: 'background:#111', nombre: r.nombre + ' · redacción',
        cont: r.tipo === 'video' ? '<video src="' + esc(r.url) + '#t=1" muted preload="metadata" class="absolute inset-0 w-full h-full object-cover"></video>' : '<img src="' + esc(r.url) + '" class="absolute inset-0 w-full h-full object-cover" alt="">' })),
      mas('clip', 'Subir video'),
    ];
    h += seccion('Clips de video', barraSubida('clip') + mosaico(clips)
      + '<div class="mt-2 flex items-center justify-between gap-2">' + interruptor('clip-bucle', bucle, 'Bucle', 'El video se repite hasta que lo quites') + (rec && !finRecurso(rec) ? '<button type="button" id="quitar-clip" class="h-9 shrink-0 rounded-lg bg-rose-600 text-white px-3 text-xs font-semibold">Quitar del aire</button>' : '') + '</div>',
      'Ocupan toda la pantalla: intros, cortinillas, comerciales y pantallas de espera. Las cámaras se silencian mientras tanto');
    // Fondo
    const fo = p.fondo || { tipo: 'preset', valor: 'noche' };
    h += seccion('Fondo', barraSubida('fondo') + mosaico([
      ...Object.entries(FONDOS).map(([k, [n, css]]) => tarjeta({ attrs: 'data-fondo="' + k + '"', on: fo.tipo === 'preset' && fo.valor === k, fondo: 'background:' + css, nombre: n })),
      ...propios('fondo').map(a => tarjeta({ attrs: 'data-fondo-img="' + esc(a.url) + '"', on: fo.tipo === 'imagen' && fo.valor === a.url, fondo: 'background:center/cover url(&quot;' + esc(a.url) + '&quot;)', nombre: a.nombre, extra: borrar(a.id) })),
      mas('fondo', 'Subir imagen'),
    ]) + '<div class="mt-3 flex items-center gap-2"><label class="text-xs text-gray-500">Color sólido</label><input type="color" id="fondo-color" value="' + (fo.tipo === 'color' ? fo.valor : '#0B1C33') + '" class="h-8 w-14 rounded border-gray-200"></div>', 'Se ve detrás de las cámaras en los diseños de varias personas');
    // Sonidos
    const volS = typeof e.vol_sonidos === 'number' ? e.vol_sonidos : 0.8;
    h += seccion('Sonidos', '<div class="flex items-center gap-3 mb-3 text-[#1d4ed8]">' + ic('volumen') + '<input type="range" min="0" max="1" step="0.05" value="' + volS + '" id="vol-sonidos" class="flex-1 accent-[#1d4ed8]"></div>' + barraSubida('sonido')
      + '<div class="grid grid-cols-2 gap-2">' + [...Object.entries(EFECTOS).map(([k, n]) => botonSonido('data-efecto="' + k + '"', n, 'data-oir-efecto="' + k + '"')), ...propios('sonido').map(a => botonSonido('data-efecto-archivo="' + a.id + '"', a.nombre, 'data-oir-url="' + esc(a.url) + '"', a.id))].join('')
      + '<button type="button" data-subir="sonido" class="h-14 rounded-xl border border-dashed border-gray-300 text-sm text-gray-600 hover:border-[#1d4ed8] hover:text-[#1d4ed8] flex items-center justify-center gap-2">' + ic('subir', 'h-4 w-4') + 'Subir sonido</button></div>',
      'Suenan una vez en la transmisión. El audífono (🎧) es solo para que lo escuches tú');
    // Música de fondo
    const mu = e.musica || null, volM = typeof e.vol_musica === 'number' ? e.vol_musica : 0.35;
    const nombreMu = mu ? (mu.preset ? (MUSICAS[mu.preset] || mu.preset) : (mu.nombre || 'Pista')) : null;
    h += seccion('Música de fondo', '<div class="rounded-xl bg-gray-50 border border-gray-200 p-3 mb-3"><div class="flex items-center gap-3"><button type="button" id="musica-alternar" class="h-10 w-10 shrink-0 rounded-full ' + (mu ? 'bg-[#1d4ed8] text-white' : 'bg-white border border-gray-200 text-gray-400') + ' grid place-items-center">' + ic(mu ? 'stop' : 'play', 'h-4 w-4') + '</button><div class="min-w-0"><div class="text-sm font-semibold text-gray-800 truncate">' + (mu ? esc(nombreMu) : 'Ninguna pista seleccionada') + '</div><div class="text-xs text-gray-500">' + (mu ? 'Sonando en la transmisión' : 'Elige una pista abajo para comenzar') + '</div></div></div>'
      + '<div class="mt-3 flex items-center gap-3 text-gray-600">' + ic('volumen') + '<input type="range" min="0" max="1" step="0.05" value="' + volM + '" id="vol-musica" class="flex-1 accent-[#1d4ed8]"></div></div>' + barraSubida('musica')
      + '<div class="grid grid-cols-2 gap-2">' + [...Object.entries(MUSICAS).map(([k, n]) => botonMusica('data-musica="' + k + '"', n, mu && mu.preset === k, 'data-oir-musica="' + k + '"')), ...propios('musica').map(a => botonMusica('data-musica-archivo="' + a.id + '"', a.nombre, mu && mu.id === 'activo-' + a.id, 'data-oir-url="' + esc(a.url) + '"', a.id))].join('')
      + '<button type="button" data-subir="musica" class="h-14 rounded-xl border border-dashed border-gray-300 text-sm text-gray-600 hover:border-[#1d4ed8] hover:text-[#1d4ed8] flex items-center justify-center gap-2">' + ic('subir', 'h-4 w-4') + 'Subir música</button></div>'
      + '<p class="mt-2 text-[11px] text-gray-500">Las pistas incluidas se generan en el momento, sin derechos de autor. Si subes música, que sea tuya o libre de derechos: Facebook y YouTube silencian o bajan los videos con música protegida.</p>', 'Suena de fondo, a bajo volumen, en la transmisión');
    return h;
  }
  const finRecurso = (r) => r && r.duracion && r.inicio && Date.now() - r.inicio > r.duracion * 1000 + 1500 && !r.bucle;
  function miniSuperposicion(k, color) {
    const pos = { suscribete: 'right:6%;bottom:14%;width:44%;height:14%;border-radius:9px;background:#fff', bienvenidos: 'left:22%;top:38%;width:56%;height:22%;border-radius:4px;background:' + color, preguntas: 'left:6%;top:16%;width:42%;height:14%;border-radius:3px;background:' + color,
      ultima: 'left:0;top:16%;width:58%;height:14%;background:#e11d48', logro: 'right:6%;top:14%;width:28%;height:34%;border-radius:5px;background:#fbbf24', redes: 'left:30%;top:6%;width:40%;height:12%;border-radius:9px;background:rgba(0,0,0,.6)' };
    return '<span class="absolute" style="' + pos[k] + '"></span>';
  }
  const botonSonido = (attr, nombre, oir, id) => '<div class="relative h-14 rounded-xl border border-gray-200 bg-gray-50 flex items-center"><button type="button" ' + attr + ' class="flex-1 min-w-0 h-full flex items-center gap-2 pl-2 pr-1 text-left rounded-xl hover:bg-gray-100"><span class="h-8 w-8 shrink-0 rounded-full bg-white border border-gray-200 grid place-items-center text-gray-600">' + ic('play', 'h-3.5 w-3.5') + '</span><span class="text-[13px] leading-tight text-gray-800 line-clamp-2">' + esc(nombre) + '</span></button><button type="button" ' + oir + ' title="Escuchar (solo tú)" class="h-full px-2 text-gray-400 hover:text-gray-700">' + ic('oir', 'h-4 w-4') + '</button>' + (id ? borrar(id) : '') + '</div>';
  const botonMusica = (attr, nombre, on, oir, id) => '<div class="relative h-14 rounded-xl border ' + (on ? 'border-[#1d4ed8] bg-blue-50' : 'border-gray-200 bg-gray-50') + ' flex items-center"><button type="button" ' + attr + ' class="flex-1 min-w-0 h-full flex items-center gap-2 pl-2 pr-1 text-left rounded-xl hover:bg-gray-100"><span class="h-8 w-8 shrink-0 rounded-full ' + (on ? 'bg-[#1d4ed8] text-white' : 'bg-white border border-gray-200 text-gray-600') + ' grid place-items-center">♫</span><span class="text-[13px] leading-tight text-gray-800 line-clamp-2">' + esc(nombre) + '</span></button><button type="button" ' + oir + ' title="Escuchar (solo tú)" class="h-full px-2 text-gray-400 hover:text-gray-700">' + ic('oir', 'h-4 w-4') + '</button>' + (id ? borrar(id) : '') + '</div>';

  function panelEstilo() {
    const p = PL(), color = p.color_etiqueta || '#C8102E';
    let h = seccion('Color de la marca', '<div class="flex flex-wrap gap-2">' + COLORES.map(c => '<button type="button" data-color="' + c + '" class="h-8 w-8 rounded-full border-2 ' + (c.toUpperCase() === color.toUpperCase() ? 'border-gray-900 ring-2 ring-offset-1 ring-gray-300' : 'border-white shadow') + '" style="background:' + c + '"></button>').join('') + '<label class="h-8 w-8 rounded-full border-2 border-dashed border-gray-300 grid place-items-center cursor-pointer overflow-hidden relative" title="Otro color">' + ic('mas', 'h-4 w-4') + '<input type="color" id="color-libre" value="' + color + '" class="absolute inset-0 opacity-0 cursor-pointer"></label></div>', 'Etiquetas, banners, rótulos y nombres');
    h += seccion('Tema', '<div class="grid grid-cols-2 gap-2">' + Object.entries(TEMAS).map(([k, n]) => '<button type="button" data-tema="' + k + '" class="rounded-xl border-2 p-2 text-left ' + ((p.tema || 'clasico') === k ? 'border-[#1d4ed8] bg-blue-50' : 'border-gray-200 hover:border-gray-400') + '"><div class="h-14 rounded-lg bg-gradient-to-br from-slate-500 to-slate-800 relative overflow-hidden">' + miniTema(k, color) + '</div><div class="mt-1.5 text-sm font-medium">' + n + '</div></button>').join('') + '</div>');
    h += seccion('Fuente', '<div class="grid grid-cols-2 gap-2">' + Object.entries(FUENTES).map(([k, [n, css]]) => '<button type="button" data-fuente="' + k + '" class="rounded-xl border-2 px-3 py-2.5 text-left ' + ((p.fuente || 'inter') === k ? 'border-[#1d4ed8] bg-blue-50' : 'border-gray-200 hover:border-gray-400') + '"><span class="block text-lg font-bold" style="font-family:' + css + '">Aa</span><span class="block text-xs text-gray-600">' + n + '</span></button>').join('') + '</div>');
    h += seccion('En pantalla', interruptor('est-nombres', p.mostrar_nombres !== false, 'Nombres sobre las cámaras', 'En los diseños de varias personas')
      + interruptor('est-vivo', p.en_vivo !== false, 'Indicador «EN VIVO»', 'Arriba, en la esquina')
      + '<div class="grid grid-cols-2 gap-2 mt-2"><div class="col-span-2"><label class="block text-xs text-gray-500 mb-1">Etiqueta del titular</label><input id="est-etiqueta" maxlength="30" value="' + esc(p.etiqueta || '') + '" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div>'
      + '<div><label class="block text-xs text-gray-500 mb-1">Pie (izquierda)</label><input id="est-pie" maxlength="60" value="' + esc(p.pie || '') + '" placeholder="www.tumedio.com" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div>'
      + '<div><label class="block text-xs text-gray-500 mb-1">Hashtag (derecha)</label><input id="est-hashtag" maxlength="40" value="' + esc(p.hashtag || '') + '" placeholder="#Huila" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div></div>'
      + '<button type="button" id="est-guardar" class="mt-3 h-9 rounded-lg bg-gray-900 text-white px-4 text-xs font-semibold">Guardar textos</button>');
    return h;
  }
  function miniTema(k, color) {
    const base = 'position:absolute;left:8px;bottom:8px;height:16px;width:70%;';
    if (k === 'burbuja') return '<span style="' + base + 'background:#fff;border-radius:999px"></span><span style="position:absolute;left:8px;bottom:26px;height:9px;width:34%;background:' + color + ';border-radius:999px"></span>';
    if (k === 'minimal') return '<span style="' + base + 'background:rgba(0,0,0,.55);border-left:4px solid ' + color + '"></span>';
    if (k === 'bloque') return '<span style="' + base + 'background:' + color + ';border:2px solid #fff"></span><span style="position:absolute;left:8px;bottom:26px;height:9px;width:30%;background:#111"></span>';
    return '<span style="' + base + 'background:#fff"></span><span style="position:absolute;left:8px;bottom:24px;height:9px;width:30%;background:' + color + '"></span>';
  }

  function panelBanners() {
    const p = PL(), e = E(), m = marca(), banners = (m.datos && m.datos.banners) || [], actual = e.banner || null;
    let h = seccion('Titular de la transmisión', '<input id="ban-titular" maxlength="140" value="' + esc(p.titulo || '') + '" class="w-full h-10 rounded-lg border-gray-200 text-sm" placeholder="Título grande abajo">'
      + '<div class="mt-2 flex items-center justify-between">' + interruptor('ban-mostrar', p.mostrar !== false, 'Mostrar el titular') + '<button type="button" id="ban-titular-ok" class="h-9 rounded-lg bg-gray-900 text-white px-3 text-xs font-semibold">Aplicar</button></div>', 'La etiqueta (EN VIVO, ÚLTIMA HORA…) se cambia en Estilo');
    h += seccion('Banners', (banners.length ? '<div class="space-y-2">' + banners.map(b => {
      const on = actual && actual.texto === b.texto && (actual.titulo || '') === (b.titulo || '') && !!actual.cinta === !!b.cinta;
      return '<div class="group relative rounded-xl border-2 ' + (on ? 'border-[#1d4ed8] bg-blue-50' : 'border-gray-200 hover:border-gray-300') + '"><button type="button" data-banner="' + esc(b.id) + '" class="w-full text-left px-3 py-2.5 pr-16">' + (b.titulo ? '<span class="inline-block rounded px-1.5 py-0.5 text-[10px] font-bold text-white mb-1" style="background:' + esc(p.color_etiqueta || '#C8102E') + '">' + esc(b.titulo) + '</span>' : '') + '<span class="block text-sm text-gray-800">' + esc(b.texto) + '</span>' + (b.cinta ? '<span class="mt-1 inline-flex items-center gap-1 text-[11px] text-gray-500">' + ic('repetir', 'h-3 w-3') + ' Cinta que corre</span>' : '') + '</button>'
        + '<div class="absolute right-2 top-2 flex gap-1">' + (on ? '<span class="rounded bg-[#1d4ed8] text-white text-[10px] font-bold px-1.5 py-0.5">AL AIRE</span>' : '') + '<button type="button" data-editar-banner="' + esc(b.id) + '" class="h-6 w-6 rounded text-gray-400 hover:text-gray-700 grid place-items-center">' + ic('lapiz', 'h-3.5 w-3.5') + '</button><button type="button" data-borrar-banner="' + esc(b.id) + '" class="h-6 w-6 rounded text-gray-400 hover:text-rose-600 grid place-items-center">' + ic('basura', 'h-3.5 w-3.5') + '</button></div></div>';
    }).join('') + '</div>' : '<p class="text-sm text-gray-500 mb-2">Crea textos para mostrar abajo: preguntas, datos, llamados a la acción…</p>')
      + '<div class="mt-3 rounded-xl border border-gray-200 p-3 bg-gray-50"><input id="ban-titulo" maxlength="60" placeholder="Etiqueta (opcional): AHORA, DATO, PREGUNTA…" class="w-full h-9 rounded-lg border-gray-200 text-sm mb-2"><textarea id="ban-texto" maxlength="300" rows="2" placeholder="Texto del banner" class="w-full rounded-lg border-gray-200 text-sm"></textarea>'
      + '<div class="flex items-center justify-between gap-2 mt-1">' + interruptor('ban-cinta', false, 'Cinta que corre', 'Como el ticker de los noticieros') + '<button type="button" id="ban-crear" class="h-9 shrink-0 rounded-lg bg-[#1d4ed8] text-white px-3 text-xs font-semibold">+ Crear banner</button></div></div>'
      + (actual ? '<button type="button" id="ban-quitar" class="mt-3 w-full h-9 rounded-lg border border-rose-200 text-rose-700 text-xs font-semibold hover:bg-rose-50">Ocultar el banner</button>' : ''), 'Toca un banner para mostrarlo; tócalo otra vez para ocultarlo');
    return h;
  }

  function panelComentarios() {
    const e = E(), actual = e.comentario || null;
    if (!T || T.estado !== 'en_vivo') return '<div class="text-center py-10 px-4"><div class="mx-auto h-12 w-12 rounded-full bg-gray-100 grid place-items-center text-gray-500 mb-3">' + ic('comentarios') + '</div><p class="text-sm font-semibold text-gray-800">Los comentarios aparecen cuando estés al aire</p><p class="text-xs text-gray-500 mt-1">Verás los de Facebook y YouTube juntos. Toca uno para mostrarlo en la pantalla.</p></div>' + comentarioPropio();
    let h = '';
    if (avisosComentarios.length) h += '<div class="mb-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 text-xs p-2.5">' + avisosComentarios.map(esc).join('<br>') + '</div>';
    if (actual) h += '<button type="button" id="com-quitar" class="mb-3 w-full h-9 rounded-lg bg-rose-600 text-white text-xs font-semibold">Quitar el comentario de la pantalla</button>';
    h += comentarios.length ? '<div class="space-y-2">' + comentarios.map((c, i) => {
      const on = actual && actual.texto === c.texto && actual.nombre === c.nombre;
      return '<button type="button" data-comentario="' + i + '" class="w-full text-left flex gap-2.5 rounded-xl border-2 p-2.5 ' + (on ? 'border-[#1d4ed8] bg-blue-50' : 'border-gray-100 hover:border-gray-300') + '">' + (c.foto ? '<img src="' + esc(c.foto) + '" class="h-9 w-9 rounded-full object-cover shrink-0" alt="">' : '<span class="h-9 w-9 shrink-0 rounded-full bg-gray-200 grid place-items-center text-gray-500 text-sm font-bold">' + esc((c.nombre || '?').charAt(0)) + '</span>')
        + '<span class="min-w-0"><span class="flex items-center gap-1.5"><span class="text-[13px] font-semibold text-gray-900 truncate">' + esc(c.nombre) + '</span><span class="rounded px-1 text-[9px] font-bold text-white" style="background:' + (c.red === 'youtube' ? '#FF0000' : '#1877F2') + '">' + (c.red === 'youtube' ? 'YT' : 'FB') + '</span></span><span class="block text-[13px] text-gray-700 break-words">' + esc(c.texto) + '</span></span></button>';
    }).join('') + '</div>' : '<p class="text-sm text-gray-500 py-6 text-center">Aún no hay comentarios. Se actualizan solos.</p>';
    return h + comentarioPropio();
  }
  const comentarioPropio = () => seccion('Destacar un mensaje propio', '<input id="com-nombre" maxlength="80" placeholder="Nombre (ej: Pregunta del público)" class="w-full h-9 rounded-lg border-gray-200 text-sm mb-2"><textarea id="com-texto" maxlength="500" rows="2" placeholder="Texto" class="w-full rounded-lg border-gray-200 text-sm"></textarea><button type="button" id="com-mostrar" class="mt-2 h-9 rounded-lg bg-gray-900 text-white px-3 text-xs font-semibold">Mostrar en pantalla</button>');

  function panelWidgets() {
    const w = E().widgets || {}, cu = w.cuenta || null, activa = cu && cu.fin > Date.now() - 4000;
    return seccion('Reloj', interruptor('w-reloj', !!w.reloj, 'Mostrar la hora', 'Hora de Colombia, junto al indicador EN VIVO'))
      + seccion('Cuenta regresiva', '<div class="grid grid-cols-[1fr_1fr] gap-2"><div><label class="block text-xs text-gray-500 mb-1">Minutos</label><input id="w-min" type="number" min="0" max="600" value="5" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div><div><label class="block text-xs text-gray-500 mb-1">Segundos</label><input id="w-seg" type="number" min="0" max="59" value="0" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div>'
        + '<div class="col-span-2"><label class="block text-xs text-gray-500 mb-1">Texto</label><input id="w-texto" maxlength="60" placeholder="Empezamos en" class="w-full h-9 rounded-lg border-gray-200 text-sm"></div></div>'
        + '<div class="mt-3 flex gap-2"><button type="button" id="w-iniciar" class="h-9 rounded-lg bg-[#1d4ed8] text-white px-4 text-xs font-semibold">' + (activa ? 'Reiniciar' : 'Iniciar') + '</button>' + (activa ? '<button type="button" id="w-parar" class="h-9 rounded-lg border border-rose-200 text-rose-700 px-4 text-xs font-semibold">Quitar</button>' : '') + '</div>', 'Arriba, al centro, con el color de la marca')
      + seccion('Pantallas completas', '<p class="text-sm text-gray-600">Los temporizadores de 10, 30 y 60 segundos y las pantallas de «Comenzará pronto», «Volvemos enseguida» y «Gracias» están en <button type="button" data-ir="activos" class="text-[#1d4ed8] font-semibold underline">Activos › Clips de video</button>.</p>');
  }

  function panelNotas() {
    return '<p class="text-xs text-gray-500 mb-2">Solo las ves tú. Se guardan solas. Úsalas como guion o teleprompter.</p>'
      + '<textarea id="notas" rows="16" class="w-full rounded-xl border-gray-200 text-sm leading-relaxed" placeholder="Puntos a tratar, preguntas para los invitados, datos…">' + esc(T.notas || '') + '</textarea>'
      + '<div class="mt-2 flex items-center justify-between"><span id="notas-estado" class="text-[11px] text-gray-400"></span><button type="button" id="notas-tp" class="h-9 rounded-lg bg-gray-900 text-white px-3 text-xs font-semibold">Abrir teleprompter</button></div>';
  }

  function personaDe(id) { const n = (E().nombres || {})[String(id).replace(/#pantalla$/, '')]; if (!n) return null; return Array.isArray(n.personas) ? (n.personas[n.activa || 0] || n.personas[0]) : n; }
  function panelPersonas() {
    const e = E(), lista = fuentes().filter(f => !f.pantalla), enE = enEscenario();
    let h = interruptor('rot-auto', !!e.rotulo_auto, 'Rótulo automático', 'Muestra el nombre de quien está hablando') + '<div class="mt-3 space-y-3">';
    h += lista.map(f => {
      const p = personaDe(f.id) || {}, rotulo = e.rotulo_de === f.id;
      return '<div class="rounded-xl border border-gray-200 p-3"><div class="flex items-center gap-2 mb-2"><span class="h-2.5 w-2.5 rounded-full ' + (enE.includes(f.id) ? 'bg-rose-600' : 'bg-gray-300') + '"></span><span class="text-sm font-semibold truncate flex-1">' + esc(f.nombre) + '</span>' + ic(f.audio ? 'mic' : 'micOff', 'h-4 w-4 ' + (f.audio ? 'text-gray-500' : 'text-rose-600')) + ic(f.video ? 'video' : 'videoOff', 'h-4 w-4 ' + (f.video ? 'text-gray-500' : 'text-rose-600')) + '</div>'
        + '<div class="grid grid-cols-2 gap-2"><input data-p-nombre="' + esc(f.id) + '" maxlength="60" value="' + esc(p.nombre || '') + '" placeholder="Nombre" class="h-9 rounded-lg border-gray-200 text-sm"><input data-p-cargo="' + esc(f.id) + '" maxlength="60" value="' + esc(p.cargo || '') + '" placeholder="Cargo" class="h-9 rounded-lg border-gray-200 text-sm"></div>'
        + '<div class="mt-2 flex flex-wrap gap-1.5"><button type="button" data-p-guardar="' + esc(f.id) + '" class="h-8 rounded-lg bg-gray-900 text-white px-3 text-xs font-semibold">Guardar</button><button type="button" data-p-rotulo="' + esc(f.id) + '" class="h-8 rounded-lg px-3 text-xs font-semibold ' + (rotulo ? 'bg-[#1d4ed8] text-white' : 'border border-gray-200 text-gray-700') + '">' + (rotulo ? 'Ocultar rótulo' : 'Mostrar rótulo') + '</button>'
        + (f.video ? '<button type="button" data-p-escena="' + esc(f.id) + '" class="h-8 rounded-lg border border-gray-200 px-3 text-xs font-semibold text-gray-700">' + (enE.includes(f.id) ? 'Quitar del escenario' : 'Al escenario') + '</button>' : '')
        + (f.invitado ? '<button type="button" data-p-sacar="' + esc(f.id) + '" class="h-8 rounded-lg px-2 text-xs text-rose-700 hover:underline">Sacar</button>' : '') + '</div></div>';
    }).join('');
    h += '</div>' + seccion('Invitar', '<div class="mt-4"></div>' + bloqueInvitar());
    return h;
  }
  function bloqueInvitar() {
    return '<p class="text-xs text-gray-500 mb-2">Envía un enlace: abren su cámara o pantalla desde el celular o el computador, sin instalar nada.</p><div class="flex gap-2"><input id="inv-nombre" maxlength="60" placeholder="Nombre (opcional)" class="flex-1 min-w-0 h-9 rounded-lg border-gray-200 text-sm"><button type="button" data-invitar="camara" class="h-9 rounded-lg bg-[#1d4ed8] text-white px-3 text-xs font-semibold">Cámara</button><button type="button" data-invitar="pantalla" class="h-9 rounded-lg border border-gray-200 px-3 text-xs font-semibold">Pantalla</button></div>'
      + '<div class="mt-3 space-y-1.5">' + (T.invitaciones || []).slice().reverse().map(i => '<div class="flex items-center gap-2 rounded-lg bg-gray-50 px-2 py-1.5"><span class="text-[11px] font-semibold text-gray-700 shrink-0">' + esc(i.nombre || (i.modo === 'pantalla' ? 'Pantalla' : 'Invitado')) + '</span><input readonly value="' + esc(i.url) + '" class="flex-1 min-w-0 h-7 rounded border-gray-200 text-[11px] bg-white"><button type="button" data-copiar-inv="' + esc(i.url) + '" class="text-[#1d4ed8]" title="Copiar">' + ic('copiar', 'h-4 w-4') + '</button></div>').join('') + '</div>';
  }

  function panelChat() {
    return '<p class="text-xs text-gray-500 mb-3">Lo ven tú y tus invitados en el estudio. No sale al aire.</p><div id="chat-lista" class="space-y-2 mb-3">' + (chat.length ? chat.map(m => '<div class="flex ' + (m.mio ? 'justify-end' : '') + '"><div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm ' + (m.mio ? 'bg-[#1d4ed8] text-white rounded-br-sm' : 'bg-gray-100 text-gray-800 rounded-bl-sm') + '">' + (m.mio ? '' : '<div class="text-[11px] font-semibold text-gray-500">' + esc(m.nombre) + '</div>') + esc(m.texto) + '</div></div>').join('') : '<p class="text-sm text-gray-400 text-center py-6">Sin mensajes todavía.</p>') + '</div>'
      + '<form id="chat-form" class="flex gap-2 sticky bottom-0 bg-white py-2"><input id="chat-texto" maxlength="500" autocomplete="off" placeholder="Escribe a tus invitados…" class="flex-1 min-w-0 h-10 rounded-xl border-gray-200 text-sm"><button class="h-10 w-10 rounded-xl bg-[#1d4ed8] text-white grid place-items-center">' + ic('enviar', 'h-4 w-4') + '</button></form>';
  }
  function recibirChat(nombre, texto, mio) {
    if (!texto) return;
    chat.push({ nombre, texto, mio }); if (chat.length > 200) chat.shift();
    if (!mio && pestana !== 'chat') { sinLeer++; pintarRiel(); aviso('💬 ' + nombre + ': ' + texto); }
    if (pestana === 'chat') { pintarPanel(); const l = g('chat-lista'); if (l) l.lastElementChild?.scrollIntoView({ block: 'end' }); }
  }

  function pintarPanel() {
    const panel = g('panel'); if (!panel || !T) return;
    // No repintar mientras se escribe en el panel (se perdería lo escrito)
    const foco = document.activeElement;
    if (foco && panel.contains(foco) && ['INPUT', 'TEXTAREA'].includes(foco.tagName) && foco.type !== 'checkbox' && foco.type !== 'range' && !pintarPanel.forzar) return;
    pintarPanel.forzar = false;
    const scroll = panel.scrollTop;
    const html = { activos: panelActivos, estilo: panelEstilo, banners: panelBanners, comentarios: panelComentarios, widgets: panelWidgets, notas: panelNotas, personas: panelPersonas, chat: panelChat }[pestana]();
    panel.innerHTML = html;
    panel.scrollTop = scroll;
    pintarMarca();
    enlazarPanel(panel);
  }

  function enlazarPanel(panel) {
    const on = (sel, ev, fn) => panel.querySelectorAll(sel).forEach(el => el[ev] = (e) => fn(el, e));
    on('[data-subir]', 'onclick', el => subirArchivo(el.dataset.subir));
    on('[data-borrar-activo]', 'onclick', (el, e) => { e.stopPropagation(); borrarActivo(Number(el.dataset.borrarActivo)); });
    on('[data-ir]', 'onclick', el => { pestana = el.dataset.ir; pintarRiel(); pintarPanel(); });
    // Activos
    on('[data-logo]', 'onclick', el => guardarMarca({ logo_url: PL().logo_url === el.dataset.logo ? '' : el.dataset.logo }));
    on('[data-logo-pos]', 'onclick', el => guardarMarca({ logo_pos: el.dataset.logoPos }));
    const lt = g('logo-texto'); if (lt) lt.onchange = () => guardarMarca({ logo_texto: lt.value });
    const sps = g('sp-suave'); if (sps) sps.onchange = () => guardarMarca({ desvanecer: sps.checked });
    on('[data-sp]', 'onclick', el => { const sp = PL().superposicion; guardarMarca({ superposicion: sp && sp.tipo === 'preset' && sp.valor === el.dataset.sp ? null : { tipo: 'preset', valor: el.dataset.sp, texto: '' } }); });
    on('[data-sp-img]', 'onclick', el => { const sp = PL().superposicion; guardarMarca({ superposicion: sp && sp.tipo === 'imagen' && sp.valor === el.dataset.spImg ? null : { tipo: 'imagen', valor: el.dataset.spImg } }); });
    const spt = g('sp-texto-ok'); if (spt) spt.onclick = () => { const sp = PL().superposicion; pintarPanel.forzar = true; guardarMarca({ superposicion: Object.assign({}, sp, { texto: g('sp-texto').value }) }); };
    const cb = g('clip-bucle'); if (cb) cb.onchange = () => { bucle = cb.checked; aviso(bucle ? 'El próximo video que pongas se repetirá' : 'Los videos sonarán una sola vez'); };
    on('[data-pantalla]', 'onclick', el => { const c = PANTALLAS[Number(el.dataset.pantalla)]; escena({ pantalla: { preset: c.preset, duracion: c.duracion || null } }); });
    on('[data-clip]', 'onclick', el => { const r = E().recurso; if (r && r.id === 'activo-' + el.dataset.clip && !finRecurso(r)) return escena({ quitar_recurso: true }); escena({ activo_id: Number(el.dataset.clip), bucle }); });
    on('[data-recurso]', 'onclick', el => { const r = E().recurso; if (r && r.id === Number(el.dataset.recurso) && !finRecurso(r)) return escena({ quitar_recurso: true }); escena(bucle ? { recurso_id: Number(el.dataset.recurso), bucle: true } : { recurso_id: Number(el.dataset.recurso) }); });
    const qc = g('quitar-clip'); if (qc) qc.onclick = () => escena({ quitar_recurso: true });
    on('[data-fondo]', 'onclick', el => guardarMarca({ fondo: { tipo: 'preset', valor: el.dataset.fondo } }));
    on('[data-fondo-img]', 'onclick', el => guardarMarca({ fondo: { tipo: 'imagen', valor: el.dataset.fondoImg } }));
    const fc = g('fondo-color'); if (fc) fc.onchange = () => guardarMarca({ fondo: { tipo: 'color', valor: fc.value.toUpperCase() } });
    const vs = g('vol-sonidos'); if (vs) vs.onchange = () => escena({ vol_sonidos: Number(vs.value) });
    const vm = g('vol-musica'); if (vm) vm.onchange = () => escena({ vol_musica: Number(vm.value) });
    on('[data-efecto]', 'onclick', el => { escena({ sonido: { preset: el.dataset.efecto } }); aviso('Sonó «' + (EFECTOS[el.dataset.efecto] || '') + '» en la transmisión'); });
    on('[data-efecto-archivo]', 'onclick', el => escena({ sonido: { activo_id: Number(el.dataset.efectoArchivo) } }));
    on('[data-oir-efecto]', 'onclick', el => escuchar('efecto', el.dataset.oirEfecto));
    on('[data-oir-musica]', 'onclick', el => escuchar('musica', el.dataset.oirMusica));
    on('[data-oir-url]', 'onclick', el => escuchar('archivo', null, el.dataset.oirUrl));
    on('[data-musica]', 'onclick', el => { const mu = E().musica; escena(mu && mu.preset === el.dataset.musica ? { quitar_musica: true } : { musica: { preset: el.dataset.musica } }); });
    on('[data-musica-archivo]', 'onclick', el => { const mu = E().musica; escena(mu && mu.id === 'activo-' + el.dataset.musicaArchivo ? { quitar_musica: true } : { musica: { activo_id: Number(el.dataset.musicaArchivo), bucle: true } }); });
    const ma = g('musica-alternar'); if (ma) ma.onclick = () => E().musica ? escena({ quitar_musica: true }) : escena({ musica: { preset: Object.keys(MUSICAS)[0] || 'ambiente' } });
    // Estilo
    on('[data-color]', 'onclick', el => guardarMarca({ color_etiqueta: el.dataset.color }));
    const cl = g('color-libre'); if (cl) cl.onchange = () => guardarMarca({ color_etiqueta: cl.value.toUpperCase() });
    on('[data-tema]', 'onclick', el => guardarMarca({ tema: el.dataset.tema }));
    on('[data-fuente]', 'onclick', el => guardarMarca({ fuente: el.dataset.fuente }));
    const en = g('est-nombres'); if (en) en.onchange = () => guardarMarca({ mostrar_nombres: en.checked });
    const ev = g('est-vivo'); if (ev) ev.onchange = () => guardarMarca({ en_vivo: ev.checked });
    const eg = g('est-guardar'); if (eg) eg.onclick = () => { pintarPanel.forzar = true; guardarMarca({ etiqueta: g('est-etiqueta').value, pie: g('est-pie').value, hashtag: g('est-hashtag').value }).then(() => aviso('Textos guardados')); };
    // Banners
    const bt = g('ban-titular-ok'); if (bt) bt.onclick = () => { pintarPanel.forzar = true; plantilla({ titulo: g('ban-titular').value, mostrar: g('ban-mostrar').checked }); };
    const bm = g('ban-mostrar'); if (bm) bm.onchange = () => plantilla({ mostrar: bm.checked, titulo: g('ban-titular').value });
    const bannersDe = () => ((marca().datos || {}).banners || []).slice();
    const bc = g('ban-crear'); if (bc) bc.onclick = () => {
      const texto = g('ban-texto').value.trim(); if (!texto) return aviso('Escribe el texto del banner.', 'error');
      const nuevo = { id: Math.random().toString(36).slice(2, 10), titulo: g('ban-titulo').value.trim(), texto, cinta: g('ban-cinta').checked };
      pintarPanel.forzar = true; guardarMarca({ banners: [...bannersDe(), nuevo] });
    };
    on('[data-banner]', 'onclick', el => { const b = bannersDe().find(x => x.id === el.dataset.banner), a = E().banner; if (!b) return; const on = a && a.texto === b.texto && (a.titulo || '') === (b.titulo || '') && !!a.cinta === !!b.cinta; escena(on ? { quitar_banner: true } : { banner: { titulo: b.titulo, texto: b.texto, cinta: !!b.cinta } }); });
    on('[data-borrar-banner]', 'onclick', (el, e) => { e.stopPropagation(); if (!confirm('¿Borrar este banner?')) return; guardarMarca({ banners: bannersDe().filter(x => x.id !== el.dataset.borrarBanner) }); });
    on('[data-editar-banner]', 'onclick', (el, e) => { e.stopPropagation(); const l = bannersDe(), b = l.find(x => x.id === el.dataset.editarBanner); if (!b) return; const texto = prompt('Texto del banner:', b.texto); if (texto === null || !texto.trim()) return; const titulo = prompt('Etiqueta (opcional):', b.titulo || ''); b.texto = texto.trim(); b.titulo = (titulo || '').trim(); guardarMarca({ banners: l }); });
    const bq = g('ban-quitar'); if (bq) bq.onclick = () => escena({ quitar_banner: true });
    // Comentarios
    on('[data-comentario]', 'onclick', el => { const c = comentarios[Number(el.dataset.comentario)], a = E().comentario; if (!c) return; escena(a && a.texto === c.texto && a.nombre === c.nombre ? { quitar_comentario: true } : { comentario: { nombre: c.nombre, texto: c.texto, foto: c.foto || '', red: c.red } }); });
    const cq = g('com-quitar'); if (cq) cq.onclick = () => escena({ quitar_comentario: true });
    const cm = g('com-mostrar'); if (cm) cm.onclick = () => { const texto = g('com-texto').value.trim(); if (!texto) return; pintarPanel.forzar = true; escena({ comentario: { nombre: g('com-nombre').value.trim(), texto } }); };
    // Widgets
    const wr = g('w-reloj'); if (wr) wr.onchange = () => escena({ widgets: { reloj: wr.checked } });
    const wi = g('w-iniciar'); if (wi) wi.onclick = () => { const s = (Number(g('w-min').value) || 0) * 60 + (Number(g('w-seg').value) || 0); if (s < 1) return aviso('Pon el tiempo de la cuenta regresiva.', 'error'); pintarPanel.forzar = true; escena({ widgets: { cuenta: { segundos: s, texto: g('w-texto').value } } }); };
    const wp = g('w-parar'); if (wp) wp.onclick = () => escena({ widgets: { cuenta: null } });
    // Notas
    const nt = g('notas'); if (nt) { let t0 = null; nt.oninput = () => { g('notas-estado').textContent = 'Guardando…'; clearTimeout(t0); t0 = setTimeout(async () => { try { await pedir('POST', url('notas'), { notas: nt.value }); T.notas = nt.value; g('notas-estado').textContent = 'Guardado'; g('tp-texto').textContent = nt.value; } catch (e) { g('notas-estado').textContent = 'No se pudo guardar'; } }, 900); }; }
    const ntp = g('notas-tp'); if (ntp) ntp.onclick = alternarTeleprompter;
    // Personas
    const ra = g('rot-auto'); if (ra) ra.onchange = () => escena({ rotulo_auto: ra.checked });
    on('[data-p-guardar]', 'onclick', el => {
      const id = el.dataset.pGuardar, nombre = panel.querySelector('[data-p-nombre="' + CSS.escape(id) + '"]').value.trim(), cargo = panel.querySelector('[data-p-cargo="' + CSS.escape(id) + '"]').value.trim();
      const nombres = Object.assign({}, E().nombres || {});
      if (nombre) nombres[id] = { personas: [{ nombre, cargo }], activa: 0 }; else delete nombres[id];
      pintarPanel.forzar = true; escena({ nombres }).then(() => aviso('Guardado'));
    });
    on('[data-p-rotulo]', 'onclick', el => { const id = el.dataset.pRotulo; if (E().rotulo_de === id) return escena({ quitar_rotulo: true }); const p = personaDe(id); if (!p || !p.nombre) return aviso('Escribe y guarda primero su nombre.', 'error'); escena({ rotulo_de: id }); });
    on('[data-p-escena]', 'onclick', el => alternarEscenario(el.dataset.pEscena));
    on('[data-p-sacar]', 'onclick', async el => { if (!confirm('¿Sacar a esta persona de la transmisión?')) return; try { await pedir('POST', url('participantes/' + encodeURIComponent(el.dataset.pSacar) + '/expulsar')); } catch (e) { fallo(e); } });
    enlazarInvitar(panel);
    // Chat
    const cf = g('chat-form'); if (cf) { cf.onsubmit = async (e) => { e.preventDefault(); const inp = g('chat-texto'), texto = inp.value.trim(); if (!texto || !room) return; try { await room.localParticipant.publishData(new TextEncoder().encode(JSON.stringify({ nombre: 'Estudio', texto })), { reliable: true, topic: 'chat' }); inp.value = ''; pintarPanel.forzar = true; recibirChat('Tú', texto, true); g('chat-texto')?.focus(); } catch (er) { fallo(er); } }; const l = g('chat-lista'); l && l.lastElementChild && l.lastElementChild.scrollIntoView({ block: 'end' }); }
  }
  function enlazarInvitar(raiz) {
    raiz.querySelectorAll('[data-invitar]').forEach(b => b.onclick = async () => {
      try { const j = await pedir('POST', url('invitacion'), { nombre: (raiz.querySelector('#inv-nombre') || {}).value || null, modo: b.dataset.invitar }); T.invitaciones = [...(T.invitaciones || []), { nombre: null, modo: j.modo, url: j.url }]; await copiar(j.url); pintarPanel.forzar = true; if (!g('modal').classList.contains('hidden')) abrirModal(modalActual); pintarPanel(); }
      catch (e) { fallo(e); }
    });
    raiz.querySelectorAll('[data-copiar-inv]').forEach(b => b.onclick = () => copiar(b.dataset.copiarInv));
  }
  async function copiar(texto) { try { await navigator.clipboard.writeText(texto); aviso('Enlace copiado'); } catch (e) { prompt('Copia el enlace:', texto); } }

  // ------------------------------------------------ teleprompter (solo en la vista del director)
  let tpTimer = null;
  function alternarTeleprompter() {
    const tp = g('ev-teleprompter'), abierto = !tp.classList.contains('hidden');
    if (abierto) { tp.classList.add('hidden'); clearInterval(tpTimer); return; }
    if (!(T.notas || '').trim()) { pestana = 'notas'; pintarRiel(); pintarPanel(); return aviso('Escribe primero tus notas.'); }
    g('tp-texto').textContent = T.notas; tp.classList.remove('hidden'); tp.scrollTop = 0;
    clearInterval(tpTimer); tpTimer = setInterval(() => { tp.scrollTop += 1; }, 60);
  }
  g('tp-cerrar').onclick = alternarTeleprompter; g('btn-tp').onclick = alternarTeleprompter;
  g('ev-teleprompter').onclick = (e) => { if (e.target.id !== 'tp-cerrar' && !e.target.closest('#tp-cerrar')) { if (tpTimer) { clearInterval(tpTimer); tpTimer = null; } else tpTimer = setInterval(() => { g('ev-teleprompter').scrollTop += 1; }, 60); } };

  // ------------------------------------------------ ventanas (invitar, ajustes, salir al aire, opciones)
  let modalActual = null;
  function abrirModal(tipo, datos) {
    modalActual = tipo;
    const cuerpo = g('modal-cuerpo'); let titulo = '';
    if (tipo === 'invitar' || tipo === 'presentar') {
      titulo = tipo === 'presentar' ? 'Presentar o invitar' : 'Invitar al estudio';
      cuerpo.innerHTML = (tipo === 'presentar' && navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia ? '<button type="button" id="m-pantalla" class="w-full mb-4 flex items-center gap-3 rounded-xl border border-gray-200 p-3 hover:bg-gray-50 text-left"><span class="h-10 w-10 rounded-full bg-blue-50 text-[#1d4ed8] grid place-items-center">' + ic('pantalla') + '</span><span><span class="block text-sm font-semibold">' + (pantallaOn ? 'Dejar de compartir mi pantalla' : 'Compartir mi pantalla') + '</span><span class="block text-xs text-gray-500">Una ventana, una pestaña o la pantalla completa (con su audio)</span></span></button>' : '') + bloqueInvitar();
      enlazarInvitar(cuerpo);
      const mp = g('m-pantalla'); if (mp) mp.onclick = () => { cerrarModal(); alternarPantalla(); };
    } else if (tipo === 'ajustes') {
      titulo = 'Ajustes';
      cuerpo.innerHTML = '<label class="block text-xs font-semibold text-gray-500 mb-1">Cámara</label><select id="aj-cam" class="w-full h-10 rounded-lg border-gray-200 text-sm mb-3"></select><label class="block text-xs font-semibold text-gray-500 mb-1">Micrófono</label><select id="aj-mic" class="w-full h-10 rounded-lg border-gray-200 text-sm mb-3"></select>'
        + interruptor('aj-oir', oirInvitados, 'Escuchar a los invitados', 'Usa audífonos para que no se devuelva el sonido') + interruptor('aj-espejo', espejo, 'Ver mi cámara en espejo', 'Solo en tu vista; la audiencia la ve normal')
        + '<p class="mt-3 text-xs text-gray-500">La transmisión sale en 720p a 30 cuadros por segundo. El monitor de arriba es lo que ve la audiencia (sin sonido, para evitar el eco).</p>';
      llenarDispositivos();
      g('aj-oir').onchange = (e) => { oirInvitados = e.target.checked; g('audio-invitados').querySelectorAll('audio').forEach(a => a.muted = !oirInvitados); };
      g('aj-espejo').onchange = (e) => { espejo = e.target.checked; pintarBastidores(); };
    } else if (tipo === 'aire') {
      titulo = 'Salir al aire';
      cuerpo.innerHTML = '<p class="text-sm text-gray-600 mb-3">Se crea el en vivo en cada destino y la escena sale a todos a la vez.</p><div class="space-y-1.5 mb-4">' + (T.destinos || []).map(d => '<div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm"><span class="h-6 w-6 rounded-full grid place-items-center text-white text-[11px] font-bold" style="background:' + (d.red === 'youtube' ? '#FF0000' : '#1877F2') + '">' + (d.red === 'youtube' ? '▶' : 'f') + '</span>' + esc(d.pagina) + '</div>').join('') + '</div>'
        + '<label class="block text-xs font-semibold text-gray-500 mb-1">Empezar con un video (opcional)</label><select id="aire-intro" class="w-full h-10 rounded-lg border-gray-200 text-sm mb-4"><option value="">Directo con las cámaras</option>' + RECURSOS.filter(r => r.tipo === 'video').map(r => '<option value="' + r.id + '">' + esc(r.nombre) + '</option>').join('') + '</select>'
        + '<button type="button" id="aire-ok" class="w-full h-12 rounded-xl bg-[#1d4ed8] text-white font-bold">Salir al aire ahora</button>';
      g('aire-ok').onclick = salirAlAire;
    } else if (tipo === 'opciones') {
      titulo = datos.titulo;
      cuerpo.innerHTML = datos.opciones.map(([i, t], n) => '<button type="button" data-op="' + n + '" class="w-full flex items-center gap-3 rounded-xl px-3 py-3 text-left text-sm hover:bg-gray-50">' + ic(i) + esc(t) + '</button>').join('');
      cuerpo.querySelectorAll('[data-op]').forEach(b => b.onclick = () => { cerrarModal(); datos.opciones[Number(b.dataset.op)][2](); });
    }
    g('modal-titulo').textContent = titulo; g('modal').classList.remove('hidden');
  }
  function cerrarModal() { g('modal').classList.add('hidden'); modalActual = null; }
  g('modal-cerrar').onclick = cerrarModal; g('modal').onclick = (e) => { if (e.target.id === 'modal') cerrarModal(); };
  document.querySelectorAll('[data-abrir-modal]').forEach(b => b.onclick = () => abrirModal(b.dataset.abrirModal));
  async function llenarDispositivos() {
    if (!room) return;
    const llenar = async (tipo, sel) => {
      const lista = await LivekitClient.Room.getLocalDevices(tipo).catch(() => []);
      sel.innerHTML = lista.map(d => '<option value="' + esc(d.deviceId) + '">' + esc(d.label || tipo) + '</option>').join('');
      const actual = room.getActiveDevice(tipo); if (actual) sel.value = actual;
      sel.onchange = () => room.switchActiveDevice(tipo, sel.value).catch(fallo);
    };
    await llenar('videoinput', g('aj-cam')); await llenar('audioinput', g('aj-mic'));
  }

  // ------------------------------------------------ al aire / terminar
  g('btn-aire').onclick = () => abrirModal('aire');
  async function salirAlAire() {
    const b = g('aire-ok'); b.disabled = true; b.textContent = 'Saliendo al aire…';
    try { const intro = g('aire-intro').value; const j = await pedir('POST', url('aire'), intro ? { intro_recurso_id: Number(intro) } : {}); T = j.transmision; cerrarModal(); pintarTodo(); aviso('¡Estás al aire!'); }
    catch (e) { b.disabled = false; b.textContent = 'Salir al aire ahora'; fallo(new Error('No se pudo salir al aire: ' + e.message)); }
  }
  async function terminar() {
    if (!confirm(T.estado === 'en_vivo' ? '¿Terminar la transmisión en todas las páginas?' : '¿Cerrar el estudio sin salir al aire?')) return;
    try {
      const j = await pedir('POST', url('terminar')); T = j.transmision; clearInterval(sondeo); clearInterval(sondeoComentarios);
      if (room) await room.disconnect();
      const enlaces = (T.destinos || []).filter(d => d.permalink).map(d => d.pagina + ': ' + d.permalink).join('\n');
      alert('Transmisión terminada.' + (enlaces ? '\n\n' + enlaces : '')); location.reload();
    } catch (e) { fallo(e); }
  }
  g('btn-terminar').onclick = terminar; g('btn-terminar-top').onclick = terminar;

  // ------------------------------------------------ estado
  async function sondear() {
    if (!T) return;
    try {
      const [est, par] = await Promise.all([pedir('GET', url('estado')), pedir('GET', url('participantes')).catch(() => null)]);
      aplicarEstado(est);
      if (par) { participantes = par.participantes || []; if (par.escena) T.escena = par.escena; }
      pintarBastidores(); pintarLayouts();
      if (['comentarios', 'widgets', 'activos'].includes(pestana)) pintarPanel();
    } catch (e) { /* reintenta en el próximo ciclo */ }
  }
  function aplicarEstado(j) {
    const notas = T ? T.notas : null;
    T = Object.assign(j.transmision, { notas: j.transmision.notas ?? notas });
    pintarTransmision();
    const fb = j.facebook || {};
    if (T.estado === 'en_vivo') { g('ev-espectadores').classList.remove('hidden'); g('ev-espectadores-n').textContent = fb.espectadores ?? '—'; }
    const sal = g('ev-salida'); sal.textContent = j.egress ? 'salida: ' + String(j.egress).replace('EGRESS_', '').toLowerCase() : ''; sal.classList.toggle('hidden', !j.egress);
    if (T.estado === 'error') fallo(new Error(T.error || 'La transmisión tuvo un error.'));
    revisarComentarios();
  }
  function revisarComentarios() {
    const activo = T && T.estado === 'en_vivo' && pestana === 'comentarios';
    if (activo && !sondeoComentarios) { leerComentarios(); sondeoComentarios = setInterval(leerComentarios, 9000); }
    if (!activo && sondeoComentarios) { clearInterval(sondeoComentarios); sondeoComentarios = null; }
  }
  async function leerComentarios() {
    try { const j = await pedir('GET', url('comentarios')); comentarios = j.comentarios || []; avisosComentarios = j.avisos || []; if (pestana === 'comentarios') pintarPanel(); } catch (e) {}
  }
  function pintarTransmision() {
    if (!T) return;
    g('ev-titulo').textContent = T.titulo;
    const enVivo = T.estado === 'en_vivo';
    const e = g('ev-estado'); e.textContent = enVivo ? '● EN VIVO' : (T.estado === 'sala' ? 'SALA DE ESPERA' : String(T.estado).toUpperCase());
    e.className = 'hidden sm:inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold tracking-wide ' + (enVivo ? 'bg-rose-600 text-white' : 'bg-gray-100 text-gray-700');
    g('btn-aire').classList.toggle('hidden', T.estado !== 'sala');
    g('btn-terminar-top').classList.toggle('hidden', !enVivo);
    g('btn-terminar').title = enVivo ? 'Terminar la transmisión' : 'Cerrar el estudio';
    g('ev-destinos').innerHTML = (T.destinos || []).map(d => {
      const color = d.estado === 'ok' ? 'bg-emerald-50 text-emerald-700' : (d.estado === 'error' ? 'bg-rose-50 text-rose-700' : 'bg-gray-100 text-gray-600');
      const cont = esc((d.red === 'youtube' ? '▶ ' : 'f ') + d.pagina) + (d.error ? ' · ' + esc(d.error).slice(0, 60) : '');
      return d.permalink ? '<a href="' + esc(d.permalink) + '" target="_blank" rel="noopener" class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold ' + color + ' hover:underline">' + cont + ' ↗</a>' : '<span class="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold ' + color + '">' + cont + '</span>';
    }).join('');
    if (enVivo && T.iniciada_en && !relojInicio) { relojInicio = new Date(T.iniciada_en); g('ev-reloj').classList.remove('hidden'); }
  }
  function pintarTodo() { if (!T) return; pintarTransmision(); pintarLayouts(); pintarBastidores(); pintarRiel(); pintarPanel(); pintarMarca(); }
  setInterval(() => { if (!relojInicio) return; const s = Math.max(0, Math.floor((Date.now() - relojInicio) / 1000)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60; g('ev-reloj').textContent = (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0'); }, 1000);
  window.addEventListener('beforeunload', (ev) => { if (T && T.estado === 'en_vivo') { ev.preventDefault(); ev.returnValue = ''; } });

  if (T) abrirEstudio(null, null);
})();
</script>
@endsection
