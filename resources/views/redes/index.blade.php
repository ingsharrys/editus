@extends('layouts.app')

@section('content')
{{--
  Redes: lo mismo que la sección Redes de la app del editor. Por cada medio elegido se publica
  la nota en su web (con la categoría elegida) y luego, en sus páginas de Facebook e Instagram,
  la imagen (con plantilla: logo del medio, categoría, título y pie) o el video. Todo queda en
  la campaña de sistema Esnoticia. La lógica la hace el backend de esnoticia (como en la app).
--}}
@php
    $T = [
        'eligeAutor' => __('Elige el periodista que firma la nota.'),
        'eligeFoto' => __('Adjunta la imagen'),
        'eligeVideo' => __('Elige el video'),
        'esperaVideo' => __('Espera a que termine de subir el video'),
        'escribeTitulo' => __('Escribe el título'),
        'escribeTexto' => __('Escribe el texto de la nota'),
        'eligeMedio' => __('Elige al menos un medio'),
        'videoSinRedes' => __('El video se publica primero en Facebook / Instagram: marca un medio con página asignada'),
        'sinRespuesta' => __('El servidor no respondió. Si la imagen es muy grande, prueba con una más liviana.'),
        'errorConexion' => __('Error de conexión'),
        'noSePudo' => __('No se pudo publicar'),
        'publicado' => __('¡Publicado!'),
        'conObservaciones' => __('Publicado con observaciones'),
        'videoListo' => __('Video listo: se publica al tocar Publicar'),
        'subiendoVideo' => __('Preparando…'),
        'videoMax' => __('El video no puede superar :n MB'),
        'videoError' => __('No se pudo subir el video'),
        'imagenError' => __('No se pudo leer la imagen'),
        'logoGuardado' => __('Logo guardado'),
        'tituloPrevia' => __('Título de la publicación'),
        'videoWebOk' => __('¡Video publicado en la web!'),
        'publicarEn' => __('Publicar en :m medio(s) · :r red(es)'),
        'publicando' => __('Publicando…'),
        'publicandoVideo' => __('Publicando el video en redes y en la web…'),
        'soloWeb' => __('Solo web (sin página asignada en editus)'),
        'seCrea' => __('(se crea en este medio)'),
        'seCreaOpcion' => __('(se crea)'),
        'propuesto' => __('(propuesto)'),
        'marco' => __('marco'),
        'estado' => ['publicado' => __('✅ Publicado'), 'parcial' => __('⚠️ Parcial'), 'fallido' => __('❌ Falló'), 'procesando' => __('⏳ Procesando')],
        'red' => ['ok' => __('Publicado'), 'no' => __('No enviado'), 'sinConectar' => __('Sin conectar'), 'omitido' => __('Omitido'), 'fallo' => __('Falló')],
    ];
    $inp = 'w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-200';
    $lbl = 'block text-xs font-semibold text-gray-500 mb-1';
    $card = 'rounded-2xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5';
    $h3 = 'text-sm font-bold text-gray-900 mb-3';
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8" x-data="redesWeb()" x-init="iniciar()">
    <div class="mt-8 lg:mt-10 mb-5 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">{{ __('Campaña Esnoticia') }}</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">{{ __('Redes') }}</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-3xl">{!! __('Lo mismo que la sección Redes de la app: elige los <strong>medios</strong> y en cada uno se publica la nota en su <strong>web</strong> y luego, en sus páginas de <strong>Facebook e Instagram</strong>, la imagen con el logo del medio. Todo queda en la campaña <strong>Esnoticia</strong>.') !!}</p>
        </div>
        @if ($configurado)
            <div class="w-full sm:w-72">
                <label class="{{ $lbl }}">{{ __('Firma la nota (periodista)') }} *</label>
                <select x-model="autor" @change="cambiarAutor()" class="{{ $inp }} h-10">
                    <option value="">{{ __('— Elige el periodista —') }}</option>
                    @foreach ($autores as $a)
                        <option value="{{ $a['id'] }}">{{ $a['username'] }}{{ $a['email'] ? ' · ' . $a['email'] : '' }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @unless ($configurado)
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">{!! __('Falta conectar editus con el backend de esnoticia: agrega <code>ESNOTICIA_URL</code> y <code>EDITUS_INGEST_TOKEN</code> al .env de editus (el token debe ser igual a <code>EDITUS_TOKEN</code> del backend).') !!}</div>
    @else
        @if (empty($autores))
            <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-4 text-sm">{{ __('No se pudo leer la lista de periodistas del backend de esnoticia. Revisa la conexión (ESNOTICIA_URL y EDITUS_INGEST_TOKEN).') }}</div>
        @endif

        {{-- Pestañas --}}
        <div class="inline-flex rounded-xl bg-gray-100 p-1 mb-5 text-sm font-semibold">
            <button type="button" @click="cambiarVista('nueva')" :class="vista === 'nueva' ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="rounded-lg px-4 py-2">{{ __('Nueva publicación') }}</button>
            <button type="button" @click="cambiarVista('video')" :class="vista === 'video' ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="rounded-lg px-4 py-2">🎬 {{ __('Ya en redes') }}</button>
            <button type="button" @click="cambiarVista('historial')" :class="vista === 'historial' ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="rounded-lg px-4 py-2">{{ __('Historial') }}</button>
        </div>

        <div x-show="!autor" class="rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-900 px-4 py-3 mb-5 text-sm">{{ __('Elige arriba el periodista que firma la nota: con su usuario se publica en la web de los medios y se ven sus páginas, como en la app.') }}</div>

        {{-- Aviso flotante --}}
        <div x-show="aviso.texto" x-transition class="fixed bottom-5 right-5 z-[120] max-w-sm rounded-xl px-4 py-3 text-sm font-medium shadow-lg"
             :class="{'bg-rose-600 text-white': aviso.tono === 'fallo', 'bg-emerald-600 text-white': aviso.tono === 'ok', 'bg-amber-500 text-white': aviso.tono === 'atencion'}" x-text="aviso.texto" style="display:none"></div>

        {{-- ======================= NUEVA PUBLICACIÓN ======================= --}}
        <div x-show="vista === 'nueva' && autor" style="display:none">
            {{-- Resultado de la última publicación --}}
            <template x-if="resultado">
                <div class="{{ $card }} mb-5" :class="resultado.success ? 'border-emerald-200' : 'border-rose-200'">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex gap-3 min-w-0">
                            <template x-if="resultado.imagen"><img :src="resultado.imagen" alt="" class="h-20 w-16 rounded-lg object-cover border border-gray-100"></template>
                            <div class="min-w-0">
                                <div class="text-base font-bold" :class="resultado.success ? 'text-emerald-700' : 'text-rose-700'" x-text="resultado.titulo"></div>
                                <div class="text-sm text-gray-800 font-semibold" x-text="resultado.nota"></div>
                                <div class="text-xs text-gray-500" x-text="resultado.detalle"></div>
                                <p x-show="resultado.error" class="mt-1 text-xs text-rose-700" x-text="resultado.error"></p>
                            </div>
                        </div>
                        <button type="button" @click="resultado = null" class="text-xs font-semibold text-gray-500 hover:text-gray-800">{{ __('Cerrar') }}</button>
                    </div>
                    <div x-html="htmlResultados(resultado.resultados)"></div>
                </div>
            </template>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] gap-5 items-start">
                <div class="space-y-5 min-w-0">
                    {{-- Foto o video --}}
                    <div class="{{ $card }}">
                        <h3 class="{{ $h3 }}">{{ __('¿Qué vas a publicar?') }}</h3>
                        <div class="inline-flex rounded-xl bg-gray-100 p-1 text-sm font-semibold mb-3">
                            <button type="button" @click="tipoMedio = 'foto'; vistaPrevia = null" :class="tipoMedio === 'foto' ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="rounded-lg px-4 py-1.5">🖼️ {{ __('Foto') }}</button>
                            <button type="button" @click="tipoMedio = 'video'; vistaPrevia = null" :class="tipoMedio === 'video' ? 'bg-white shadow text-gray-900' : 'text-gray-500'" class="rounded-lg px-4 py-1.5">🎬 {{ __('Video') }}</button>
                        </div>

                        <div x-show="tipoMedio === 'foto'">
                            <label class="block cursor-pointer rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 hover:bg-gray-100 overflow-hidden"
                                   @dragover.prevent @drop.prevent="leerFoto($event.dataTransfer.files[0])">
                                <input type="file" accept="image/*" class="hidden" @change="leerFoto($event.target.files[0]); $event.target.value = ''" :disabled="publicando">
                                <template x-if="imagen"><img :src="imagen" alt="" class="w-full max-h-72 object-contain bg-gray-900"></template>
                                <div x-show="!imagen" class="py-12 text-center text-sm text-gray-500">📷 {{ __('Elige una foto o arrástrala aquí') }}</div>
                            </label>
                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="text-[11px] text-gray-500" x-text="plantilla ? @js(__('Con plantilla, la foto se recorta al formato vertical 4:5 y se le agregan el logo, la categoría y el título. En la web va la foto original.')) : @js(__('Instagram acepta fotos entre 4:5 y 1.91:1. Si la tuya es más ancha o más alta, se completa con bordes blancos, sin recortar.'))"></p>
                                <button type="button" x-show="imagen" @click="imagen = null; vistaPrevia = null" class="text-xs font-semibold text-rose-600 shrink-0">{{ __('Quitar') }}</button>
                            </div>
                        </div>

                        <div x-show="tipoMedio === 'video'" style="display:none">
                            <template x-if="video">
                                <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                                    <div class="text-2xl" :class="videoId ? 'text-emerald-600' : 'text-gray-400'">🎬</div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-semibold text-gray-900 truncate" x-text="video.nombre"></div>
                                        <div class="text-xs text-gray-500" x-text="[fmtDuracion(video.duracion), fmtTamano(video.tamano)].filter(Boolean).join(' · ')"></div>
                                        <div x-show="subiendo !== null" class="h-1.5 mt-1.5 rounded-full bg-gray-200 overflow-hidden"><div class="h-1.5 bg-indigo-500" :style="'width:' + Math.round((subiendo || 0) * 100) + '%'"></div></div>
                                        <div class="text-xs font-semibold mt-1" :class="videoId ? 'text-emerald-700' : 'text-gray-500'" x-text="subiendo !== null ? T.subiendoVideo + ' ' + Math.round(subiendo * 100) + '%' : (videoId ? '✓ ' + @js(__('Listo para publicar')) : @js(__('Pendiente')))"></div>
                                    </div>
                                    <button type="button" @click="quitarVideo()" :disabled="publicando || subiendo !== null" class="text-xs font-semibold text-gray-500 hover:text-rose-600">{{ __('Quitar') }}</button>
                                </div>
                            </template>
                            <label x-show="!video" class="block cursor-pointer rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 hover:bg-gray-100 py-10 text-center text-sm text-gray-500">
                                <input type="file" accept="video/*" class="hidden" @change="elegirVideo($event.target.files[0]); $event.target.value = ''" :disabled="publicando">
                                🎬 {{ __('Elige el video (MP4, ideal vertical 9:16, máximo 15 minutos)') }}
                            </label>
                            <p class="mt-2 text-[11px] text-gray-500">{{ __('En una sola publicación: el video va primero a Facebook (video) e Instagram (Reel) de los medios marcados y enseguida a la web de cada medio con el video embebido, en la categoría Videos (si no eliges otra). El archivo no se guarda en ningún servidor: pasa por editus solo mientras Meta lo procesa.') }}</p>
                        </div>
                    </div>

                    {{-- Contenido --}}
                    <div class="{{ $card }}">
                        <h3 class="{{ $h3 }}">{{ __('Contenido') }}</h3>
                        <div class="flex items-end justify-between"><label class="{{ $lbl }}">{{ __('Título') }} *</label><span class="text-[11px] text-gray-400" x-text="titulo.length + '/150'"></span></div>
                        <input type="text" x-model="titulo" maxlength="150" :disabled="publicando" placeholder="{{ __('Título de la nota (también va en la imagen)') }}" class="{{ $inp }} h-10 mb-3">
                        <div class="flex items-end justify-between"><label class="{{ $lbl }}">{{ __('Texto de la nota') }} *</label><span class="text-[11px] text-gray-400" x-text="texto.length + ' / 6000'"></span></div>
                        <textarea x-model="texto" maxlength="6000" rows="8" :disabled="publicando" placeholder="{{ __('Cuerpo completo de la nota. Va a la web y, completo, a Instagram. Puedes usar #hashtags.') }}" class="{{ $inp }}"></textarea>
                        <div x-show="resumen()" class="mt-3 rounded-xl bg-sky-50 p-3">
                            <div class="text-[11px] font-bold uppercase text-sky-800 mb-1">📘 {{ __('Así saldrá en Facebook') }}</div>
                            <div class="text-sm text-gray-900" x-text="resumen()"></div>
                            <div class="text-xs font-semibold text-sky-800 mt-1">{{ __('Ver más: enlace corto a la nota') }}</div>
                        </div>
                    </div>

                    {{-- Categoría --}}
                    <div class="{{ $card }}">
                        <h3 class="{{ $h3 }}">🏷️ {{ __('Categoría') }}</h3>
                        <label class="flex items-start gap-2 mb-3 cursor-pointer">
                            <input type="checkbox" x-model="mismaCategoria" class="mt-0.5 rounded border-gray-300 text-indigo-600" :disabled="publicando">
                            <span class="text-sm"><span class="font-semibold text-gray-900" x-text="mismaCategoria ? @js(__('La misma categoría para todos los medios')) : @js(__('Una categoría por medio'))"></span>
                                <span class="block text-xs text-gray-500" x-text="mismaCategoria ? @js(__('Si un medio no la tiene, se crea automáticamente')) : @js(__('Elige la categoría en cada medio, más abajo'))"></span></span>
                        </label>
                        <select x-show="mismaCategoria" x-model="categoriaGlobal" @change="vistaPrevia = null" :disabled="publicando" class="{{ $inp }} h-10">
                            <template x-for="o in opcionesCategoriaGlobal()" :key="o"><option :value="o" x-text="o" :selected="o === categoriaGlobal"></option></template>
                        </select>
                        <p class="mt-2 text-[11px] text-gray-500" x-text="tipoMedio === 'video' ? @js(__('La categoría de la nota de video en la web (por defecto se usa "Videos").')) : @js(__('La categoría va en la web y, con plantilla, en la etiqueta roja de la imagen.'))"></p>
                    </div>

                    {{-- Medios --}}
                    <div class="{{ $card }}">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h3 class="text-sm font-bold text-gray-900">📰 {{ __('Medios: ¿dónde se publica?') }}</h3>
                            <div class="flex gap-3 text-xs font-semibold">
                                <button type="button" @click="marcarTodos(true)" class="text-indigo-700 hover:underline">{{ __('Todos') }}</button>
                                <button type="button" @click="marcarTodos(false)" class="text-gray-500 hover:underline">{{ __('Ninguno') }}</button>
                            </div>
                        </div>
                        <div x-show="cargando" class="py-6 text-center text-sm text-gray-500">{{ __('Cargando medios y páginas…') }}</div>
                        <template x-for="(e, k) in errores" :key="k">
                            <div class="rounded-lg bg-amber-50 text-amber-900 text-xs px-3 py-2 mb-2"><span x-text="e"></span> <button type="button" @click="cargarCatalogos()" class="ml-1 font-semibold underline">{{ __('Reintentar') }}</button></div>
                        </template>

                        <div class="space-y-2">
                            <template x-for="m in medios" :key="m.sitio_id">
                                <div class="rounded-xl border p-3" :class="sel[m.sitio_id]?.marcada ? 'border-emerald-300 bg-emerald-50/40' : 'border-gray-200 bg-white'">
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="checkbox" :checked="sel[m.sitio_id]?.marcada" @change="setSel(m.sitio_id, {marcada: $event.target.checked})" :disabled="publicando" class="mt-0.5 rounded border-gray-300 text-emerald-600">
                                        <span class="min-w-0"><span class="block text-sm font-semibold text-gray-900" x-text="m.sitio_nombre"></span>
                                            <span class="block text-xs text-gray-500" x-text="m.linea_nombre + ' · ' + resumenRedes(m)"></span></span>
                                    </label>
                                    <div x-show="sel[m.sitio_id]?.marcada" class="mt-2 pl-6 space-y-2">
                                        <div x-show="paginasDe(m).length" class="flex flex-wrap gap-2 text-xs font-semibold">
                                            <span class="rounded-full bg-gray-100 text-gray-600 px-2.5 py-1">🌐 {{ __('Web') }}</span>
                                            <button type="button" @click="setSel(m.sitio_id, {facebook: !sel[m.sitio_id].facebook})" :disabled="publicando" class="rounded-full px-2.5 py-1" :class="sel[m.sitio_id]?.facebook ? 'bg-sky-100 text-sky-800' : 'bg-gray-100 text-gray-500'"><span x-text="sel[m.sitio_id]?.facebook ? '✓ ' : ''"></span>📘 Facebook</button>
                                            <button type="button" x-show="paginasDe(m).some(p => p.instagram)" @click="setSel(m.sitio_id, {instagram: !sel[m.sitio_id].instagram})" :disabled="publicando" class="rounded-full px-2.5 py-1" :class="sel[m.sitio_id]?.instagram ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-500'"><span x-text="sel[m.sitio_id]?.instagram ? '✓ ' : ''"></span>📸 Instagram</button>
                                        </div>
                                        <div x-show="paginasDe(m).length > 1" class="flex flex-wrap gap-1.5">
                                            <template x-for="p in paginasDe(m)" :key="p.id">
                                                <button type="button" @click="alternarPagina(m.sitio_id, p.id)" :disabled="publicando" class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                                        :class="(sel[m.sitio_id]?.excluidas || []).includes(p.id) ? 'bg-gray-100 text-gray-400 line-through' : 'bg-emerald-100 text-emerald-800'"
                                                        x-text="((sel[m.sitio_id]?.excluidas || []).includes(p.id) ? '✕ ' : '✓ ') + p.nombre"></button>
                                            </template>
                                        </div>
                                        <div x-show="!mismaCategoria">
                                            <label class="{{ $lbl }}">{{ __('Categoría en') }} <span x-text="m.sitio_nombre"></span></label>
                                            <select class="{{ $inp }} h-9" :disabled="publicando" @change="categoriaPorMedio[m.sitio_id] = $event.target.value; vistaPrevia = null">
                                                <template x-for="o in opcionesCategoriaMedio(m)" :key="o.v"><option :value="o.v" x-text="o.t" :selected="o.v === categoriaDe(m)"></option></template>
                                            </select>
                                        </div>
                                        <p x-show="mismaCategoria" class="text-xs text-gray-500"><span>{{ __('Categoría:') }}</span> <span x-text="categoriaDe(m)"></span> <span x-show="!categoriaDelMedio(m, categoriaDe(m))" x-text="T.seCrea"></span></p>

                                        <div x-show="plantilla && tipoMedio === 'foto'" class="flex items-center gap-2 text-xs">
                                            <span class="text-gray-500 shrink-0">{{ __('Plantilla:') }}</span>
                                            <select class="h-8 flex-1 rounded-lg border-gray-200 text-xs" :disabled="publicando" @change="plantillaPorMedio = {...plantillaPorMedio, [m.sitio_id]: $event.target.value}; vistaPrevia = null">
                                                <template x-for="p in opcionesPlantillaMedio(m)" :key="p.id"><option :value="p.id" x-text="p.nombre + (p.tipo === 'marco' ? ' · ' + T.marco : '')" :selected="plantillaDe(m)?.id === p.id"></option></template>
                                            </select>
                                        </div>

                                        {{-- Logo del medio (con plantilla de diseño) --}}
                                        <template x-if="plantilla && tipoMedio === 'foto' && plantillaDe(m)?.tipo !== 'marco'">
                                            <div class="border-t border-gray-100 pt-2">
                                                <template x-if="editandoLogo !== m.sitio_id">
                                                    <button type="button" @click="editarLogo(m)" :disabled="publicando" class="w-full flex items-center gap-2 text-left text-xs text-gray-700">
                                                        <span class="h-3.5 w-3.5 rounded-full border border-black/10" :style="'background:' + logoDe(m).color_logo"></span>
                                                        <span class="flex-1 truncate">{{ __('Logo:') }} <b x-text="logoDe(m).logo_texto"></b> · <span x-text="logoDe(m).pie + ' ' + logoDe(m).hashtag"></span> <span x-show="!logos[m.slug]" x-text="T.propuesto"></span></span>
                                                        <span class="font-bold text-sky-700">{{ __('Editar') }}</span>
                                                    </button>
                                                </template>
                                                <template x-if="editandoLogo === m.sitio_id">
                                                    <div class="space-y-2">
                                                        <div><label class="{{ $lbl }}">{{ __('Texto del logo') }}</label><input type="text" x-model="logoEdit.logo_texto" maxlength="40" class="{{ $inp }} h-9" placeholder="OPA Noticias, NEIVA 24…">
                                                            <p class="text-[11px] text-gray-400 mt-0.5">{{ __('La primera palabra va letra por letra en cajas de color y el resto al lado.') }}</p></div>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <div><label class="{{ $lbl }}">{{ __('Pie (sitio web)') }}</label><input type="text" x-model="logoEdit.pie" maxlength="40" class="{{ $inp }} h-9" placeholder="opanoticias.com"></div>
                                                            <div><label class="{{ $lbl }}">{{ __('Hashtag') }}</label><input type="text" x-model="logoEdit.hashtag" maxlength="30" class="{{ $inp }} h-9" placeholder="#EsNoticia"></div>
                                                            <div><label class="{{ $lbl }}">{{ __('Color del logo') }}</label><input type="color" x-model="logoEdit.color_logo" class="h-9 w-full rounded-lg border border-gray-200"></div>
                                                            <div><label class="{{ $lbl }}">{{ __('Color del título') }}</label><input type="color" x-model="logoEdit.color_titulo" class="h-9 w-full rounded-lg border border-gray-200"></div>
                                                        </div>
                                                        <div class="flex flex-wrap gap-2">
                                                            <button type="button" @click="guardarLogo(m)" class="h-8 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white">{{ __('Guardar logo de') }} <span x-text="m.sitio_nombre"></span></button>
                                                            <button type="button" @click="editandoLogo = null" class="h-8 rounded-lg border border-gray-200 px-3 text-xs font-semibold text-gray-700">{{ __('Cancelar') }}</button>
                                                            <button type="button" x-show="logos[m.slug]" @click="restablecerLogo(m)" class="h-8 px-2 text-xs font-semibold text-gray-500">{{ __('Restablecer') }}</button>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <p x-show="medios.length" class="mt-3 text-[11px] text-gray-500">{{ __('Qué página de Facebook / Instagram tiene cada medio se define en App del editor → Medio de cada página. El logo de cada medio se guarda en este navegador.') }}</p>
                    </div>
                </div>

                {{-- Columna derecha: plantilla, vista previa y publicar --}}
                <div class="space-y-5 lg:sticky lg:top-24 min-w-0">
                    <div x-show="tipoMedio === 'foto'" class="{{ $card }}">
                        <h3 class="{{ $h3 }}">🖼️ {{ __('Plantilla de la imagen') }}</h3>
                        <label class="flex items-start gap-2 mb-3 cursor-pointer rounded-xl border p-3" :class="usarPlantilla ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200'">
                            <input type="checkbox" x-model="usarPlantilla" @change="vistaPrevia = null" :disabled="publicando" class="mt-0.5 rounded border-gray-300 text-emerald-600">
                            <span class="text-sm"><span class="font-semibold text-gray-900" x-text="usarPlantilla ? @js(__('Publicar con plantilla')) : @js(__('Publicar la foto tal cual (sin plantilla)'))"></span>
                                <span class="block text-xs text-gray-500" x-text="usarPlantilla ? @js(__('Logo del medio, categoría, título y pie sobre la foto')) : @js(__('Marca para agregar logo, categoría y título a la foto'))"></span></span>
                        </label>
                        <div x-show="usarPlantilla">
                            <div class="{{ $lbl }}">{{ __('Diseño general') }} <span class="font-normal text-gray-400">{{ __('(los medios con marco propio usan el suyo)') }}</span></div>
                            <div class="flex gap-2 overflow-x-auto pb-1">
                                <template x-for="p in plantillas" :key="p.id">
                                    <button type="button" @click="plantillaId = p.id; vistaPrevia = null; if (p.medio) { const md = medios.find(m => m.slug === p.medio); if (md) medioPrevId = md.sitio_id; }" :disabled="publicando" class="relative w-28 shrink-0 rounded-xl border-2 p-1.5 text-left" :class="plantillaId === p.id ? 'border-emerald-500 bg-emerald-50' : 'border-gray-200 bg-white'">
                                        <template x-if="p.muestra_url"><img :src="p.muestra_url" alt="" class="h-32 w-full rounded-lg object-cover bg-gray-800"></template>
                                        <div x-show="!p.muestra_url" class="h-32 w-full rounded-lg bg-gray-800 flex items-center justify-center text-center text-xs font-black text-white px-1" x-text="p.logo_texto || p.nombre"></div>
                                        <div class="mt-1 text-xs font-bold text-gray-800 truncate" x-text="p.nombre"></div>
                                        <div class="text-[10px] text-gray-500" x-text="p.origen === 'sistema' ? @js(__('Del sistema')) : @js(__('De editus'))"></div>
                                        <span x-show="plantillaId === p.id" class="absolute top-2 right-2 rounded-full bg-white text-emerald-600 text-sm leading-none">✅</span>
                                    </button>
                                </template>
                            </div>
                            <p x-show="!plantillas.length && !cargando" class="text-xs text-gray-500">{{ __('No hay plantillas disponibles. Se usará la foto tal cual.') }}</p>
                        </div>

                        <div x-show="plantillaDe(medioPreview())" class="mt-4">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <div class="{{ $lbl }} !mb-0">{{ __('Vista previa') }} {{ __('(se actualiza sola)') }}</div>
                                <select x-show="mediosMarcados().length > 1" class="h-8 rounded-lg border-gray-200 text-xs" @change="medioPrevId = Number($event.target.value); vistaPrevia = null">
                                    <template x-for="m in mediosMarcados()" :key="m.sitio_id"><option :value="m.sitio_id" x-text="m.sitio_nombre + ' · ' + (plantillaDe(m)?.nombre || '')" :selected="medioPreview()?.sitio_id === m.sitio_id"></option></template>
                                </select>
                            </div>
                            <div class="relative rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 aspect-[4/5] overflow-hidden flex items-center justify-center">
                                <template x-if="vistaPrevia"><img :src="vistaPrevia" alt="" class="absolute inset-0 h-full w-full object-contain bg-gray-900"></template>
                                <div x-show="!vistaPrevia && !previsualizando" class="px-6 text-center text-xs text-gray-500" x-text="imagen ? @js(__('Generando la vista previa…')) : @js(__('Elige la foto para ver cómo queda'))"></div>
                                <div x-show="previsualizando" class="absolute inset-x-0 bottom-0 bg-black/60 text-white text-xs text-center py-1.5">{{ __('Actualizando…') }}</div>
                            </div>
                            <p class="mt-2 text-[11px] text-gray-500">{{ __('Es exactamente la imagen que compone el servidor. Cada medio sale con su propio logo y su categoría; aquí se muestra el primero marcado.') }}</p>
                        </div>
                    </div>

                    <div class="{{ $card }}">
                        <div class="text-xs text-gray-500 mb-3">{{ __('Campaña') }}: <b class="text-gray-800">Esnoticia</b> · {{ __('Firma') }}: <b class="text-gray-800" x-text="nombreAutor()"></b></div>
                        <button type="button" @click="publicar()" :disabled="publicando"
                                class="w-full h-12 rounded-xl text-base font-bold text-white shadow-sm"
                                :class="puedePublicar() ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-emerald-300'"
                                x-text="publicando ? (tipoMedio === 'video' ? T.publicandoVideo : T.publicando) : T.publicarEn.replace(':m', mediosMarcados().length).replace(':r', totalRedes())"></button>
                        <p x-show="publicando" class="mt-2 text-center text-xs text-gray-500">{{ __('Puede tardar hasta un minuto por medio: la web primero, luego Facebook e Instagram.') }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- ======================= YA EN REDES ======================= --}}
        <div x-show="vista === 'video' && autor" style="display:none">
            <template x-if="!vElegido">
                <div class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">🎬 {{ __('Videos ya publicados en redes') }}</h3>
                            <p class="text-xs text-gray-500">{{ __('Elige un video que ya está en Facebook o Instagram y publícalo en la web de los medios (como video embebido). No se sube ningún archivo.') }}</p>
                        </div>
                        <div class="flex gap-2">
                            <select x-model="vFiltro" class="{{ $inp }} h-9 w-56"><option value="">{{ __('Todas las páginas') }}</option><template x-for="p in vPaginas" :key="p.page_id"><option :value="p.page_id" x-text="p.nombre"></option></template></select>
                            <button type="button" @click="cargarVideos()" class="h-9 rounded-xl border border-gray-200 px-3 text-xs font-semibold text-gray-700">{{ __('Actualizar') }}</button>
                        </div>
                    </div>
                    <div x-show="vCargando" class="py-8 text-center text-sm text-gray-500">{{ __('Cargando videos…') }}</div>
                    <p x-show="vError" class="rounded-lg bg-rose-50 text-rose-800 text-sm px-3 py-2" x-text="vError"></p>
                    <p x-show="vAviso" class="rounded-lg bg-amber-50 text-amber-900 text-xs px-3 py-2 mb-2" x-text="vAviso"></p>
                    <div class="space-y-4">
                        <template x-for="p in vPaginas.filter(x => !vFiltro || x.page_id === vFiltro)" :key="p.page_id">
                            <div x-show="p.videos && p.videos.length">
                                <div class="text-xs font-bold uppercase text-gray-500 mb-2" x-text="p.nombre"></div>
                                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                    <template x-for="v in p.videos" :key="v.red + v.id">
                                        <button type="button" @click="elegirVideoRed(p, v)" class="flex gap-3 rounded-xl border border-gray-200 bg-white p-2 text-left hover:border-indigo-300 hover:bg-indigo-50/40">
                                            <template x-if="v.miniatura"><img :src="v.miniatura" alt="" class="h-20 w-16 rounded-lg object-cover bg-gray-100"></template>
                                            <div x-show="!v.miniatura" class="h-20 w-16 rounded-lg bg-gray-100 flex items-center justify-center">🎬</div>
                                            <div class="min-w-0">
                                                <div class="text-[11px] font-semibold" :class="v.red === 'instagram' ? 'text-purple-700' : 'text-sky-700'" x-text="(v.red === 'instagram' ? '📸 Instagram' : '📘 Facebook') + (v.tipo === 'reel' ? ' · Reel' : '') + (v.duracion ? ' · ' + fmtDuracion(v.duracion) : '')"></div>
                                                <div class="text-sm font-semibold text-gray-900 line-clamp-2" x-text="v.titulo || v.descripcion || '—'"></div>
                                                <div class="text-[11px] text-gray-400" x-text="fmtFecha(v.fecha)"></div>
                                            </div>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <p x-show="!vCargando && !vError && !vPaginas.some(p => p.videos && p.videos.length)" class="py-8 text-center text-sm text-gray-500">{{ __('No hay videos recientes en las páginas.') }}</p>
                    </div>
                </div>
            </template>
            <template x-if="vElegido">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)] gap-5 items-start">
                    <div class="{{ $card }} space-y-3">
                        <h3 class="text-sm font-bold text-gray-900">{{ __('Nota en la web') }}</h3>
                        <div><div class="flex items-end justify-between"><label class="{{ $lbl }}">{{ __('Título') }} *</label><span class="text-[11px] text-gray-400" x-text="vTitulo.length + '/150'"></span></div>
                            <input type="text" x-model="vTitulo" maxlength="150" :disabled="vPublicando" class="{{ $inp }} h-10"></div>
                        <div><label class="{{ $lbl }}">{{ __('Texto') }}</label>
                            <textarea x-model="vTexto" rows="6" maxlength="6000" :disabled="vPublicando" class="{{ $inp }}" placeholder="{{ __('Texto de la nota (viene de la descripción del video; puedes editarlo)') }}"></textarea></div>
                        <div><label class="{{ $lbl }}">{{ __('Categoría') }}</label>
                            <select x-model="vCategoria" :disabled="vPublicando" class="{{ $inp }} h-10"><template x-for="o in opcionesCategoriaVideo()" :key="o"><option :value="o" x-text="o" :selected="o === vCategoria"></option></template></select></div>
                        <div>
                            <div class="{{ $lbl }}">{{ __('Medios') }} *</div>
                            <div class="grid sm:grid-cols-2 gap-2">
                                <template x-for="m in medios" :key="m.sitio_id">
                                    <label class="flex items-center gap-2 rounded-xl border px-3 py-2 text-sm cursor-pointer" :class="vMedios[m.sitio_id] ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200'">
                                        <input type="checkbox" x-model="vMedios[m.sitio_id]" :disabled="vPublicando" class="rounded border-gray-300 text-emerald-600"><span x-text="m.sitio_nombre"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div class="{{ $card }}">
                            <div class="flex gap-3">
                                <template x-if="vElegido.video.miniatura"><img :src="vElegido.video.miniatura" alt="" class="h-28 w-20 rounded-lg object-cover"></template>
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold" :class="vElegido.video.red === 'instagram' ? 'text-purple-700' : 'text-sky-700'" x-text="(vElegido.video.red === 'instagram' ? '📸 Instagram' : '📘 Facebook') + (vElegido.video.tipo === 'reel' ? ' · Reel' : '')"></div>
                                    <div class="text-sm font-bold text-gray-900" x-text="vElegido.pagina.nombre"></div>
                                    <div class="text-xs text-gray-400" x-text="fmtFecha(vElegido.video.fecha)"></div>
                                    <a :href="vElegido.video.permalink" target="_blank" rel="noopener" class="text-xs font-semibold text-indigo-700 hover:underline">{{ __('Ver en la red') }} ↗</a>
                                </div>
                            </div>
                            <button type="button" @click="vElegido = null; vResultado = null" :disabled="vPublicando" class="mt-3 h-9 rounded-xl border border-gray-200 px-3 text-xs font-semibold text-gray-700">← {{ __('Elegir otro video') }}</button>
                        </div>
                        <button type="button" @click="publicarVideoRed()" :disabled="vPublicando" class="w-full h-12 rounded-xl text-base font-bold text-white shadow-sm" :class="vTitulo.trim() && medios.some(m => vMedios[m.sitio_id]) ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-emerald-300'"
                                x-text="vPublicando ? T.publicando : @js(__('Publicar en la web'))"></button>
                        <template x-if="vResultado">
                            <div class="{{ $card }}" :class="vResultado.success ? 'border-emerald-200' : 'border-rose-200'">
                                <div class="text-sm font-bold" :class="vResultado.success ? 'text-emerald-700' : 'text-rose-700'" x-text="vResultado.titulo"></div>
                                <p x-show="vResultado.error" class="text-xs text-rose-700" x-text="vResultado.error"></p>
                                <div x-html="htmlResultados({web: vResultado.web, paginas: []})"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        {{-- ======================= HISTORIAL ======================= --}}
        <div x-show="vista === 'historial' && autor" style="display:none">
            <div x-show="cargandoHistorial" class="py-10 text-center text-sm text-gray-500">{{ __('Cargando historial…') }}</div>
            <p x-show="!cargandoHistorial && !historial.length" class="py-10 text-center text-sm text-gray-500">📭 {{ __('Este periodista todavía no ha publicado en redes.') }}</p>
            <div class="grid md:grid-cols-2 gap-4">
                <template x-for="h in historial" :key="h.id">
                    <div class="{{ $card }}">
                        <div class="flex gap-3">
                            <template x-if="h.imagen_url"><img :src="h.imagen_url" alt="" class="h-20 w-20 rounded-lg object-cover bg-gray-100"></template>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="h.estado === 'publicado' ? 'bg-emerald-50 text-emerald-700' : (h.estado === 'fallido' ? 'bg-rose-50 text-rose-700' : 'bg-gray-100 text-gray-600')" x-text="T.estado[h.estado] || h.estado"></span>
                                    <span class="ml-auto text-[11px] text-gray-400" x-text="fmtFecha(h.creado_en)"></span>
                                </div>
                                <div class="mt-1 text-sm font-bold text-gray-900" x-text="h.titulo"></div>
                                <div class="text-xs text-gray-500 line-clamp-2" x-text="h.texto"></div>
                            </div>
                        </div>
                        <div x-html="htmlResultados(h.resultados || {})"></div>
                    </div>
                </template>
            </div>
        </div>
    @endunless
</div>

<script>
function redesWeb() {
    const T = @js($T);
    const R = {
        catalogos: @js(route('redes.catalogos')), previsualizar: @js(route('redes.previsualizar')), publicar: @js(route('redes.publicar')),
        historial: @js(route('redes.historial')), autorizar: @js(route('redes.video.autorizar')), videos: @js(route('redes.videos')), publicarVideo: @js(route('redes.publicar-video')),
    };
    const AUTORES = @js(collect($autores)->mapWithKeys(fn($a) => [(string) $a['id'] => $a['username']]));
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const leer = (k) => { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } };
    const guardar = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
    const clave = (s) => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]/g, '');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const dominio = (url) => { const m = /^(?:https?:\/\/)?(?:www\.)?([^/:?#]+)/i.exec((url || '').trim()); return m ? m[1].toLowerCase() : ''; };

    return {
        T,
        autor: String(leer('redesAutorWeb') || @js($autorSugerido ? (string) $autorSugerido : '')),
        vista: 'nueva', aviso: {texto: '', tono: 'ok'}, avisoTimer: null,
        cargando: false, errores: {}, medios: [], paginas: [], plantillas: [], categoriaDefecto: 'Opas',
        tipoMedio: 'foto', imagen: null, video: null, videoId: null, subiendo: null,
        titulo: '', texto: '',
        mismaCategoria: true, categoriaGlobal: '', categoriaPorMedio: {},
        sel: {}, logos: leer('redesLogosPorMedio') || {}, editandoLogo: null, logoEdit: {}, plantillaPorMedio: {}, medioPrevId: null,
        usarPlantilla: true, plantillaId: '', vistaPrevia: null, firmaPrevia: '', previsualizando: false, previaTimer: null,
        publicando: false, resultado: null,
        historial: [], cargandoHistorial: false,
        vPaginas: [], vCargando: false, vError: '', vAviso: '', vFiltro: '', vElegido: null, vTitulo: '', vTexto: '', vCategoria: 'Videos', vCategoriaVideos: 'Videos', vMedios: {}, vPublicando: false, vResultado: null,

        iniciar() {
            if (this.autor && !AUTORES[this.autor]) this.autor = '';
            const aj = leer('redesAjustesWeb') || {};
            this.usarPlantilla = aj.usarPlantilla !== false;
            this.mismaCategoria = aj.mismaCategoria !== false;
            this.$watch('usarPlantilla', () => this.recordar());
            this.$watch('plantillaId', () => this.recordar());
            this.$watch('mismaCategoria', () => this.recordar());
            this.$watch('categoriaGlobal', () => this.recordar());
            this.$watch(() => this.firma(), () => this.programarPrevia());
            if (this.autor) this.cargarCatalogos(aj);
        },
        recordar() { guardar('redesAjustesWeb', {usarPlantilla: this.usarPlantilla, plantillaId: this.plantillaId, mismaCategoria: this.mismaCategoria, categoriaGlobal: this.categoriaGlobal}); },
        avisar(texto, tono = 'ok', ms = 4000) { this.aviso = {texto, tono}; clearTimeout(this.avisoTimer); this.avisoTimer = setTimeout(() => this.aviso = {texto: '', tono}, ms); },
        nombreAutor() { return AUTORES[this.autor] || '—'; },
        cambiarAutor() {
            guardar('redesAutorWeb', this.autor);
            this.historial = []; this.vPaginas = [];
            if (this.autor) { this.cargarCatalogos(); if (this.vista === 'historial') this.cargarHistorial(); if (this.vista === 'video') this.cargarVideos(); }
        },
        cambiarVista(v) { this.vista = v; if (!this.autor) return; if (v === 'historial') this.cargarHistorial(); if (v === 'video' && !this.vPaginas.length) this.cargarVideos(); },

        async api(url, opciones = {}) {
            const r = await fetch(url, {
                ...opciones,
                headers: {'Accept': 'application/json', 'X-Autor': this.autor, 'X-CSRF-TOKEN': CSRF, ...(opciones.body ? {'Content-Type': 'application/json'} : {}), ...(opciones.headers || {})},
            });
            let data = null; try { data = await r.json(); } catch (e) {}
            return {ok: r.ok, status: r.status, data};
        },

        // ---------------- Catálogos ----------------
        async cargarCatalogos(aj = null) {
            this.cargando = true; this.errores = {};
            try {
                const {data} = await this.api(R.catalogos);
                if (!data || !data.success) { this.errores = {general: data?.error || T.errorConexion}; return; }
                this.medios = data.medios || []; this.paginas = data.paginas || []; this.plantillas = data.plantillas || [];
                this.categoriaDefecto = data.categoria || 'Opas'; this.errores = data.errores || {};
                const s = {...this.sel};
                this.medios.forEach(m => { if (!s[m.sitio_id]) s[m.sitio_id] = {marcada: true, facebook: true, instagram: true, excluidas: []}; });
                this.sel = s;
                aj = aj || leer('redesAjustesWeb') || {};
                if (!this.categoriaGlobal) this.categoriaGlobal = aj.categoriaGlobal || this.categoriaDefecto;
                const deseada = this.plantillaId || aj.plantillaId || '';
                this.plantillaId = (deseada && this.plantillas.some(p => p.id === deseada)) ? deseada : ((this.plantillas.find(p => p.predeterminada) || this.plantillas[0])?.id || '');
            } catch (e) { this.errores = {general: T.errorConexion}; }
            finally { this.cargando = false; }
        },

        // ---------------- Derivados ----------------
        get plantilla() { return this.usarPlantilla ? (this.plantillas.find(p => p.id === this.plantillaId) || null) : null; },
        /** Plantilla con la que sale cada medio: la elegida para él o, si no, su propio marco (o la general). */
        plantillaDe(m) {
            const g = this.plantilla; if (!g || !m) return null;
            const elegida = this.plantillaPorMedio[m.sitio_id];
            if (elegida) { const p = this.plantillas.find(x => x.id === elegida); if (p) return p; }
            const propias = this.plantillas.filter(p => p.medio && p.medio === m.slug);
            if (g.medio) return g.medio === m.slug ? g : (propias[0] || this.plantillas.find(p => !p.medio && p.predeterminada) || this.plantillas.find(p => !p.medio) || null);
            return propias[0] || g;
        },
        opcionesPlantillaMedio(m) { return this.plantillas.filter(p => !p.medio || p.medio === m.slug); },
        paginasDe(m) {
            const asignadas = this.paginas.filter(p => p.medio && p.medio === m.slug);
            if (asignadas.length) return asignadas;
            const km = clave(m.sitio_nombre), ks = clave(m.slug);
            return this.paginas.filter(p => { if (p.medio) return false; const k = clave(p.nombre); return k && (k.includes(km) || km.includes(k) || k.includes(ks) || ks.includes(k)); });
        },
        resumenRedes(m) { const pags = this.paginasDe(m); return pags.length ? '📘 ' + pags.map(p => p.nombre).join(', ') + (pags.some(p => p.instagram) ? ' · 📸 Instagram' : '') : T.soloWeb; },
        categoriaDelMedio(m, nombre) { const k = clave(nombre); return (m.categorias || []).find(c => clave(c.nombre) === k); },
        categoriaDe(m) { return (this.mismaCategoria ? this.categoriaGlobal : (this.categoriaPorMedio[m.sitio_id] || this.categoriaGlobal)) || this.categoriaDefecto; },
        opcionesCategoriaGlobal() {
            const vistas = new Map();
            this.medios.forEach(s => (s.categorias || []).forEach(c => { const k = clave(c.nombre); if (k && !vistas.has(k)) vistas.set(k, c.nombre); }));
            [this.categoriaDefecto, this.categoriaGlobal].forEach(x => { if (x && !vistas.has(clave(x))) vistas.set(clave(x), x); });
            return Array.from(vistas.values()).sort((a, b) => a.localeCompare(b));
        },
        opcionesCategoriaMedio(m) {
            const ops = (m.categorias || []).map(c => ({v: c.nombre, t: c.nombre}));
            const act = this.categoriaDe(m);
            return ops.some(o => clave(o.v) === clave(act)) ? ops : [{v: act, t: act + ' ' + T.seCreaOpcion}, ...ops];
        },
        logoDe(m) {
            return this.logos[m.slug] || {
                logo_texto: m.sitio_nombre, color_logo: this.plantilla?.color_logo || '#FFFFFF', color_titulo: this.plantilla?.color_titulo || '#FFFFFF',
                pie: dominio(m.url) || (m.slug + '.com'), hashtag: this.plantilla?.hashtag || '#EsNoticia',
            };
        },
        mediosMarcados() { return this.medios.filter(m => this.sel[m.sitio_id]?.marcada); },
        paginasElegidas(m) {
            const s = this.sel[m.sitio_id] || {};
            return this.paginasDe(m).filter(p => !(s.excluidas || []).includes(p.id))
                .map(p => ({id: p.id, page_id: p.page_id, nombre: p.nombre, facebook: !!s.facebook, instagram: !!(s.instagram && p.instagram)}))
                .filter(p => p.facebook || p.instagram);
        },
        totalRedes() { return this.mediosMarcados().reduce((n, m) => n + this.paginasElegidas(m).length, 0); },
        /** Medio de la vista previa: el elegido, o el del diseño elegido (si es de un medio), o el primero marcado. */
        medioPreview() {
            const marcados = this.mediosMarcados();
            const elegido = marcados.find(m => m.sitio_id === this.medioPrevId);
            if (elegido) return elegido;
            const g = this.plantilla;
            if (g && g.medio) { const delDiseno = marcados.find(m => m.slug === g.medio); if (delDiseno) return delDiseno; }
            return marcados[0] || this.medios[0] || null;
        },
        resumen() {
            const limpio = (this.texto || '').replace(/\s+/g, ' ').trim();
            let r = limpio;
            if (limpio.length > 280) {
                const corte = limpio.slice(0, 280); const frase = corte.match(/^(.{80,}[.!?])\s/s);
                if (frase) r = frase[1].trim(); else { const esp = corte.lastIndexOf(' '); r = (esp > 120 ? corte.slice(0, esp) : corte).replace(/[\s,;:\-]+$/g, '') + '…'; }
            }
            return r.slice(0, 200);
        },
        puedePublicar() {
            const v = this.tipoMedio === 'video';
            return !this.publicando && this.subiendo === null && this.titulo.trim() && this.texto.trim() && (v ? !!this.videoId : !!this.imagen) && this.mediosMarcados().length > 0 && (!v || this.totalRedes() > 0);
        },

        // ---------------- Selección ----------------
        setSel(id, cambio) { this.sel = {...this.sel, [id]: {...(this.sel[id] || {marcada: false, facebook: true, instagram: true, excluidas: []}), ...cambio}}; this.vistaPrevia = null; },
        marcarTodos(v) { const s = {...this.sel}; this.medios.forEach(m => s[m.sitio_id] = {...(s[m.sitio_id] || {facebook: true, instagram: true, excluidas: []}), marcada: v}); this.sel = s; this.vistaPrevia = null; },
        alternarPagina(id, pid) { const ex = this.sel[id]?.excluidas || []; this.setSel(id, {excluidas: ex.includes(pid) ? ex.filter(x => x !== pid) : [...ex, pid]}); },
        editarLogo(m) { this.logoEdit = {...this.logoDe(m)}; this.editandoLogo = m.sitio_id; },
        guardarLogo(m) {
            const l = {...this.logoEdit, logo_texto: (this.logoEdit.logo_texto || '').trim() || m.sitio_nombre, pie: (this.logoEdit.pie || '').trim(), hashtag: (this.logoEdit.hashtag || '').trim()};
            this.logos = {...this.logos, [m.slug]: l}; guardar('redesLogosPorMedio', this.logos); this.editandoLogo = null; this.vistaPrevia = null; this.avisar(T.logoGuardado);
        },
        restablecerLogo(m) { const n = {...this.logos}; delete n[m.slug]; this.logos = n; guardar('redesLogosPorMedio', n); this.editandoLogo = null; this.vistaPrevia = null; },

        // ---------------- Foto y video ----------------
        leerFoto(f) {
            if (!f) return;
            if (!/^image\//.test(f.type)) return this.avisar(T.imagenError, 'fallo');
            const url = URL.createObjectURL(f);
            const img = new Image();
            img.onload = () => {
                const f = Math.min(1, 1600 / img.width);
                const c = document.createElement('canvas'); c.width = Math.round(img.width * f); c.height = Math.round(img.height * f);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                this.imagen = c.toDataURL('image/jpeg', 0.88); this.vistaPrevia = null; URL.revokeObjectURL(url);
            };
            img.onerror = () => { URL.revokeObjectURL(url); this.avisar(T.imagenError, 'fallo'); };
            img.src = url;
        },
        async elegirVideo(f) {
            if (!f) return;
            this.video = {nombre: f.name, tamano: f.size, duracion: 0}; this.videoId = null; this.subiendo = 0;
            try {
                const dur = await new Promise(res => { const v = document.createElement('video'); v.preload = 'metadata'; v.onloadedmetadata = () => { res(Math.round(v.duration || 0)); URL.revokeObjectURL(v.src); }; v.onerror = () => res(0); v.src = URL.createObjectURL(f); });
                this.video = {...this.video, duracion: dur};
                const {data: aut, status} = await this.api(R.autorizar);
                if (!aut?.success || !aut.upload_url) throw new Error(aut?.error || ('HTTP ' + status));
                if (aut.max_mb && f.size > aut.max_mb * 1024 * 1024) throw new Error(T.videoMax.replace(':n', aut.max_mb));
                const subido = await new Promise((res, rej) => {
                    const form = new FormData();
                    Object.entries(aut.campos || {}).forEach(([k, v]) => form.append(k, String(v)));
                    form.append('video', f, f.name);
                    const x = new XMLHttpRequest(); x.open('POST', aut.upload_url); x.setRequestHeader('Accept', 'application/json'); x.timeout = 15 * 60 * 1000;
                    x.upload.onprogress = (ev) => { if (ev.lengthComputable) this.subiendo = ev.loaded / ev.total; };
                    x.onload = () => {
                        let d = null; try { d = JSON.parse(x.responseText); } catch (e) {}
                        if (x.status >= 200 && x.status < 300 && d?.success && d.video_id) return res(d);
                        if (x.status === 413) return rej(new Error(T.videoMax.replace(':n', aut.max_mb || '')));
                        rej(new Error(T.videoError + ': ' + (d?.error || (d?.errors ? Object.values(d.errors).flat().join(' ') : '') || ('HTTP ' + x.status))));
                    };
                    x.onerror = () => rej(new Error(T.errorConexion)); x.ontimeout = () => rej(new Error(T.videoError));
                    x.send(form);
                });
                this.videoId = subido.video_id; this.subiendo = null; this.avisar(T.videoListo);
            } catch (e) { this.subiendo = null; this.video = null; this.videoId = null; this.avisar(e.message || T.videoError, 'fallo', 7000); }
        },
        quitarVideo() { this.video = null; this.videoId = null; this.subiendo = null; },

        // ---------------- Vista previa (la imagen exacta del servidor) ----------------
        firma() {
            const m = this.medioPreview();
            const pl = this.plantillaDe(m);
            return this.tipoMedio === 'foto' && pl && this.imagen && m
                ? JSON.stringify({p: pl.id, t: this.titulo.trim(), e: this.categoriaDe(m), l: this.logoDe(m), i: this.imagen.length}) : '';
        },
        programarPrevia() {
            clearTimeout(this.previaTimer);
            const f = this.firma();
            if (!f) { this.vistaPrevia = null; return; }
            if (f === this.firmaPrevia && this.vistaPrevia) return;
            this.previaTimer = setTimeout(() => this.previsualizar(f), 900);
        },
        async previsualizar(f) {
            const m = this.medioPreview(); const pl = this.plantillaDe(m); if (!m || !pl || !this.imagen) return;
            this.previsualizando = true;
            try {
                const {data} = await this.api(R.previsualizar, {method: 'POST', body: JSON.stringify({imagen: this.imagen, texto: this.texto.trim(), plantilla_id: pl.id, titulo: this.titulo.trim() || T.tituloPrevia, etiqueta: this.categoriaDe(m), ...this.logoDe(m)})});
                if (f !== this.firma()) return;
                if (data?.success && data.imagen) { this.vistaPrevia = data.imagen; this.firmaPrevia = f; }
                else this.avisar(data?.error || T.errorConexion, 'fallo', 6000);
            } catch (e) { this.avisar(T.errorConexion, 'fallo'); }
            finally { this.previsualizando = false; }
        },

        // ---------------- Publicar ----------------
        async publicar() {
            const v = this.tipoMedio === 'video';
            if (!this.puedePublicar()) {
                if (this.publicando) return;
                if (this.subiendo !== null) return this.avisar(T.esperaVideo, 'atencion');
                if (v && !this.videoId) return this.avisar(T.eligeVideo, 'atencion');
                if (!v && !this.imagen) return this.avisar(T.eligeFoto, 'atencion');
                if (!this.titulo.trim()) return this.avisar(T.escribeTitulo, 'atencion');
                if (!this.texto.trim()) return this.avisar(T.escribeTexto, 'atencion');
                if (!this.mediosMarcados().length) return this.avisar(T.eligeMedio, 'atencion');
                if (v && !this.totalRedes()) return this.avisar(T.videoSinRedes, 'atencion', 6000);
                return;
            }
            const lista = this.mediosMarcados().map(m => {
                const nombreCat = this.categoriaDe(m); const cat = this.categoriaDelMedio(m, nombreCat);
                return {sitio_id: m.sitio_id, slug: m.slug, sitio_nombre: m.sitio_nombre, linea_editorial: m.linea_nombre,
                    categoria_id: cat ? (cat.id || 1) : null, categoria: cat?.nombre || nombreCat,
                    logo: this.plantilla ? this.logoDe(m) : undefined, plantilla_id: this.plantillaDe(m)?.id || undefined, paginas: this.paginasElegidas(m)};
            });
            const cuerpo = v ? {titulo: this.titulo.trim(), texto: this.texto.trim(), video_id: this.videoId, medios: lista}
                             : {titulo: this.titulo.trim(), texto: this.texto.trim(), imagen: this.imagen, medios: lista, plantilla: this.plantilla ? {id: this.plantilla.id} : undefined};
            this.publicando = true; this.resultado = null;
            try {
                const {data} = await this.api(R.publicar, {method: 'POST', body: JSON.stringify(cuerpo)});
                if (!data) throw new Error(T.sinRespuesta);
                const res = {web: data.resultados?.web || [], paginas: data.resultados?.paginas || []};
                if (data.success) {
                    const webs = res.web.filter(r => r.ok).length;
                    const redes = res.paginas.filter(r => r.facebook?.ok || r.instagram?.ok).length;
                    const todoOk = webs === res.web.length && res.paginas.every(p => (!p.facebook || p.facebook.ok) && (!p.instagram || p.instagram.ok));
                    this.resultado = {success: true, titulo: todoOk ? T.publicado : T.conObservaciones, nota: data.titulo, imagen: data.imagen_url || this.vistaPrevia || this.imagen,
                        detalle: 'Web: ' + webs + '/' + res.web.length + ' · Redes: ' + redes + '/' + res.paginas.length + (data.plantilla?.nombre ? ' · ' + data.plantilla.nombre : ''), resultados: res};
                    this.titulo = ''; this.texto = ''; this.imagen = null; this.quitarVideo(); this.vistaPrevia = null; this.firmaPrevia = ''; this.historial = [];
                } else {
                    this.resultado = {success: false, titulo: T.noSePudo, nota: '', detalle: '', error: data.error, resultados: res};
                    this.avisar(data.error || T.noSePudo, 'fallo', 6000);
                }
                window.scrollTo({top: 0, behavior: 'smooth'});
            } catch (e) { this.avisar(e.message || T.errorConexion, 'fallo', 6000); }
            finally { this.publicando = false; }
        },

        // ---------------- Ya en redes ----------------
        async cargarVideos() {
            this.vCargando = true; this.vError = ''; this.vAviso = '';
            try {
                const {data, status} = await this.api(R.videos);
                if (!data?.success) throw new Error(data?.error || ('HTTP ' + status));
                this.vPaginas = data.paginas || [];
                if (data.categoria) { this.vCategoriaVideos = data.categoria; this.vCategoria = data.categoria; }
                this.vAviso = [data.error, ...this.vPaginas.filter(p => p.error).map(p => p.nombre + ': ' + p.error)].filter(Boolean).join(' · ');
            } catch (e) { this.vError = e.message || T.errorConexion; }
            finally { this.vCargando = false; }
        },
        elegirVideoRed(p, v) {
            const desc = (v.descripcion || v.titulo || '').trim();
            this.vElegido = {pagina: p, video: v}; this.vResultado = null;
            this.vTitulo = (v.titulo || '').trim() || this.tituloSugerido(desc); this.vTexto = desc; this.vCategoria = this.vCategoriaVideos;
            const m = {}; this.medios.forEach(s => m[s.sitio_id] = !!p.medio && s.slug === p.medio);
            if (!Object.values(m).some(Boolean) && this.medios.length === 1) m[this.medios[0].sitio_id] = true;
            this.vMedios = m;
        },
        tituloSugerido(texto) {
            let pri = ((texto || '').replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}\u{200D}]/gu, '').trim().split('\n')[0] || '').trim();
            const fr = pri.match(/^(.{20,}?[.!?])(\s|$)/u); if (fr) pri = fr[1];
            pri = pri.replace(/\s+/g, ' ').replace(/^[\s\-–—:|#]+|[\s\-–—:|#]+$/g, '');
            if (pri.length > 90) { const c = pri.slice(0, 90); const e = c.lastIndexOf(' '); pri = (e > 40 ? c.slice(0, e) : c).replace(/[\s,;:\-]+$/g, ''); }
            return pri.replace(/\.$/, '');
        },
        opcionesCategoriaVideo() {
            const vistas = new Map();
            this.medios.forEach(s => (s.categorias || []).forEach(c => { const k = clave(c.nombre); if (k && !vistas.has(k)) vistas.set(k, c.nombre); }));
            [this.vCategoriaVideos, this.categoriaDefecto, this.vCategoria].forEach(x => { if (x && !vistas.has(clave(x))) vistas.set(clave(x), x); });
            return Array.from(vistas.values()).sort((a, b) => a.localeCompare(b));
        },
        async publicarVideoRed() {
            if (!this.vElegido || this.vPublicando) return;
            if (!this.vTitulo.trim()) return this.avisar(T.escribeTitulo, 'atencion');
            const marcados = this.medios.filter(m => this.vMedios[m.sitio_id]);
            if (!marcados.length) return this.avisar(T.eligeMedio, 'atencion');
            const lista = marcados.map(m => { const cat = this.categoriaDelMedio(m, this.vCategoria); return {sitio_id: m.sitio_id, slug: m.slug, sitio_nombre: m.sitio_nombre, linea_editorial: m.linea_nombre, categoria_id: cat ? (cat.id || 1) : null, categoria: cat?.nombre || this.vCategoria}; });
            this.vPublicando = true; this.vResultado = null;
            try {
                const {data} = await this.api(R.publicarVideo, {method: 'POST', body: JSON.stringify({titulo: this.vTitulo.trim(), texto: this.vTexto.trim(), video: this.vElegido.video, medios: lista})});
                if (!data) throw new Error(T.sinRespuesta);
                const web = data.resultados?.web || [];
                const oks = web.filter(w => w.ok).length;
                this.vResultado = {success: !!data.success, titulo: data.success ? (oks === web.length ? T.videoWebOk : T.conObservaciones) : T.noSePudo, error: data.success ? null : (data.error || web.find(w => !w.ok)?.error), web};
                if (!data.success) this.avisar(this.vResultado.error || T.noSePudo, 'fallo', 7000);
            } catch (e) { this.avisar(e.message || T.errorConexion, 'fallo', 6000); }
            finally { this.vPublicando = false; }
        },

        // ---------------- Historial ----------------
        async cargarHistorial() {
            this.cargandoHistorial = true;
            try { const {data} = await this.api(R.historial); this.historial = data?.posts || []; }
            catch (e) { this.historial = []; }
            finally { this.cargandoHistorial = false; }
        },

        // ---------------- Utilidades ----------------
        estadoRed(r) {
            if (!r) return ['neutro', T.red.no];
            if (r.ok) return ['ok', T.red.ok];
            if (r.no_configurado) return ['neutro', T.red.sinConectar];
            if (r.omitido) return ['neutro', T.red.omitido];
            return ['fallo', T.red.fallo];
        },
        htmlResultados(r) {
            const chip = (tono, txt) => '<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ' + (tono === 'ok' ? 'bg-emerald-50 text-emerald-700' : tono === 'fallo' ? 'bg-rose-50 text-rose-700' : 'bg-gray-100 text-gray-600') + '">' + esc(txt) + '</span>';
            const enlace = (url, txt) => '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="rounded-full px-2 py-0.5 text-[11px] font-semibold bg-emerald-50 text-emerald-700 hover:underline">' + esc(txt) + ' ↗</a>';
            let h = '';
            const web = r.web || [], pags = r.paginas || [];
            if (web.length) {
                h += '<div class="mt-3 text-[11px] font-bold uppercase text-gray-400">🌐 Web</div>';
                web.forEach(w => {
                    h += '<div class="flex items-center justify-between gap-2 py-1"><span class="text-sm text-gray-800 font-medium">' + esc(w.medio) + (w.categoria ? ' <span class="text-gray-400 font-normal">· ' + esc(w.categoria) + '</span>' : '') + '</span>' + (w.ok && w.url ? enlace(w.url, @js(__('Ver nota'))) : chip('fallo', T.red.fallo)) + '</div>';
                    if (w.ok && w.url_corta) h += '<div class="text-[11px] text-sky-700 -mt-1">' + esc(w.url_corta) + '</div>';
                    if (!w.ok && w.error) h += '<div class="text-[11px] text-rose-700">' + esc(w.error) + '</div>';
                });
            }
            if (pags.length) {
                h += '<div class="mt-3 text-[11px] font-bold uppercase text-gray-400">📣 ' + esc(@js(__('Redes'))) + '</div>';
                pags.forEach(p => {
                    h += '<div class="border-t border-gray-100 pt-1.5 mt-1.5"><div class="text-sm font-bold text-gray-900">' + esc(p.pagina || p.page_id) + (p.medio ? ' <span class="text-gray-400 font-normal">· ' + esc(p.medio) + '</span>' : '') + '</div>';
                    [['📘 Facebook', p.facebook], ['📸 Instagram', p.instagram]].forEach(([n, x]) => {
                        if (!x) return;
                        const [tono, txt] = this.estadoRed(x);
                        h += '<div class="flex items-center justify-between py-0.5"><span class="text-xs text-gray-600">' + n + '</span>' + (x.ok && x.permalink ? enlace(x.permalink, @js(__('Ver post'))) : chip(tono, txt)) + '</div>';
                        if (!x.ok && x.error) h += '<div class="text-[11px] ' + (x.omitido || x.no_configurado ? 'text-gray-500' : 'text-rose-700') + '">' + esc(x.error) + '</div>';
                    });
                    h += '</div>';
                });
            }
            return h;
        },
        fmtFecha(f) { if (!f) return ''; const d = new Date(String(f).replace(' ', 'T')); return isNaN(d) ? f : d.toLocaleDateString('es-CO', {day: 'numeric', month: 'short'}) + ' ' + d.toLocaleTimeString('es-CO', {hour: '2-digit', minute: '2-digit'}); },
        fmtDuracion(s) { return s ? Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') : ''; },
        fmtTamano(b) { return !b ? '' : (b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB'); },
    };
}
</script>
@endsection
