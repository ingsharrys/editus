@extends('layouts.app')

@section('content')
    <div class="w-full md:max-w-7xl mx-auto mt-20">
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl">{{ __('Páginas de Meta') }}</h2>
                <span class="text-sm text-gray-500">{{ __('Total:') }}
                    {{ method_exists($pages, 'total') ? $pages->total() : $pages->count() }}</span>
            </div>
        </x-slot>

        {{-- Alertas --}}
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-3">
                <ul class="list-disc ml-5 space-y-1">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-3">
                {{ session('error') }}
            </div>
        @endif


        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-3">
                {{ session('success') }}
            </div>
        @endif

        {{-- Detalle por página del último intento de publicación --}}
        @if (session('publish_results'))
            @php
                $prettyError = function ($err) {
                    if (!$err) return __('Error desconocido');
                    $json = json_decode((string) $err, true);
                    $msg = data_get($json, 'error.message', (string) $err);
                    $code = (int) data_get($json, 'error.code', 0);
                    if ($code === 190 || str_contains($msg, 'access token') || str_contains($msg, 'Sin token activo') || $msg === 'Sin token activo') {
                        return __('Token de la página vencido o ausente → pulsa «Sincronizar» arriba (o «Vincular» en la tarjeta de esta página) y vuelve a intentar.');
                    }
                    if ($code === 200 || str_contains($msg, 'permission')) {
                        return __('Falta un permiso de Meta para esta página: :detalle', ['detalle' => \Illuminate\Support\Str::limit($msg, 140)]);
                    }
                    return \Illuminate\Support\Str::limit($msg, 180);
                };
                $resultados = collect(session('publish_results'));
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-4 mb-3">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-semibold text-gray-800">{{ __('Detalle del último envío por página') }}</p>
                    <span class="text-[11px] text-gray-500">
                        ✅ {{ $resultados->where('ok', true)->count() }} ·
                        ❌ {{ $resultados->where('ok', false)->count() }}
                    </span>
                </div>
                <div class="space-y-1.5 max-h-72 overflow-auto pr-1">
                    @foreach ($resultados as $r)
                        <div class="flex items-start gap-2 text-xs">
                            @if ($r['ok'] ?? false)
                                <span class="shrink-0 text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1.5 py-0.5">
                                    {{ data_get($r, 'body.queued') ? '⏳ ' . __('En cola') : '✅ ' . __('Publicado') }}
                                </span>
                                <span class="font-medium text-gray-800 pt-0.5">{{ $r['page'] ?? '—' }}</span>
                            @else
                                <span class="shrink-0 text-red-700 bg-red-50 border border-red-200 rounded px-1.5 py-0.5">❌ {{ __('Falló') }}</span>
                                <div class="min-w-0 pt-0.5">
                                    <span class="font-medium text-gray-800">{{ $r['page'] ?? '—' }}:</span>
                                    <span class="text-gray-600">{{ $prettyError($r['error'] ?? null) }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @php
            $hasFb = \App\Models\SocialAccount::where('user_id', auth()->id())
                ->where('provider', 'facebook')
                ->exists();
        @endphp
        
        <div class="mb-5 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">

            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
        
                <!-- IZQUIERDA -->
                <div class="flex items-start gap-4">
        
                    <!-- Logo -->
                    <div class="shrink-0 rounded-xl bg-indigo-50 p-2 ring-1 ring-indigo-100">
                        <img src="https://cdn.pixabay.com/photo/2021/11/01/15/20/meta-logo-6760788_1280.png"
                             class="h-10 w-10 object-contain">
                    </div>
        
                    <!-- Info -->
                    <div class="min-w-0">
                        <h2 class="font-semibold text-base text-gray-800">
                            Meta / Facebook
                        </h2>
        
                        <p class="text-xs text-gray-500">
                            {{ __('Gestiona y publica en tus páginas.') }}
                        </p>
        
                        <!-- Estado -->
                        <div class="mt-2 flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full 
                                {{ $hasFb ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
        
                            <span class="text-xs font-medium 
                                {{ $hasFb ? 'text-emerald-600' : 'text-gray-500' }}">
                                {{ $hasFb ? __('Conectado') : __('No conectado') }}
                            </span>
                        </div>
                    </div>
                </div>
        
                <!-- DERECHA -->
                <div class="flex flex-col sm:flex-row flex-wrap gap-2 w-full lg:w-auto">
        
                    @if (auth()->user()->isAdmin() && isset($owners))
                        <select name="owner_id"
                            onchange="this.form.submit()"
                            class="text-xs rounded-lg border border-gray-200 px-3 py-2 bg-white
                                   focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">{{ __('Todos los perfiles') }}</option>
                            @foreach ($owners as $o)
                                <option value="{{ $o->id }}"
                                    {{ (string) ($ownerId ?? request('owner_id')) === (string) $o->id ? 'selected' : '' }}>
                                    {{ $o->name }}
                                </option>
                            @endforeach
                        </select>
                    @endif
        
                    @if (!$hasFb)
                        <a href="{{ route('facebook.connect') }}"
                           class="text-xs inline-flex items-center justify-center px-4 py-2 rounded-lg
                                  border border-indigo-200 text-indigo-700 hover:bg-indigo-50 transition">
                            {{ __('Conectar') }}
                        </a>
                    @endif
        
                    <form method="POST" action="{{ route('meta.pages.sync') }}">
                        @csrf
                        <button
                            class="text-xs px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition
                            {{ $hasFb ? '' : 'opacity-50 cursor-not-allowed' }}"
                            {{ $hasFb ? '' : 'disabled' }}>
                            {{ __('Sincronizar') }}
                        </button>
                    </form>
        
                    <form method="POST" action="{{ route('facebook.unlink') }}"
                          onsubmit="return confirm(@js(__('¿Desvincular Facebook de tu cuenta?')));">
                        @csrf @method('DELETE')
                        <button
                            class="text-xs px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 transition
                            {{ $hasFb ? '' : 'opacity-50 cursor-not-allowed' }}"
                            {{ $hasFb ? '' : 'disabled' }}>
                            {{ __('Desvincular') }}
                        </button>
                    </form>
        
                </div>
            </div>
        
            <!-- SECCIÓN REPAIR -->
            <div class="mt-5 pt-4 border-t border-gray-100">
        
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
        
                    <button id="btnRepairTokens"
                        class="text-xs px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition
                        {{ $hasFb ? '' : 'opacity-50 cursor-not-allowed' }}"
                        {{ $hasFb ? '' : 'disabled' }}>
                        {{ __('Arreglar tokens (insights)') }}
                    </button>
        
                    <!-- Barra -->
                    <div id="repairBox" class="hidden w-full max-w-md">
                        <div class="flex justify-between text-xs mb-1">
                            <span id="repairLabel" class="text-gray-600">{{ __('Preparando…') }}</span>
                            <span id="repairPct" class="text-gray-600">0%</span>
                        </div>
        
                        <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div id="repairBar"
                                 class="h-2 bg-emerald-500 transition-all duration-300"
                                 style="width:0%">
                            </div>
                        </div>
        
                        <div id="repairStats" class="mt-1 text-[11px] text-gray-500"></div>
                    </div>
        
                </div>
            </div>
        
        </div>



        {{-- Búsqueda y filtros (versión bonita) --}}
        {{-- Búsqueda, estado, selección y perfil --}}
        <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

            <!-- Buscar -->
            <div class="flex flex-col gap-1">
                <label for="searchInput" class="text-xs font-medium text-gray-200">{{ __('Buscar') }}</label>
        
                <div class="relative group">
                    <span class="absolute inset-y-0 left-3 flex items-center text-gray-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="m21 21-4.35-4.35M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z" />
                        </svg>
                    </span>
        
                    <input id="searchInput" type="search"
                        placeholder="{{ __('Nombre o ID...') }}"
                        class="w-full h-11 rounded-xl bg-white/90 border border-gray-200 pl-10 pr-3
                               text-sm text-gray-800 placeholder:text-gray-400 shadow-sm
                               focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none
                               transition">
                </div>
            </div>
        
            <!-- Estado -->
            <div class="flex flex-col gap-1">
                <label for="statusFilter" class="text-xs font-medium text-gray-200">{{ __('Estado') }}</label>
        
                <div class="relative">
                    <select id="statusFilter"
                        class="w-full h-11 appearance-none rounded-xl bg-white/90 border border-gray-200
                               px-3 pr-10 text-sm text-gray-800 shadow-sm
                               focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none
                               transition">
        
                        <option value="all">{{ __('Todas') }}</option>
                        <option value="active">{{ __('Activo') }}</option>
                        <option value="inactive">{{ __('Inactivo') }}</option>
                    </select>
        
                    <span class="absolute inset-y-0 right-3 flex items-center text-gray-400 pointer-events-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4"
                             viewBox="0 0 20 20" fill="currentColor">
                            <path d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z"/>
                        </svg>
                    </span>
                </div>
            </div>
        
            <!-- Selección -->
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-gray-200">{{ __('Selección') }}</label>
        
                <div class="flex items-center justify-between h-11 px-3
                            rounded-xl bg-white/90 border border-gray-200 shadow-sm
                            hover:border-gray-300 transition">
        
                    <label for="selectAll" class="flex items-center gap-2 cursor-pointer">
                        <input id="selectAll" type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200">
                        <span class="text-sm text-gray-700">{{ __('Todas') }}</span>
                    </label>
        
                    <span class="flex items-center gap-1 text-xs text-gray-600">
                        <span id="selectedCount"
                            class="px-2 py-0.5 rounded-md bg-gray-100 text-gray-800 font-medium">
                            0
                        </span>
                        {{ __('seleccionadas') }}
                    </span>
                </div>
            </div>
        
            <!-- Favoritos -->
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium text-gray-200">{{ __('Favoritos') }}</label>
        
                <div class="flex items-center gap-2">
        
                    <!-- Botón -->
                    <button id="openFavModal" type="button"
                        class="h-11 px-3 inline-flex items-center gap-1
                               rounded-xl border border-yellow-300 bg-yellow-50
                               text-sm text-yellow-800 hover:bg-yellow-100
                               shadow-sm transition">
                        ⭐
                        <span class="hidden sm:inline">{{ __('Gestionar') }}</span>
                    </button>
        
                    <!-- Checkbox -->
                    <label class="flex items-center gap-2 h-11 px-3
                                  rounded-xl bg-white/90 border border-gray-200
                                  text-sm text-gray-800 shadow-sm
                                  hover:border-gray-300 transition cursor-pointer">
        
                        <input id="onlyFavorites" type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200">
        
                        <span>{{ __('Solo') }}</span>
                    </label>
                </div>
            </div>
        
        </div>



        @auth
            @if (auth()->user()->isAdmin())
            
            <form method="POST" action="{{ route('meta.pages.publish') }}"
                  id="publishForm"
                  enctype="multipart/form-data"
                  class="space-y-5">
            
            @csrf
            
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-6">
            
                @if (!empty($esCliente))
                    @php $lim = $planInfo['limites'] ?? null; @endphp
                    <div class="rounded-xl {{ $lim ? 'border border-indigo-200 bg-indigo-50 text-indigo-900' : 'border border-amber-200 bg-amber-50 text-amber-900' }} px-4 py-3 text-sm">
                        @if ($lim)
                            {!! __('Plan <strong>:plan</strong>: publicas en :n de :max página(s).', ['plan' => e($lim['nombre']), 'n' => count($planInfo['permitidas']), 'max' => e($lim['paginas'])]) !!}
                            <a href="{{ route('suscripcion.index') }}" class="underline">{{ __('Elegir páginas del plan') }}</a>
                        @else
                            {{ __('Necesitas un plan activo para publicar.') }} <a href="{{ route('suscripcion.index') }}" class="font-semibold underline">{{ __('Ver planes') }}</a>
                        @endif
                    </div>
                @else
                <!-- CAMPAÑA (obligatoria) -->
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 mb-2">
                        {{ __('Campaña') }} <span class="text-red-500">*</span>
                    </h3>
                    <select name="campaign_id" id="campaignSelect" required
                            data-paginas='@json($campaignPages ?? [])'
                            class="w-full sm:max-w-md rounded-xl border-gray-200 text-sm">
                        <option value="">{{ __('— Selecciona la campaña de esta publicación —') }}</option>
                        @foreach ($campaigns ?? [] as $c)
                            <option value="{{ $c->id }}" {{ (string) old('campaign_id') === (string) $c->id ? 'selected' : '' }}>
                                {{ $c->name }}{{ $c->medios ? ' · ' . implode(', ', $c->nombresMedios()) : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p id="campaignPagesHint" class="hidden mt-2 rounded-lg bg-indigo-50 text-indigo-800 px-3 py-2 text-xs"></p>
                    {{-- Plan de medios: subcampaña y tipo de pieza (métricas, cupos y certificado de cada medio) --}}
                    <div id="campaignPieza" class="hidden mt-3 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3" data-piezas='@json($campaignPiezas ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)' data-old-sub="{{ old('subcampana_id') }}" data-old-tipo="{{ old('tipo_pieza') }}" data-old-prod="{{ old('producto_id') }}">
                        <p class="text-xs font-semibold text-emerald-900 mb-2">{{ __('Plan de medios') }}</p>
                        <div class="grid sm:grid-cols-2 gap-2">
                            <div id="piezaProdCaja" class="sm:col-span-2"><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Pieza que se publica') }} <span class="text-red-500">*</span></label>
                                <select name="producto_id" id="piezaProd" class="w-full h-9 rounded-lg border-gray-200 text-sm"></select></div>
                            <div id="piezaSubCaja"><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Subcampaña') }} <span class="text-red-500">*</span></label>
                                <select name="subcampana_id" id="piezaSub" class="w-full h-9 rounded-lg border-gray-200 text-sm"></select></div>
                            <div id="piezaTipoCaja"><label class="block text-[11px] text-gray-600 mb-0.5">{{ __('Tipo de publicación') }} <span class="text-red-500">*</span></label>
                                <select name="tipo_pieza" id="piezaTipo" class="w-full h-9 rounded-lg border-gray-200 text-sm"></select></div>
                        </div>
                        <p id="piezaCupos" class="hidden mt-2 text-xs"></p>
                    </div>
                    </div>
                    <p class="mt-1 text-[11px] text-gray-500">
                        {{ __('Al elegir la campaña se marcan las páginas de sus medios; desmarca las que quieras excluir de esta publicación.') }}
                        @if (auth()->user()->isAdmin())
                            <a href="{{ route('campaigns.index') }}" class="text-blue-600 hover:underline">{{ __('Gestionar campañas') }}</a>
                        @endif
                    </p>
                </div>
                @endif

                <!-- REDES DESTINO -->
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">{{ __('Publicar en') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="cursor-pointer">
                            <input type="checkbox" name="networks[]" value="facebook" id="netFacebook" class="hidden peer" checked>
                            <div class="flex items-center justify-center gap-2 px-3 py-3 rounded-xl border
                                        border-gray-200 text-sm text-gray-700
                                        peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700
                                        hover:border-gray-300 transition">
                                📘 Facebook
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="checkbox" name="networks[]" value="instagram" id="netInstagram" class="hidden peer">
                            <div class="flex items-center justify-center gap-2 px-3 py-3 rounded-xl border
                                        border-gray-200 text-sm text-gray-700
                                        peer-checked:border-pink-500 peer-checked:bg-pink-50 peer-checked:text-pink-700
                                        hover:border-gray-300 transition">
                                📸 Instagram
                            </div>
                        </label>
                    </div>
                    <p id="igTextWarning" class="hidden mt-2 text-[11px] text-amber-600">
                        ⚠️ {{ __('Instagram no permite publicaciones de solo texto: agrega una foto o video, o se publicará únicamente en Facebook.') }}
                    </p>
                    <p class="mt-1 text-[11px] text-gray-500">
                        {{ __('Instagram publica solo en páginas con cuenta de Instagram Business conectada.') }}
                    </p>
                </div>

                <!-- TIPO DE PUBLICACIÓN -->
                <div>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">{{ __('Tipo de publicación') }}</h3>
            
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            
                        <!-- Texto -->
                        <label class="cursor-pointer">
                            <input type="radio" name="type" value="text" id="typeText" class="hidden peer" checked>
                            <div class="flex items-center justify-center gap-2 px-3 py-3 rounded-xl border
                                        border-gray-200 text-sm text-gray-700
                                        peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700
                                        hover:border-gray-300 transition">
                                📝 {{ __('Texto / Enlace') }}
                            </div>
                        </label>
            
                        <!-- Foto -->
                        <label class="cursor-pointer">
                            <input type="radio" name="type" value="photo" id="typePhoto" class="hidden peer">
                            <div class="flex items-center justify-center gap-2 px-3 py-3 rounded-xl border
                                        border-gray-200 text-sm text-gray-700
                                        peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700
                                        hover:border-gray-300 transition">
                                🖼️ {{ __('Foto') }}
                            </div>
                        </label>
            
                        <!-- Video -->
                        <label class="cursor-pointer">
                            <input type="radio" name="type" value="video" id="typeVideo" class="hidden peer">
                            <div class="flex items-center justify-center gap-2 px-3 py-3 rounded-xl border
                                        border-gray-200 text-sm text-gray-700
                                        peer-checked:border-indigo-500 peer-checked:bg-indigo-50 peer-checked:text-indigo-700
                                        hover:border-gray-300 transition">
                                🎥 Video
                            </div>
                        </label>
            
                    </div>
                </div>
            
                <!-- MENSAJE -->
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="messageInput" class="text-sm font-medium text-gray-800">
                            {{ __('Mensaje') }}
                        </label>
                        <span class="text-[11px] text-gray-500">
                            <span id="msgCount">0</span> / 63206
                        </span>
                    </div>
            
                    <textarea id="messageInput" name="message" rows="4"
                        placeholder="{{ __('Escribe tu mensaje...') }}"
                        required maxlength="63206"
                        class="w-full rounded-xl border border-gray-200 px-3 py-3 text-sm
                               placeholder-gray-400 resize-none
                               focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition"></textarea>
            
                    <p class="mt-1 text-[11px] text-gray-500">
                        {{ __('Este campo es obligatorio.') }}
                    </p>
                </div>
            
                <!-- ENLACE -->
                <div id="linkWrap">
                    <label for="linkInput" class="block text-sm font-medium text-gray-800 mb-1">
                        {{ __('Enlace (opcional)') }}
                    </label>
            
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            🔗
                        </span>
            
                        <input id="linkInput" type="url" name="link"
                            placeholder="{{ __('https://tusitio.com') }}"
                            class="w-full rounded-xl border border-gray-200 pl-9 pr-20 py-2.5 text-sm
                                   focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none transition" />
            
                        <button type="button" id="clearLink"
                            class="absolute right-2 top-1/2 -translate-y-1/2 hidden text-xs px-2 py-1 rounded-md
                                   text-gray-600 hover:bg-gray-100">
                            {{ __('Limpiar') }}
                        </button>
                    </div>
                </div>
            
                <!-- FOTOS -->
                <div id="photoFilesWrap" class="hidden">
                    <label class="block text-sm font-medium text-gray-800 mb-1">
                        {{ __('Imágenes') }}
                    </label>
            
                    <input id="photoFiles" type="file" name="photos[]" accept="image/*" multiple
                        class="block w-full text-sm
                               file:mr-3 file:px-4 file:py-2 file:rounded-lg
                               file:bg-indigo-600 file:text-white file:border-0
                               hover:file:bg-indigo-700 transition" />
            
                    <div id="photoErrors" class="mt-2 text-xs text-red-600 hidden"></div>
            
                    <div id="photoPreview"
                         class="mt-3 grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4"></div>
            
                    <p class="mt-2 text-[11px] text-gray-500">
                        <span id="photoCount">0</span> {{ __('imagen(es) seleccionadas') }}
                    </p>
                </div>
            
                <!-- VIDEO -->
                <div id="videoWrap" class="hidden">
                    <label class="block text-sm font-medium text-gray-800 mb-1">
                        Video
                    </label>
            
                    <input id="videoFile" type="file" name="video" accept="video/*"
                        class="block w-full text-sm
                               file:mr-3 file:px-4 file:py-2 file:rounded-lg
                               file:bg-indigo-600 file:text-white file:border-0
                               hover:file:bg-indigo-700 transition" />
            
                    <video id="videoPreview"
                        class="mt-3 w-full max-w-md rounded-lg border hidden"
                        controls></video>
            
                    <p class="mt-2 text-[11px] text-gray-500">
                        {{ __('Formatos: MP4, WEBM, MOV.') }}
                    </p>
                </div>
            
            </div>
            
            <!-- BOTÓN -->
            <div class="flex justify-end">
                <button type="submit"
                    class="px-5 py-2.5 rounded-xl bg-[#183EEB] text-white text-sm font-medium
                           hover:bg-indigo-700 transition shadow-sm">
                    {{ __('Publicar') }}
                </button>
            </div>
            
            </form>
            
            @endif
        @endauth



        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">

            <!-- Header -->
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-lg font-semibold text-gray-800">
                    {{ __('Selecciona páginas para publicar') }}
                </h2>
        
                <span class="text-xs text-gray-500">
                    {{ __('Solo publicará en páginas sincronizadas') }}
                </span>
            </div>
        
            <!-- Lista -->
            @if (auth()->user()->isAdmin())
                <!-- Publicación automática desde esnoticia -->
                <div class="mb-4 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3">
                    <p class="text-sm font-semibold text-blue-900">📰 {{ __('Publicación automática de esnoticia') }}</p>
                    <p class="mt-1 text-xs text-blue-800 leading-relaxed">
                        {!! __('En cada página verás el selector <strong>«Medio esnoticia»</strong>. Elige el medio que le corresponde y se guarda solo con seleccionarlo: desde ese momento, cada artículo publicado en ese medio desde la app de noticias se publicará automáticamente en la página de Facebook (como enlace con vista previa) y en su Instagram Business conectado (imagen + titular).') !!}
                        {{ __('Estas publicaciones quedan bajo la campaña «Esnoticia» en los informes.') }}
                        {{ __('Para desactivar una página, selecciona «— Sin medio —».') }}
                    </p>
                </div>
            @endif

            <div id="pagesGrid" class="space-y-3">
        
                @foreach ($pages as $p)
        
                    @php
                        $isAdmin = auth()->user()->isAdmin();
        
                        $myPivot = optional($p->users->firstWhere('id', auth()->id()))->pivot;
                        $isActiveMine = (bool) $myPivot?->is_active;
                        $hasTokenMine = !empty($myPivot?->page_access_token);
        
                        $activeOwnerUser = $p->users->first(fn($u) => $u->pivot && $u->pivot->is_active);
                        $ownerName = $activeOwnerUser?->name;
                        $okForAdmin = (bool) $activeOwnerUser;
        
                        $ok = $isAdmin ? $okForAdmin : $isActiveMine && $hasTokenMine;
        
                        $isFav = in_array($p->id, $favIds ?? []);
        
                        $img = "https://graph.facebook.com/v20.0/{$p->page_id}/picture?type=square&width=96&height=96";
                    @endphp
        
                    <!-- ITEM -->
                    <div
                        class="page-card group flex flex-col lg:flex-row lg:items-center gap-4
                               rounded-2xl border border-gray-200 bg-[#f8f5f5]
                               px-4 py-4 shadow-sm hover:shadow-md transition-all duration-200"
        
                        data-id="{{ $p->id }}"
                        data-name="{{ Str::lower($p->name . ' ' . $p->page_id) }}"
                        data-status="{{ $ok ? 'active' : 'inactive' }}"
                        data-favorite="{{ $isFav ? '1' : '0' }}"
                    >
        
                        <!-- LEFT -->
                        <div class="flex items-center gap-4 flex-1 min-w-0">
        
                            <!-- Imagen -->
                            <img src="{{ $img }}"
                                alt="{{ $p->name }}"
                                loading="lazy"
                                class="w-12 h-12 rounded-full object-cover bg-gray-300 shrink-0">
        
                            <!-- Info -->
                            <div class="min-w-0">
        
                                <div class="flex items-center gap-1">
        
                                    <a href="{{ route('facebook-pages.show', $p) }}"
                                       class="truncate text-sm font-semibold text-gray-800 hover:underline">
                                        {{ $p->name }}
                                    </a>
        
                                    @if ($isFav)
                                        <span class="text-yellow-500 text-sm">⭐</span>
                                    @endif
        
                                </div>
        
                                <div class="text-xs text-gray-400 truncate">
                                    ID: {{ $p->page_id }}
                                </div>
        
                            </div>
        
                        </div>
        
                        <!-- CENTER -->
                        <div class="flex items-center gap-3 lg:min-w-[180px]">
        
                            <!-- Estado -->
                            <div class="flex items-center gap-2">
        
                                <span
                                    class="w-2.5 h-2.5 rounded-full
                                    {{ $ok ? 'bg-green-500' : 'bg-yellow-400' }}">
                                </span>
        
                                @if ($isAdmin && $ownerName)
                                    <span class="text-[11px] text-blue-500 truncate">
                                        {{ $ownerName }}
                                    </span>
                                @endif
        
                            </div>
        
                            <!-- Checkbox -->
                            <label class="flex items-center gap-2 cursor-pointer">
        
                                <input type="checkbox"
                                       name="page_ids[]"
                                       value="{{ $p->id }}"
                                       data-name="{{ $p->name }}"
                                       class="page-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-200"
                                       {{ $ok ? '' : 'disabled' }}>
        
                                <span class="text-sm text-gray-700">
                                    {{ __('Publicar aquí') }}
                                </span>
        
                            </label>
        
                            @if ($isAdmin)
                                <!-- Medio esnoticia (guarda al seleccionar) -->
                                <div class="flex flex-col">
                                    <label class="text-[10px] uppercase tracking-wide text-gray-400 font-semibold">
                                        {{ __('Medio esnoticia') }}
                                    </label>
                                    <select name="medio_slug"
                                            form="medio-{{ $p->id }}"
                                            onchange="document.getElementById('medio-{{ $p->id }}').submit()"
                                            class="mt-0.5 rounded-lg border-gray-300 text-xs py-1 pr-7
                                                   {{ $p->medio_slug ? 'text-blue-700 font-semibold' : 'text-gray-500' }}">
                                        <option value="">{{ __('— Sin medio —') }}</option>
                                        @foreach (config('services.editus.medios', []) as $slug => $nombre)
                                            <option value="{{ $slug }}" {{ $p->medio_slug === $slug ? 'selected' : '' }}>
                                                {{ $nombre }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
        
                        </div>
        
                        <!-- RIGHT -->
                        <div class="flex items-center gap-2 lg:ml-auto">
        
                            <!-- BOTÓN PUBLICAR -->
                            <button type="button"
                                class="px-4 py-2 rounded-lg bg-[#183EEB] text-white text-xs font-medium hover:bg-blue-700 transition">
                                {{ __('Publicar') }}
                            </button>
        
                            <!-- BOTÓN VINCULAR -->
                            @if ($isAdmin)
        
                                @if ($okForAdmin)
        
                                    <button type="submit"
                                        form="unlink-{{ $p->id }}"
                                        onclick="event.stopPropagation(); return confirm({{ \Illuminate\Support\Js::from(__('¿Desvincular «:nombre»?', ['nombre' => $p->name])) }});"
                                        class="px-4 py-2 rounded-lg bg-[#222]
                                               text-white text-xs font-medium
                                               hover:bg-black transition">
                                        {{ __('Desvincular') }}
                                    </button>
        
                                @else
        
                                    <button type="submit"
                                        form="link-{{ $p->id }}"
                                        onclick="event.stopPropagation();"
                                        class="px-4 py-2 rounded-lg bg-[#222]
                                               text-white text-xs font-medium
                                               hover:bg-black transition">
                                        {{ __('Vincular') }}
                                    </button>
        
                                @endif
        
                            @else
        
                                @if ($isActiveMine && $hasTokenMine)
        
                                    <button type="submit"
                                        form="unlink-{{ $p->id }}"
                                        onclick="event.stopPropagation(); return confirm({{ \Illuminate\Support\Js::from(__('¿Desvincular «:nombre»?', ['nombre' => $p->name])) }});"
                                        class="px-4 py-2 rounded-lg bg-[#222]
                                               text-white text-xs font-medium
                                               hover:bg-black transition">
                                        {{ __('Desvincular') }}
                                    </button>
        
                                @else
        
                                    <button type="submit"
                                        form="link-{{ $p->id }}"
                                        onclick="event.stopPropagation();"
                                        class="px-4 py-2 rounded-lg bg-[#222]
                                               text-white text-xs font-medium
                                               hover:bg-black transition">
                                        {{ __('Vincular') }}
                                    </button>
        
                                @endif
        
                            @endif
        
                        </div>
        
                    </div>
        
                @endforeach
        
            </div>
        
        </div>

        @auth
            @if (auth()->user()->isAdmin())
                <div class="sticky bottom-4 z-10">
                    <div class="rounded-xl border border-gray-200 bg-[#0B238F] p-3 shadow-sm flex items-center justify-between">
                        <div class="text-sm text-white"><span id="selectedCountFooter">0</span> {{ __('páginas seleccionadas') }}
                        </div>
                        <button id="publishBtn" type="submit" form="publishForm"
                            class="px-4 py-2 rounded-lg bg-blue-600 text-white text-xs font-medium hover:bg-blue-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                            {{ __('Publicar') }}
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">{{ __('Solo el admin puede publicar en múltiples página.') }}</p>
            @endif
        @endauth
        </form>

        {{-- Forms ocultos para acciones por página --}}
        @foreach ($pages as $p)
            <form id="link-{{ $p->id }}" method="POST" action="{{ route('meta.pages.link', $p) }}"
                class="hidden">
                @csrf
            </form>

            <form id="unlink-{{ $p->id }}" method="POST" action="{{ route('meta.pages.unlink', $p) }}"
                class="hidden">
                @csrf @method('DELETE')
            </form>

            <form id="medio-{{ $p->id }}" method="POST" action="{{ route('meta.pages.medio', $p) }}"
                class="hidden">
                @csrf
            </form>
        @endforeach


        {{-- Resultados de publicación --}}
        {{-- Alertas --}}
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-3">
                <ul class="list-disc ml-5 space-y-1">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-3">
                {{ session('error') }}
            </div>
        @endif
        <div id="favModal" class="fixed inset-0 z-[100] hidden">
            <div class="absolute inset-0 bg-black/40"></div>

            <div class="relative mx-auto mt-20 w-full max-w-xl rounded-2xl bg-white shadow-xl">
                <div class="flex items-center justify-between px-4 py-3 border-b">
                    <h3 class="font-semibold">{{ __('Selecciona tus páginas favoritas') }}</h3>
                    <button id="closeFavModal" class="text-gray-500 hover:text-gray-700">&times;</button>
                </div>

                <div class="p-4">
                    {{-- Buscador dentro del modal --}}
                    <div class="mb-3">
                        <input id="favSearch" type="search" placeholder="{{ __('Buscar página por nombre o ID...') }}"
                            class="w-full h-10 rounded-lg border border-gray-200 px-3 text-sm outline-none focus:ring-2 focus:ring-indigo-100">
                    </div>

                    {{-- Lista de páginas (solo nombres + checkbox) --}}
                    <div id="favList" class="max-h-[50vh] overflow-auto space-y-1">
                        @foreach ($pages as $p)
                            @php
                                $isAdmin = auth()->user()->isAdmin();
                                $myPivot = optional($p->users->firstWhere('id', auth()->id()))->pivot;
                                $isActiveMine = (bool) $myPivot?->is_active;
                                $hasTokenMine = !empty($myPivot?->page_access_token);

                                $activeOwnerUser = $p->users->first(fn($u) => $u->pivot && $u->pivot->is_active);
                                $okForAdmin = (bool) $activeOwnerUser;

                                $ok = $isAdmin ? $okForAdmin : $isActiveMine && $hasTokenMine;
                            @endphp

                            <label class="flex items-center gap-2 px-2 py-1 rounded hover:bg-gray-50 cursor-pointer"
                                data-name="{{ Str::lower($p->name . ' ' . $p->page_id) }}">
                                <input type="checkbox" class="fav-item h-4 w-4" value="{{ $p->id }}"
                                    {{ in_array($p->id, $favIds ?? []) ? 'checked' : '' }}>

                                <span class="text-sm text-gray-800 truncate">{{ $p->name }}</span>

                                <span class="ml-auto inline-flex items-center"
                                    title="{{ $ok ? __('Vinculada') : __('Desvinculada') }}"
                                    aria-label="{{ $ok ? __('Vinculada') : __('Desvinculada') }}">
                                    <span
                                        class="inline-block h-2.5 w-2.5 rounded-full {{ $ok ? 'bg-green-500' : 'bg-amber-400' }}"></span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                </div>

                <div class="flex items-center justify-end gap-2 px-4 py-3 border-t">
                    <button id="favClearAll" type="button"
                        class="text-sm rounded-lg px-3 py-2 bg-gray-100 hover:bg-gray-200">
                        {{ __('Borrar selección') }}
                    </button>
                    <button id="favSave" type="button"
                        class="text-sm rounded-lg px-3 py-2 bg-blue-600 text-white hover:bg-blue-700">
                        {{ __('Guardar') }}
                    </button>
                </div>
            </div>
        </div>
        <script>
            (function() {
                const T = @js([
                    'quitar' => __('Quitar'),
                    'noEsImagen' => __('No es imagen: :nombre'),
                    'seMarcaron' => __('Se marcaron :n página(s) de los medios de la campaña. Desmarca las que quieras excluir.'),
                    'sinToken' => __(':n página(s) de la campaña no tienen token activo.'),
                    'campanaSinPaginas' => __('La campaña no tiene páginas con token activo en esta lista. Marca a mano dónde publicar o revisa sus medios en Campañas.'),
                    'debesCampana' => __('Debes seleccionar una campaña antes de publicar.'),
                    'eligeSub' => __('Elige la subcampaña de esta publicación.'),
                    'eligePieza' => __('Elige la pieza que vas a publicar.'),
                    'eligeTipo' => __('Elige el tipo de pieza de esta publicación.'),
                    'cupoLleno' => __('Cupo completo (no se puede publicar):'),
                    'cupoUsado' => __('Cupo usado:'),
                    'mensajeObligatorio' => __('El mensaje es obligatorio.'),
                    'alMenosRed' => __('Selecciona al menos una red (Facebook o Instagram).'),
                    'igSoloTexto' => __('Instagram no permite publicaciones de solo texto. Agrega una foto o video, o marca también Facebook.'),
                    'alMenosPagina' => __('Selecciona al menos una página para publicar.'),
                    'publicando' => __('Publicando...'),
                    'noGuardarFav' => __('No se pudo guardar favoritos.'),
                    'errorFav' => __('Error guardando favoritos:'),
                ]);
                // ====== ELEMENTOS ORIGINALES ======
                const searchInput = document.getElementById('searchInput');
                const statusFilter = document.getElementById('statusFilter');
                const grid = document.getElementById('pagesGrid');
                const selectAll = document.getElementById('selectAll');
                const publishBtn = document.getElementById('publishBtn');
                const selectedCount = document.getElementById('selectedCount');
                const selectedCountFooter = document.getElementById('selectedCountFooter');
                const checkboxes = () => Array.from(document.querySelectorAll('.page-checkbox'));

                const form = document.getElementById('publishForm');
                const msg = document.getElementById('messageInput');
                const msgCount = document.getElementById('msgCount');
                const MAX_MSG = 63206;

                const link = document.getElementById('linkInput');
                const clear = document.getElementById('clearLink');

                // Tipo
                const typeText = document.getElementById('typeText');
                const typePhoto = document.getElementById('typePhoto');
                const typeVideo = document.getElementById('typeVideo');

                // Bloques condicionales
                const linkWrap = document.getElementById('linkWrap');
                const photoFilesWrap = document.getElementById('photoFilesWrap');
                const videoWrap = document.getElementById('videoWrap');

                // Archivos / preview (fotos)
                const photoFiles = document.getElementById('photoFiles');
                const photoPreview = document.getElementById('photoPreview');
                const photoCount = document.getElementById('photoCount');
                const photoErrors = document.getElementById('photoErrors');

                // Archivo / preview (video)
                const videoFile = document.getElementById('videoFile');
                const videoPreview = document.getElementById('videoPreview');

                const MAX_FILES = 50;
                const MAX_SIZE = 10 * 1024 * 1024; // 10MB
                let selectedFiles = [];

                // ====== FAVORITOS (NUEVO) ======
                const onlyFavorites = document.getElementById('onlyFavorites');
                const favModal = document.getElementById('favModal');
                const openFavModal = document.getElementById('openFavModal');
                const closeFavModal = document.getElementById('closeFavModal');
                const favList = document.getElementById('favList');
                const favSearch = document.getElementById('favSearch');
                const favSave = document.getElementById('favSave');
                const favClearAll = document.getElementById('favClearAll');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const saveUrl = "{{ route('meta.pages.favorites.save') }}";

                function getCards() {
                    return Array.from(grid?.querySelectorAll('.page-card') || []);
                }

                function getCardCheckbox(card) {
                    return card.querySelector('.page-checkbox');
                }

                function autoSelectFavorites(onlyVisible = false) {
                    getCards().forEach(card => {
                        const isFav = card.dataset.favorite === '1';
                        const isVisible = card.style.display !== 'none';
                        if (!isFav) return;
                        if (onlyVisible && !isVisible) return;

                        const cb = getCardCheckbox(card);
                        if (cb && !cb.disabled) {
                            cb.checked = true;
                            markAutoFav(cb, true);
                            cb.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        }
                    });
                }

                function autoUnselectFavorites() {
                    // solo desmarca los que marcamos automáticamente
                    checkboxes().forEach(cb => {
                        if (cb.dataset.autofav === '1') {
                            cb.checked = false;
                            delete cb.dataset.autofav;
                            cb.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        }
                    });
                }

                function markAutoFav(cb, val) {
                    if (!cb) return;
                    if (val) cb.dataset.autofav = '1';
                    else delete cb.dataset.autofav;
                }

                function setFavoriteSelection(checked = true, onlyVisible = false) {
                    getCards().forEach(card => {
                        const isFav = card.dataset.favorite === '1';
                        const isVisible = card.style.display !== 'none';
                        if (!isFav) return;
                        if (onlyVisible && !isVisible) return;

                        const cb = getCardCheckbox(card);
                        if (cb && !cb.disabled) {
                            cb.checked = checked;
                            // dispara change por si tu UI cuenta seleccionados
                            cb.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        }
                    });
                }
                // ====== FILTROS Y SELECCIÓN ======
                function applyFilters() {
                    if (!grid) return;
                    const q = (searchInput?.value || '').trim().toLowerCase();
                    const st = statusFilter?.value || 'all';
                    const onlyFav = !!onlyFavorites?.checked;

                    const cards = getCards();
                    cards.forEach(card => {
                        const name = (card.dataset.name || '').toLowerCase();
                        const status = card.dataset.status || 'inactive';
                        const isFav = card.dataset.favorite === '1';

                        const matchesText = !q || name.includes(q);
                        const matchesStatus = st === 'all' || st === status;
                        const matchesFav = !onlyFav || isFav;

                        card.style.display = (matchesText && matchesStatus && matchesFav) ? '' : 'none';
                    });
                }

                function pagesSelectedCount() {
                    return checkboxes().filter(c => c.checked).length;
                }

                function updateCounts() {
                    const count = pagesSelectedCount();
                    selectedCount && (selectedCount.textContent = count);
                    selectedCountFooter && (selectedCountFooter.textContent = count);
                    updatePublishState();
                }

                searchInput && searchInput.addEventListener('input', applyFilters);
                statusFilter && statusFilter.addEventListener('change', applyFilters);
                onlyFavorites && onlyFavorites.addEventListener('change', () => {
                    applyFilters();
                    if (onlyFavorites.checked) {
                        autoSelectFavorites(true); // marca favoritos visibles
                    } else {
                        autoUnselectFavorites(); // desmarca solo los auto-seleccionados
                    }
                    updateCounts();
                });


                selectAll && selectAll.addEventListener('change', () => {
                    const visibleCards = getCards().filter(card => card.style.display !== 'none');
                    visibleCards.forEach(card => {
                        const cb = getCardCheckbox(card);
                        if (cb && !cb.disabled) cb.checked = selectAll.checked;
                    });
                    updateCounts();
                });
                if (onlyFavorites?.checked) {
                    setFavoriteSelection(true, true);
                    updateCounts();
                }


                document.addEventListener('change', (e) => {
                    if (e.target.classList.contains('page-checkbox')) {
                        if (!e.target.checked && e.target.dataset.autofav === '1') {
                            delete e.target.dataset.autofav;
                        }
                        updateCounts();
                    }
                });


                // ====== MENSAJE ======
                function updateMsg() {
                    if (!msg) return;
                    msg.style.height = 'auto';
                    msg.style.height = (msg.scrollHeight) + 'px';
                    const len = (msg.value || '').length;
                    if (msgCount) {
                        msgCount.textContent = len;
                        msgCount.classList.toggle('text-red-600', len > MAX_MSG);
                    }
                    updatePublishState();
                }
                msg && msg.addEventListener('input', updateMsg);

                // ====== ENLACE ======
                function toggleClear() {
                    if (!clear || !link) return;
                    clear.classList.toggle('hidden', !(link.value || '').trim());
                    updatePublishState();
                }
                link && link.addEventListener('input', toggleClear);
                clear && clear.addEventListener('click', () => {
                    link.value = '';
                    link.dispatchEvent(new Event('input'));
                    link.focus();
                });

                // ====== UI: TIPO ======
                function refreshUI() {
                    const isText = !!typeText?.checked;
                    const isPhoto = !!typePhoto?.checked;
                    const isVideo = !!typeVideo?.checked;

                    linkWrap?.classList.toggle('hidden', !isText);
                    photoFilesWrap?.classList.toggle('hidden', !isPhoto);
                    videoWrap?.classList.toggle('hidden', !isVideo);

                    
                    if (msg) msg.required = isText; 
                    if (msg) msg.required = true; // SIEMPRE obligatorio

                    toggleClear();
                    updateMsg();
                    updatePublishState();
                }

                typeText && typeText.addEventListener('change', () => { refreshUI(); refreshNetworkHints(); });
                typePhoto && typePhoto.addEventListener('change', () => { refreshUI(); refreshNetworkHints(); });
                typeVideo && typeVideo.addEventListener('change', () => { refreshUI(); refreshNetworkHints(); });

                // ====== PREVIEW IMÁGENES ======
                function fmtSize(b) {
                    return b < 1024 ? b + ' B' :
                        b < 1048576 ? (b / 1024).toFixed(1) + ' KB' :
                        (b / 1048576).toFixed(1) + ' MB';
                }

                function syncInput() {
                    if (!photoFiles) return;
                    const dt = new DataTransfer();
                    selectedFiles.forEach(f => dt.items.add(f));
                    photoFiles.files = dt.files;
                    photoCount && (photoCount.textContent = selectedFiles.length);
                }

                function renderPreviews() {
                    if (!photoPreview) return;
                    photoPreview.innerHTML = '';
                    selectedFiles.forEach((file, idx) => {
                        const url = URL.createObjectURL(file);
                        const card = document.createElement('div');
                        card.className = 'relative group border rounded-xl overflow-hidden';
                        card.innerHTML = `
        <img src="${url}" alt="" class="w-full h-32 object-cover" loading="lazy">
        <button type="button" data-index="${idx}"
          class="remove-img absolute top-1.5 right-1.5 bg-white/90 rounded-full p-1 shadow hidden group-hover:block"
          title="${T.quitar}">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" />
          </svg>
        </button>
        <div class="px-2 py-1 text-[11px] text-gray-600 truncate">${file.name} · ${fmtSize(file.size)}</div>
      `;
                        photoPreview.appendChild(card);
                    });
                }

                function addFiles(fileList) {
                    let errors = [];
                    const incoming = Array.from(fileList || []);
                    for (const f of incoming) {
                        if (!f.type.startsWith('image/')) {
                            errors.push(T.noEsImagen.replace(':nombre', f.name));
                            continue;
                        }
                        if (f.size > MAX_SIZE) {
                            errors.push(`>10MB: ${f.name}`);
                            continue;
                        }
                        const exists = selectedFiles.some(s => s.name === f.name && s.size === f.size && s.lastModified ===
                            f.lastModified);
                        if (exists) continue;
                        selectedFiles.push(f);
                        if (selectedFiles.length >= MAX_FILES) break;
                    }
                    if (photoErrors) {
                        if (errors.length) {
                            photoErrors.textContent = errors.join(' · ');
                            photoErrors.classList.remove('hidden');
                        } else {
                            photoErrors.textContent = '';
                            photoErrors.classList.add('hidden');
                        }
                    }
                    syncInput();
                    renderPreviews();
                    updatePublishState();
                }
                photoFiles && photoFiles.addEventListener('change', () => addFiles(photoFiles.files));
                photoPreview && photoPreview.addEventListener('click', (e) => {
                    const btn = e.target.closest('.remove-img');
                    if (!btn) return;
                    const idx = parseInt(btn.dataset.index, 10);
                    if (!Number.isNaN(idx)) {
                        selectedFiles.splice(idx, 1);
                        syncInput();
                        renderPreviews();
                        updatePublishState();
                    }
                });

                // ====== PREVIEW VIDEO ======
                videoFile && videoFile.addEventListener('change', () => {
                    if (videoFile.files?.[0]) {
                        const url = URL.createObjectURL(videoFile.files[0]);
                        videoPreview.src = url;
                        videoPreview.style.display = 'block';
                    } else {
                        videoPreview.removeAttribute('src');
                        videoPreview.style.display = 'none';
                    }
                    updatePublishState();
                });

                // ====== VALIDACIÓN PARA HABILITAR “PUBLICAR” ======
                function contentValid() {
                    const pagesOk = pagesSelectedCount() > 0;
                    const msgLen = (msg?.value || '').trim().length;

                    // Mensaje siempre requerido y dentro del límite
                    if (!pagesOk || msgLen === 0 || msgLen > MAX_MSG) return false;

                    if (typeText?.checked) return true; // texto: con mensaje ya basta
                    if (typePhoto?.checked) return selectedFiles.length > 0; // fotos + mensaje
                    if (typeVideo?.checked) return (videoFile?.files?.length || 0) > 0; // video + mensaje
                    return false;
                }


                function updatePublishState() {
                    if (!publishBtn) return;
                    publishBtn.disabled = !contentValid();
                }

                // Evita doble submit

                // ====== REDES DESTINO ======
                const netFacebook = document.getElementById('netFacebook');
                const netInstagram = document.getElementById('netInstagram');
                const igTextWarning = document.getElementById('igTextWarning');

                function refreshNetworkHints() {
                    const igOn = !!netInstagram?.checked;
                    const isText = !!typeText?.checked;
                    igTextWarning?.classList.toggle('hidden', !(igOn && isText));
                }
                netFacebook && netFacebook.addEventListener('change', refreshNetworkHints);
                netInstagram && netInstagram.addEventListener('change', refreshNetworkHints);

                // Al elegir la campaña, marcar las páginas de sus medios (se pueden desmarcar después)
                (function () {
                    const sel = document.getElementById('campaignSelect');
                    const hint = document.getElementById('campaignPagesHint');
                    if (!sel) return;
                    let mapa = {};
                    try { mapa = JSON.parse(sel.dataset.paginas || '{}'); } catch (e) {}
                    const aplicar = () => {
                        const ids = (mapa[sel.value] || []).map(String);
                        if (!sel.value) { hint && hint.classList.add('hidden'); return; }
                        let marcadas = 0, sinToken = 0;
                        checkboxes().forEach(cb => {
                            const enCampana = ids.includes(String(cb.value));
                            if (cb.disabled) { if (enCampana) sinToken++; return; }
                            cb.checked = enCampana;
                            if (enCampana) marcadas++;
                            cb.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                        if (hint) {
                            hint.textContent = marcadas
                                ? T.seMarcaron.replace(':n', marcadas) + (sinToken ? ' ' + T.sinToken.replace(':n', sinToken) : '')
                                : T.campanaSinPaginas;
                            hint.classList.remove('hidden');
                        }
                    };
                    sel.addEventListener('change', aplicar);
                    if (sel.value) setTimeout(aplicar, 0);
                })();

                // Plan de medios: pieza (o subcampaña, o tipo de pieza) y cupos de la campaña elegida
                (function () {
                    const sel = document.getElementById('campaignSelect');
                    const caja = document.getElementById('campaignPieza');
                    if (!sel || !caja) return;
                    let mapa = {};
                    try { mapa = JSON.parse(caja.dataset.piezas || '{}'); } catch (e) {}
                    const prodSel = document.getElementById('piezaProd');
                    const subSel = document.getElementById('piezaSub');
                    const tipoSel = document.getElementById('piezaTipo');
                    const hint = document.getElementById('piezaCupos');
                    const opcion = (padre, v, t, previo) => { const o = document.createElement('option'); o.value = v; o.textContent = t; if (previo !== undefined && String(v) === String(previo)) o.selected = true; padre.appendChild(o); };
                    const opciones = (el, items, vacio, previo) => { el.innerHTML = ''; opcion(el, '', vacio); items.forEach(([v, t]) => opcion(el, v, t, previo)); };
                    const mostrar = (id, si) => { document.getElementById(id).classList.toggle('hidden', !si); };
                    const modo = conf => conf.productos.length ? 'producto' : (conf.subs.length ? 'sub' : 'tipo');
                    const tiposDeSub = conf => { const s = conf.subs.find(x => String(x.id) === subSel.value); return s && s.tipos.length ? s.tipos : conf.tipos; };
                    const llenarTipos = conf => opciones(tipoSel, (modo(conf) === 'sub' ? tiposDeSub(conf) : conf.tipos).map(t => [t, t]), T.eligeTipo, tipoSel.value || caja.dataset.oldTipo);
                    const tipoActual = () => {
                        const conf = mapa[sel.value];
                        if (!conf) return '';
                        if (modo(conf) === 'producto') return (conf.productos.find(p => String(p.id) === prodSel.value) || {}).tipo || '';
                        return tipoSel.value;
                    };
                    const nombrePagina = cb => cb.dataset.name || ('#' + cb.value);
                    const cupos = () => {
                        const conf = mapa[sel.value];
                        const t = tipoActual();
                        if (!conf || !t) { hint.classList.add('hidden'); return; }
                        const k = t.toLowerCase();
                        const llenas = [], quedan = [];
                        checkboxes().filter(cb => cb.checked).forEach(cb => {
                            const c = (conf.cupos[cb.value] || {})[k];
                            if (!c) return;
                            (c[0] >= c[1] ? llenas : quedan).push(nombrePagina(cb) + ' ' + c[0] + '/' + c[1]);
                        });
                        if (!llenas.length && !quedan.length) { hint.classList.add('hidden'); return; }
                        hint.innerHTML = '';
                        if (llenas.length) { const s = document.createElement('span'); s.className = 'block text-rose-700 font-semibold'; s.textContent = T.cupoLleno + ' ' + llenas.join(', '); hint.appendChild(s); }
                        if (quedan.length) { const s = document.createElement('span'); s.className = 'block text-emerald-800'; s.textContent = T.cupoUsado + ' (' + t + ') ' + quedan.join(', '); hint.appendChild(s); }
                        hint.classList.remove('hidden');
                    };
                    const aplicar = () => {
                        const conf = mapa[sel.value];
                        caja.classList.toggle('hidden', !conf);
                        [prodSel, subSel, tipoSel].forEach(x => x.disabled = true);
                        if (!conf) return;
                        const m = modo(conf);
                        mostrar('piezaProdCaja', m === 'producto'); mostrar('piezaSubCaja', m === 'sub'); mostrar('piezaTipoCaja', m !== 'producto');
                        if (m === 'producto') {
                            prodSel.disabled = false;
                            prodSel.innerHTML = ''; opcion(prodSel, '', T.eligePieza);
                            const grupos = {};
                            conf.productos.forEach(p => { (grupos[p.grupo] = grupos[p.grupo] || []).push(p); });
                            Object.keys(grupos).forEach(g => {
                                const og = document.createElement('optgroup'); og.label = g;
                                grupos[g].forEach(p => opcion(og, p.id, p.nombre + (p.estado ? ' — ' + p.estado : ''), caja.dataset.oldProd));
                                prodSel.appendChild(og);
                            });
                        } else {
                            if (m === 'sub') {
                                subSel.disabled = false;
                                opciones(subSel, conf.subs.map(x => [x.id, x.nombre]), T.eligeSub, caja.dataset.oldSub);
                            }
                            tipoSel.disabled = false;
                            llenarTipos(conf);
                        }
                        cupos();
                    };
                    sel.addEventListener('change', aplicar);
                    [prodSel, subSel, tipoSel].forEach(x => x.addEventListener('change', cupos));
                    subSel.addEventListener('change', () => { const conf = mapa[sel.value]; if (conf) { llenarTipos(conf); cupos(); } });
                    document.addEventListener('change', e => { if (e.target.matches && e.target.matches('input[type=checkbox]')) cupos(); });
                    aplicar();
                    window.__validarPlan = () => {
                        const conf = mapa[sel.value];
                        if (!conf) return true;
                        const m = modo(conf);
                        if (m === 'producto' && !prodSel.value) { alert(T.eligePieza); prodSel.focus(); return false; }
                        if (m === 'sub' && !subSel.value) { alert(T.eligeSub); subSel.focus(); return false; }
                        if (m !== 'producto' && !tipoSel.value) { alert(T.eligeTipo); tipoSel.focus(); return false; }
                        return true;
                    };
                })();

                form && form.addEventListener('submit', function(e) {

                    const campaignSelect = document.getElementById('campaignSelect');
                    if (campaignSelect && !campaignSelect.value) {
                        e.preventDefault();
                        alert(T.debesCampana);
                        campaignSelect.focus();
                        return;
                    }
                    if (window.__validarPlan && !window.__validarPlan()) { e.preventDefault(); return; }

                    const hasMsg = (msg?.value || '').trim().length > 0;
                    if (!hasMsg) {
                        e.preventDefault();
                        alert(T.mensajeObligatorio);
                        msg?.focus();
                        return;
                    }

                    if (netFacebook && netInstagram && !netFacebook.checked && !netInstagram.checked) {
                        e.preventDefault();
                        alert(T.alMenosRed);
                        return;
                    }

                    if (netInstagram?.checked && !netFacebook?.checked && typeText?.checked) {
                        e.preventDefault();
                        alert(T.igSoloTexto);
                        return;
                    }

                    // Garantiza que las páginas seleccionadas viajen con el formulario:
                    // en este diseño las casillas están fuera del <form>, así que se
                    // inyectan como inputs hidden antes de enviar.
                    form.querySelectorAll('input[data-injected-page]').forEach(el => el.remove());

                    const selected = checkboxes().filter(cb => cb.checked && !cb.disabled);
                    if (selected.length === 0) {
                        e.preventDefault();
                        alert(T.alMenosPagina);
                        return;
                    }

                    selected.forEach(cb => {
                        cb.removeAttribute('name');
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'page_ids[]';
                        hidden.value = cb.value;
                        hidden.setAttribute('data-injected-page', '1');
                        form.appendChild(hidden);
                    });

                    if (publishBtn) {
                        publishBtn.disabled = true;
                        publishBtn.textContent = T.publicando;
                    }
                });

                // ====== FAVORITOS: MODAL & GUARDADO (con reload) ======
                function openFav() {
                    // opcional: sincronizar checks con estado actual si manejas Set en el futuro
                    favModal?.classList.remove('hidden');
                }

                function closeFav() {
                    favModal?.classList.add('hidden');
                }
                openFavModal && openFavModal.addEventListener('click', openFav);
                closeFavModal && closeFavModal.addEventListener('click', closeFav);
                favModal && favModal.addEventListener('click', (e) => {
                    if (e.target === favModal) closeFav();
                });

                favSearch && favSearch.addEventListener('input', () => {
                    const q = (favSearch.value || '').trim().toLowerCase();
                    favList?.querySelectorAll('[data-name]').forEach(row => {
                        row.style.display = (!q || row.dataset.name.includes(q)) ? '' : 'none';
                    });
                });

                favClearAll && favClearAll.addEventListener('click', () => {
                    favList?.querySelectorAll('.fav-item').forEach(chk => chk.checked = false);
                });

                favSave && favSave.addEventListener('click', async () => {
                    const selected = Array.from(favList?.querySelectorAll('.fav-item:checked') || [])
                        .map(chk => parseInt(chk.value, 10));

                    try {
                        const res = await fetch(saveUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                page_ids: selected
                            })
                        });

                        if (!res.ok) {
                            const txt = await res.text();
                            alert(T.noGuardarFav + '\n' + txt);
                            return;
                        }

                        // Refresca para ver ⭐ y data-favorite actualizados
                        window.location.reload();
                    } catch (e) {
                        alert(T.errorFav + ' ' + (e?.message || e));
                    }
                });

                // ====== INIT ======
                applyFilters();
                updateCounts();
                updateMsg();
                toggleClear();
                refreshUI();
            })();
        </script>
        <script>
            (function() {
                const TR = @js([
                    'inicializando' => __('Inicializando…'),
                    'error' => __('Error:'),
                    'inicio' => __('inicio'),
                    'errorStep' => __('Error en step'),
                    'terminado' => __('Terminado'),
                    'procesando' => __('Procesando…'),
                    'stats' => __('Total: :total | Listos: :listos | Fix: :fix | Errores: :errores'),
                ]);
                const btn = document.getElementById('btnRepairTokens');
                const box = document.getElementById('repairBox');
                const bar = document.getElementById('repairBar');
                const pct = document.getElementById('repairPct');
                const label = document.getElementById('repairLabel');
                const stats = document.getElementById('repairStats');

                async function post(url, data = {}) {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    });
                    return res.json();
                }

                async function start() {
                    btn.disabled = true;
                    box.classList.remove('hidden');
                    label.textContent = TR.inicializando;
                    bar.style.width = '0%';
                    pct.textContent = '0%';
                    stats.textContent = '';

                    const startUrl = "{{ route('meta.pages.repairTokens.start') }}";
                    const stepUrl = "{{ route('meta.pages.repairTokens.step') }}";

                    const s = await post(startUrl);
                    if (!s.ok) {
                        label.textContent = TR.error + ' ' + (s.error || TR.inicio);
                        btn.disabled = false;
                        return;
                    }

                    async function step() {
                        const r = await post(stepUrl, {
                            limit: 25
                        });
                        if (!r.ok) {
                            label.textContent = TR.errorStep;
                            btn.disabled = false;
                            return;
                        }

                        const st = r.state;
                        const total = st.total || 1;
                        const done = st.done || 0;
                        const perc = Math.round(done * 100 / total);

                        bar.style.width = perc + '%';
                        pct.textContent = perc + '%';
                        label.textContent = st.finished ? TR.terminado : TR.procesando;
                        stats.textContent =
                            TR.stats.replace(':total', total).replace(':listos', st.kept).replace(':fix', st.fixed).replace(':errores', st.errors);

                        if (st.finished) {
                            btn.disabled = false;
                            return;
                        }
                        setTimeout(step, 500);
                    }
                    step();
                }

                if (btn) btn.addEventListener('click', start);
            })();
        </script>
    </div>
@endsection
