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



        {{-- Publicar --}}
        <form method="POST" action="{{ route('meta.pages.publish') }}" class="space-y-4" id="publishForm">
            @csrf
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                {{-- Mensaje --}}
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label for="messageInput" class="block text-sm font-medium">Mensaje</label>
                        <span class="text-[11px] text-gray-500">
                            <span id="msgCount">0</span> / 63206
                        </span>
                    </div>

                    <textarea id="messageInput" name="message" rows="3" placeholder="Escribe el mensaje…" required
                        class="w-full min-h-[96px] rounded-xl border border-gray-200 px-3 py-2 text-sm placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500"></textarea>

                    <p class="mt-1 text-[11px] text-gray-500">
                        Consejo: puedes pegar emojis y enlaces; nosotros nos encargamos del formato.
                    </p>
                </div>

                {{-- Enlace (opcional) --}}
                <div class="mt-4">
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
                            title="Limpiar enlace">
                            Limpiar
                        </button>
                    </div>

                    <p class="mt-1 text-[11px] text-gray-500">
                        Usa <code>http://</code> o <code>https://</code>. Si dejas vacío, se publicará solo el texto.
                    </p>
                </div>
            </div>


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
                        <div
                            class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm flex items-center justify-between">
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
                    <p class="text-sm text-gray-500">Solo el admin puede publicar en múltiples páginas.</p>
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

        {{-- Paginación --}}
        <div class="mt-6">
            {{ $pages->links() }}
        </div>

        {{-- Resultados de publicación --}}
        @if (session('publish_results'))
            <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4">
                <h3 class="font-semibold mb-2">Resultados:</h3>
                <ul class="list-disc ml-5 space-y-1">
                    @foreach (session('publish_results') as $r)
                        <li>
                            <strong>{{ $r['page'] }}:</strong>
                            {!! $r['ok'] ? '<span class="text-green-700">OK</span>' : '<span class="text-red-700">Error</span>' !!}
                            @if (!$r['ok'])
                                <pre class="text-xs bg-gray-50 border border-gray-200 rounded p-2 mt-1 whitespace-pre-wrap">{{ $r['error'] }}</pre>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- JS mínimo: búsqueda, filtro, seleccionar todas, contador, habilitar botón --}}
        <script>
            (function() {
                const searchInput = document.getElementById('searchInput');
                const statusFilter = document.getElementById('statusFilter');
                const grid = document.getElementById('pagesGrid');
                const selectAll = document.getElementById('selectAll');
                const publishBtn = document.getElementById('publishBtn');
                const selectedCount = document.getElementById('selectedCount');
                const selectedCountFooter = document.getElementById('selectedCountFooter');
                const checkboxes = () => Array.from(document.querySelectorAll('.page-checkbox'));

                function applyFilters() {
                    const q = (searchInput?.value || '').trim().toLowerCase();
                    const st = statusFilter?.value || 'all';
                    const cards = Array.from(grid.querySelectorAll('.page-card'));
                    cards.forEach(card => {
                        const name = card.dataset.name || '';
                        const status = card.dataset.status || 'inactive';
                        const matchesText = !q || name.includes(q);
                        const matchesStatus = st === 'all' || st === status;
                        card.style.display = (matchesText && matchesStatus) ? '' : 'none';
                    });
                }

                function updateCounts() {
                    const count = checkboxes().filter(c => c.checked).length;
                    if (selectedCount) selectedCount.textContent = count;
                    if (selectedCountFooter) selectedCountFooter.textContent = count;
                    if (publishBtn) publishBtn.disabled = (count === 0);
                }

                if (searchInput) searchInput.addEventListener('input', applyFilters);
                if (statusFilter) statusFilter.addEventListener('change', applyFilters);

                if (selectAll) {
                    selectAll.addEventListener('change', () => {
                        checkboxes().forEach(c => {
                            if (!c.disabled) c.checked = selectAll.checked;
                        });
                        updateCounts();
                    });
                }

                document.addEventListener('change', (e) => {
                    if (e.target.classList.contains('page-checkbox')) updateCounts();
                });

                // init
                applyFilters();
                updateCounts();
            })();
        </script>
        <script>
            (function() {
                const msg = document.getElementById('messageInput');
                const msgCount = document.getElementById('msgCount');
                const max = 63206;

                const link = document.getElementById('linkInput');
                const clear = document.getElementById('clearLink');

                // Auto-grow del textarea + contador
                function updateMsg() {
                    if (!msg) return;
                    // autogrow
                    msg.style.height = 'auto';
                    msg.style.height = (msg.scrollHeight) + 'px';
                    // contador
                    const len = (msg.value || '').length;
                    if (msgCount) {
                        msgCount.textContent = len;
                        msgCount.classList.toggle('text-red-600', len > max);
                    }
                }
                msg && msg.addEventListener('input', updateMsg);
                updateMsg();

                // Botón limpiar enlace
                function toggleClear() {
                    if (!clear || !link) return;
                    clear.classList.toggle('hidden', !(link.value || '').trim());
                }
                link && link.addEventListener('input', toggleClear);
                clear && clear.addEventListener('click', () => {
                    link.value = '';
                    link.dispatchEvent(new Event('input'));
                    link.focus();
                });
                toggleClear();
            })();
        </script>

    </div>
@endsection
