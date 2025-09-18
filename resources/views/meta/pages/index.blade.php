@extends('layouts.app')

@section('content')
    <div class="max-w-7xl mx-auto mt-20">
        <x-slot name="header">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl">Páginas de Meta</h2>
                <span class="text-sm text-gray-500">Total:
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
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-3">{{ session('error') }}</div>
        @endif

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-3">
                {{ session('success') }}
            </div>
        @endif

        @php
            $hasFb = \App\Models\SocialAccount::where('user_id', auth()->id())
                ->where('provider', 'facebook')
                ->exists();
        @endphp

        <div class="mb-5 rounded-xl border border-gray-100 bg-white p-4">
            <div class="flex items-start justify-between gap-4">

                {{-- Lado izquierdo: branding + estado --}}
                <div class="flex items-start gap-3">
                    <div class="shrink-0 rounded-full bg-indigo-50 p-2 ring-1 ring-indigo-100">
                        <img src="https://cdn.pixabay.com/photo/2021/11/01/15/20/meta-logo-6760788_1280.png" alt="Meta"
                            class="h-10 w-10">
                    </div>
                    <div>
                        <div class="font-semibold">Meta / Facebook</div>
                        <div class="text-xs text-gray-500">Gestiona y publica en tus páginas.</div>
                        <div class="mt-2 inline-flex items-center gap-2">
                            <span
                                class="inline-block h-2.5 w-2.5 rounded-full {{ $hasFb ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                            <span class="text-xs {{ $hasFb ? 'text-green-700' : 'text-gray-600' }}">
                                {{ $hasFb ? 'Conectado' : 'No conectado' }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Lado derecho: selector de perfil + acciones apiladas --}}
                <div class="flex flex-col items-end gap-2">

                    @if (auth()->user()->isAdmin() && isset($owners))
                        <form method="GET" action="{{ route('meta.pages.index') }}" class="w-44">
                            <select name="owner_id"
                                class="w-full text-xs rounded-md border border-gray-200 bg-white px-2.5 py-2 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"
                                onchange="this.form.submit()">
                                <option value="">Todos los perfiles</option>
                                @foreach ($owners as $o)
                                    <option value="{{ $o->id }}"
                                        {{ (string) ($ownerId ?? request('owner_id')) === (string) $o->id ? 'selected' : '' }}>
                                        {{ $o->name }}
                                    </option>
                                @endforeach
                            </select>
                        </form>
                    @endif

                    @if (!$hasFb)
                        <a href="{{ route('facebook.redirect') }}"
                            class="w-44 text-xs inline-flex items-center justify-center gap-2 px-3 py-2 rounded-md border border-indigo-200 text-indigo-700 hover:bg-indigo-50">
                            Conectar
                        </a>
                    @endif

                    <form method="POST" action="{{ route('meta.pages.sync') }}">
                        @csrf
                        <button
                            class="w-44 text-xs px-3 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 {{ $hasFb ? '' : 'opacity-50 cursor-not-allowed' }}"
                            {{ $hasFb ? '' : 'disabled' }}>
                            Sincronizar
                        </button>
                    </form>

                    <form method="POST" action="{{ route('facebook.unlink') }}"
                        onsubmit="return confirm('¿Desvincular Facebook de tu cuenta? Se limpiarán todos los tokens.');">
                        @csrf @method('DELETE')
                        <button
                            class="w-44 text-xs px-3 py-2 rounded-md border border-red-200 text-red-700 hover:bg-red-50 {{ $hasFb ? '' : 'opacity-50 cursor-not-allowed' }}"
                            {{ $hasFb ? '' : 'disabled' }}>
                            Desvincular
                        </button>
                    </form>

                </div>
            </div>
        </div>


        {{-- Búsqueda y filtros (versión bonita) --}}
        {{-- Búsqueda, estado, selección y perfil --}}
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">

            {{-- Buscar --}}
            <div class="space-y-1">
                <label for="searchInput" class="text-xs font-medium text-white">Buscar</label>
                <div
                    class="relative group rounded-xl border border-gray-200 bg-white/90 shadow-sm hover:border-gray-300 focus-within:ring-2 focus-within:ring-indigo-100">
                    <span class="absolute inset-y-0 left-3 flex items-center pointer-events-none text-gray-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="m21 21-4.35-4.35M10 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16z" />
                        </svg>
                    </span>
                    <input id="searchInput" type="search" placeholder="Nombre o ID de la página..."
                        class="w-full h-10 rounded-xl bg-transparent pl-9 pr-3 text-sm text-gray-800 placeholder:text-gray-400 outline-none"
                        aria-label="Buscar página por nombre o ID">
                </div>
            </div>

            {{-- Estado --}}
            <div class="space-y-1">
                <label for="statusFilter" class="text-xs font-medium text-white">Estado</label>
                <div
                    class="relative rounded-xl border border-gray-200 bg-white/90 shadow-sm hover:border-gray-300 focus-within:ring-2 focus-within:ring-indigo-100">
                    <select id="statusFilter"
                        class="w-full h-10 appearance-none rounded-xl bg-transparent pl-3 pr-9 text-sm text-gray-800 outline-none">
                        <option value="all">Todas</option>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                    <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-gray-400">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path
                                d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z" />
                        </svg>
                    </span>
                </div>
            </div>

            {{-- Selección --}}
            <div class="space-y-1">
                <label class="text-xs text-white font-bold">Selección</label>
                <div
                    class="flex items-center justify-between rounded-xl border border-gray-200 bg-white/90 px-3 h-10 shadow-sm hover:border-gray-300">
                    <label for="selectAll" class="flex items-center gap-2 cursor-pointer">
                        <input id="selectAll" type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200">
                        <span class="text-sm text-gray-700">Seleccionar todas</span>
                    </label>
                    <span class="inline-flex items-center gap-1 text-xs text-gray-600">
                        <span id="selectedCount"
                            class="inline-flex h-5 min-w-5 items-center justify-center rounded-md bg-gray-100 px-2 text-gray-800">0</span>
                        seleccionadas
                    </span>
                </div>
            </div>

            {{-- Perfil / Propietario (solo admin) --}}
            @if (auth()->user()->isAdmin() && isset($owners) && $owners->count())
                <form method="GET" action="{{ route('meta.pages.index') }}" class="space-y-1">
                    <label for="ownerSelect" class="text-xs font-medium text-white">Perfil</label>
                    <div
                        class="relative rounded-xl border border-gray-200 bg-white/90 shadow-sm hover:border-gray-300 focus-within:ring-2 focus-within:ring-indigo-100">
                        <select id="ownerSelect" name="owner_id"
                            class="w-full h-10 appearance-none rounded-xl bg-transparent pl-3 pr-9 text-sm text-gray-800 outline-none"
                            onchange="this.form.submit()">
                            <option value="">Todos los perfiles</option>
                            @foreach ($owners as $o)
                                <option value="{{ $o->id }}"
                                    {{ (string) ($ownerId ?? request('owner_id')) === (string) $o->id ? 'selected' : '' }}>
                                    {{ $o->name }}
                                </option>
                            @endforeach
                        </select>
                        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20"
                                fill="currentColor">
                                <path
                                    d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z" />
                            </svg>
                        </span>
                    </div>

                    {{-- conserva otros query params (ej: búsqueda/estado/página) --}}
                    @foreach (request()->except('owner_id', 'page') as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endforeach
                </form>
            @endif

        </div>



        @auth
            @if (auth()->user()->role_id === 1)
                <form method="POST" action="{{ route('meta.pages.publish') }}" class="space-y-4" id="publishForm"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">

                        {{-- Tipo de publicación --}}
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Tipo de publicación</label>
                            <div class="flex items-center gap-4 text-sm">
                                <label class="inline-flex items-center gap-2">
                                    <input type="radio" name="type" value="text" id="typeText"
                                        class="accent-indigo-600" checked>
                                    <span>Texto / Enlace</span>
                                </label>
                                <label class="inline-flex items-center gap-2">
                                    <input type="radio" name="type" value="photo" id="typePhoto"
                                        class="accent-indigo-600">
                                    <span>Foto (una o varias)</span>
                                </label>
                                <label class="inline-flex items-center gap-2">
                                    <input type="radio" name="type" value="video" id="typeVideo"
                                        class="accent-indigo-600">
                                    <span>Video</span>
                                </label>
                            </div>
                        </div>

                        {{-- Mensaje / Caption --}}
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label for="messageInput" class="block text-sm font-medium">Mensaje</label>
                                <span class="text-[11px] text-gray-500">
                                    <span id="msgCount">0</span> / 63206
                                </span>
                            </div>

                            <textarea id="messageInput" name="message" rows="3" placeholder="Escribe el mensaje…"
                                class="w-full min-h-[96px] rounded-xl border border-gray-200 px-3 py-2 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"></textarea>

                            <p class="mt-1 text-[11px] text-gray-500">
                                En <strong>Texto/Enlace</strong> el mensaje es obligatorio. En <strong>Foto</strong>, es
                                opcional (caption).
                            </p>
                        </div>

                        {{-- Enlace (solo type=text) --}}
                        <div class="mt-4" id="linkWrap">
                            <label for="linkInput" class="block text-sm font-medium mb-1">Enlace (opcional)</label>

                            <div class="relative">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                                    {{-- icono link --}}
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24"
                                        fill="currentColor">
                                        <path
                                            d="M10.59 13.41a1 1 0 0 0 1.41 1.41l4.24-4.24a3 3 0 1 0-4.24-4.24L9.17 8.17a1 1 0 1 0 1.41 1.41l2.12-2.12a1 1 0 1 1 1.41 1.41l-4.24 4.24ZM13.41 10.59a1 1 0 0 0-1.41-1.41L7.76 13.41a3 3 0 1 0 4.24 4.24l2.83-2.83a1 1 0 0 0-1.41-1.41l-2.83 2.83a1 1 0 0 1-1.41-1.41l4.24-4.24Z" />
                                    </svg>
                                </span>

                                <input id="linkInput" type="url" name="link" placeholder="https://tusitio.com/…"
                                    class="w-full rounded-xl border border-gray-200 pl-9 pr-20 py-2 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500" />

                                <button type="button" id="clearLink"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 hidden rounded-md px-2 py-1 text-xs text-gray-600 hover:bg-gray-100"
                                    title="Limpiar enlace">Limpiar</button>
                            </div>

                            <p class="mt-1 text-[11px] text-gray-500">Usa <code>http://</code> o <code>https://</code>.</p>
                        </div>

                        {{-- Foto(s) por archivo (solo cuando type=photo) --}}
                        <div id="photoFilesWrap" class="mt-4 hidden">
                            <label for="photoFiles" class="block text-sm font-medium mb-1">Selecciona imagen(es)</label>

                            <input id="photoFiles" type="file" name="photos[]" accept="image/*" multiple
                                class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-white hover:file:bg-indigo-700" />

                            <div id="photoErrors" class="mt-2 text-xs text-red-600 hidden"></div>

                            {{-- Previsualización --}}
                            <div id="photoPreview" class="mt-3 grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4"></div>

                            <p class="mt-2 text-[11px] text-gray-500">
                                <span id="photoCount">0</span> imagen(es) seleccionadas. Máx. 10 MB c/u.
                            </p>
                        </div>
                        {{-- Video (solo cuando type=video) --}}
                        <div id="videoWrap" class="mt-4 hidden">
                            <label for="videoFile" class="block text-sm font-medium mb-1">Selecciona un video</label>

                            <input id="videoFile" type="file" name="video" accept="video/*"
                                class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-white hover:file:bg-indigo-700" />

                            {{-- Previsualización --}}
                            <video id="videoPreview" class="mt-3 w-full max-w-md rounded-lg border" controls
                                style="display:none"></video>

                            <p class="mt-2 text-[11px] text-gray-500">
                                Formatos comunes: MP4/WEBM/MOV. Para archivos grandes se usará carga por partes.
                            </p>
                        </div>

                    </div>
            @endif
        @endauth



        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex items-center justify-between mb-3">
                <p class="font-semibold">Selecciona páginas para publicar</p>
                <span class="text-xs text-gray-500">Solo publicará en páginas Sincronizadas</span>
            </div>

            {{-- GRID de tarjetas --}}
            <div id="pagesGrid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($pages as $p)
                    @php
                        $isAdmin = auth()->user()->isAdmin();

                        // Tu pivot
                        $myPivot = optional($p->users->firstWhere('id', auth()->id()))->pivot;
                        $isActiveMine = (bool) $myPivot?->is_active;
                        $hasTokenMine = !empty($myPivot?->page_access_token);

                        // Pivot ACTIVO de cualquier dueño (para admins)
                        $activeOwnerUser = $p->users->first(fn($u) => $u->pivot && $u->pivot->is_active);
                        $ownerName = $activeOwnerUser?->name;
                        $okForAdmin = (bool) $activeOwnerUser;

                        // Estado final de la tarjeta
                        $ok = $isAdmin ? $okForAdmin : $isActiveMine && $hasTokenMine;

                        $img = "https://graph.facebook.com/v20.0/{$p->page_id}/picture?type=square&width=96&height=96";
                    @endphp

                    <div class="page-card group rounded-xl border {{ $ok ? 'border-gray-200' : 'border-amber-200' }} bg-white p-3 hover:shadow-sm transition"
                        data-name="{{ Str::lower($p->name . ' ' . $p->page_id) }}"
                        data-status="{{ $ok ? 'active' : 'inactive' }}">

                        <div class="flex items-center gap-3">
                            <img src="{{ $img }}" alt=""
                                class="w-12 h-12 rounded-full ring-1 ring-gray-200" loading="lazy">
                            <div class="min-w-0">
                                <div class="truncate font-semibold">{{ $p->name }}</div>
                            </div>

                            {{-- Estado compacto: puntico + origen --}}
                            <div class="ml-auto flex flex-col items-end gap-1">
                                <span
                                    class="inline-block h-2.5 w-2.5 rounded-full {{ $ok ? 'bg-green-500' : 'bg-amber-400' }}"
                                    title="{{ $ok ? 'Vinculada' : 'Desvinculada' }}"
                                    aria-label="{{ $ok ? 'Vinculada' : 'Desvinculada' }}">
                                </span>

                                @if ($isAdmin && $ownerName)
                                    <span class="text-[10px] leading-none text-blue-500">
                                        {{ $ownerName }}</span>
                                @endif
                            </div>

                        </div>



                        <div class="mt-3 flex items-center gap-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="page_ids[]" value="{{ $p->id }}"
                                    class="page-checkbox rounded border-gray-300" {{ $ok ? '' : 'disabled' }}>
                                <span class="text-sm text-gray-700">Publicar aquí</span>
                            </label>

                            <div class="ml-auto flex items-center gap-2">
                                @if ($isAdmin)
                                    {{-- Admin: toggle según haya pivot activo de algún dueño --}}
                                    @if ($okForAdmin)
                                        <button type="submit" form="unlink-{{ $p->id }}"
                                            class="inline-flex items-center text-xs px-2 py-1 rounded-lg bg-gray-100 hover:bg-gray-200"
                                            onclick="event.stopPropagation(); return confirm('¿Desvincular «{{ $p->name }}» para todos los usuarios?');">
                                            Desvincular
                                        </button>
                                    @else
                                        <button type="submit" form="link-{{ $p->id }}"
                                            class="inline-flex items-center text-xs px-2 py-1 rounded-lg bg-blue-600 text-white hover:bg-blue-700"
                                            onclick="event.stopPropagation();">
                                            Vincular
                                        </button>
                                    @endif
                                @else
                                    {{-- Usuario normal: toggle según su propio pivot --}}
                                    @if ($isActiveMine && $hasTokenMine)
                                        <button type="submit" form="unlink-{{ $p->id }}"
                                            class="inline-flex items-center text-xs px-2 py-1 rounded-lg bg-gray-100 hover:bg-gray-200"
                                            onclick="event.stopPropagation(); return confirm('¿Desvincular «{{ $p->name }}»?');">
                                            Desvincular
                                        </button>
                                    @else
                                        <button type="submit" form="link-{{ $p->id }}"
                                            class="inline-flex items-center text-xs px-2 py-1 rounded-lg bg-blue-600 text-white hover:bg-blue-700"
                                            onclick="event.stopPropagation();">
                                            Vincular
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        @auth
            @if (auth()->user()->isAdmin())
                <div class="sticky bottom-4 z-10">
                    <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm flex items-center justify-between">
                        <div class="text-sm text-gray-600"><span id="selectedCountFooter">0</span> páginas seleccionadas
                        </div>
                        <button id="publishBtn"
                            class="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                            Publicar (Admin)
                        </button>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500">Solo el admin puede publicar en múltiples página.</p>
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
        @endforeach


        @if (session('publish_results'))
            <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="font-semibold mb-2">Resultados:</h3>
                <ul class="list-disc ml-5 space-y-2">
                    @foreach (session('publish_results') as $r)
                        <li>
                            <strong>{{ $r['page'] }}:</strong>
                            {!! $r['ok'] ? '<span class="text-green-700">OK</span>' : '<span class="text-red-700">Error</span>' !!}
                            @if (!empty($r['error']))
                                <pre class="text-xs bg-red-50 border border-red-200 rounded p-2 mt-1 whitespace-pre-wrap">{{ $r['error'] }}</pre>
                            @endif

                            @if (!empty($r['debug']))
                                <details class="mt-1">
                                    <summary class="text-sm text-gray-700 cursor-pointer">ver detalles técnicos</summary>
                                    <pre class="text-xs bg-gray-50 border border-gray-200 rounded p-2 mt-1 overflow-auto whitespace-pre-wrap">
{{ json_encode($r['debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
              </pre>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif


        <script>
            (function() {
                // ====== ELEMENTOS ======
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
                const typeVideo = document.getElementById('typeVideo'); // <-- agregado

                // Bloques condicionales
                const linkWrap = document.getElementById('linkWrap');
                const photoFilesWrap = document.getElementById('photoFilesWrap');
                const videoWrap = document.getElementById('videoWrap'); // <-- agregado

                // Archivos / preview (fotos)
                const photoFiles = document.getElementById('photoFiles');
                const photoPreview = document.getElementById('photoPreview');
                const photoCount = document.getElementById('photoCount');
                const photoErrors = document.getElementById('photoErrors');

                // Archivo / preview (video)
                const videoFile = document.getElementById('videoFile'); // <-- agregado
                const videoPreview = document.getElementById('videoPreview'); // <-- agregado

                const MAX_FILES = 50; // ajusta si quieres
                const MAX_SIZE = 10 * 1024 * 1024; // 10MB
                let selectedFiles = [];

                // ====== FILTROS Y SELECCIÓN ======
                function applyFilters() {
                    if (!grid) return;
                    const q = (searchInput?.value || '').trim().toLowerCase();
                    const st = statusFilter?.value || 'all';
                    const cards = Array.from(grid.querySelectorAll('.page-card'));
                    cards.forEach(card => {
                        const name = (card.dataset.name || '').toLowerCase();
                        const status = card.dataset.status || 'inactive';
                        const matchesText = !q || name.includes(q);
                        const matchesStatus = st === 'all' || st === status;
                        card.style.display = (matchesText && matchesStatus) ? '' : 'none';
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
                selectAll && selectAll.addEventListener('change', () => {
                    checkboxes().forEach(c => {
                        if (!c.disabled) c.checked = selectAll.checked;
                    });
                    updateCounts();
                });
                document.addEventListener('change', (e) => {
                    if (e.target.classList.contains('page-checkbox')) updateCounts();
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

                    toggleClear();
                    updateMsg();
                    updatePublishState();
                }
                typeText && typeText.addEventListener('change', refreshUI);
                typePhoto && typePhoto.addEventListener('change', refreshUI);
                typeVideo && typeVideo.addEventListener('change', refreshUI);

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
          title="Quitar">
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
                            errors.push(`No es imagen: ${f.name}`);
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
                    const isText = !!typeText?.checked;
                    const isPhoto = !!typePhoto?.checked;
                    const isVideo = !!typeVideo?.checked;

                    if (isText) {
                        const len = (msg?.value || '').trim().length;
                        return pagesOk && len > 0 && len <= MAX_MSG;
                    }
                    if (isPhoto) {
                        return pagesOk && selectedFiles.length > 0;
                    }
                    if (isVideo) {
                        return pagesOk && (videoFile?.files?.length || 0) > 0;
                    }
                    return false;
                }

                function updatePublishState() {
                    if (!publishBtn) return;
                    publishBtn.disabled = !contentValid();
                }

                // Evita doble submit
                form && form.addEventListener('submit', function() {
                    if (publishBtn) {
                        publishBtn.disabled = true;
                        publishBtn.textContent = 'Publicando...';
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


    </div>
@endsection
