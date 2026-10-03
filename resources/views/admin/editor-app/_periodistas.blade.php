{{--
  Selector de periodistas (usuarios de la app) que ven una página o canal de la organización.
  $nombre: nombre del campo (p. ej. "usuarios[12]" o "usuarios"); se envía como lista.
  $seleccion: ['*'] = todos, [] = nadie, ['willy', ...] = algunos.
  $periodistas: lista del backend [{username, role}]. Si está vacía, cae a un campo de texto.
--}}
@php
    $todos = in_array('*', $seleccion, true);
    $conocidos = collect($periodistas)->map(fn($u) => strtolower($u['username']))->all();
    $resumen = $todos ? 'Todos los periodistas' : (empty($seleccion) ? 'Nadie' : implode(', ', $seleccion));
    $tono = $todos ? 'text-gray-700' : (empty($seleccion) ? 'text-rose-600' : 'text-indigo-700');
@endphp
@if (!empty($periodistas))
    <details class="periodistas relative">
        <summary class="list-none cursor-pointer select-none flex items-center gap-2 h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm shadow-sm hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-200">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-gray-400"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span class="resumen flex-1 min-w-0 truncate {{ $tono }}">{{ $resumen }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0 text-gray-400"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="absolute right-0 z-30 mt-1.5 w-64 max-h-72 overflow-auto rounded-xl border border-gray-200 bg-white shadow-xl p-1.5 text-sm">
            <input type="hidden" name="{{ $nombre }}[]" value="">
            <label class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-gray-50 font-semibold text-gray-800 cursor-pointer">
                <input type="checkbox" name="{{ $nombre }}[]" value="*" class="todos h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200" {{ $todos ? 'checked' : '' }}>
                Todos los periodistas
            </label>
            <div class="border-t border-gray-100 my-1"></div>
            @foreach ($periodistas as $u)
                <label class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="{{ $nombre }}[]" value="{{ strtolower($u['username']) }}" class="uno h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-200" {{ !$todos && in_array(strtolower($u['username']), $seleccion, true) ? 'checked' : '' }}>
                    <span class="h-6 w-6 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold flex items-center justify-center uppercase shrink-0">{{ mb_substr($u['username'], 0, 2) }}</span>
                    <span class="flex-1 min-w-0 truncate text-gray-800">{{ $u['username'] }}</span>
                    @if ($u['role'])<span class="text-gray-400 text-[11px] uppercase tracking-wide">{{ $u['role'] }}</span>@endif
                </label>
            @endforeach
            @foreach ($seleccion as $x)
                @if ($x !== '*' && !in_array($x, $conocidos, true))
                    <label class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="{{ $nombre }}[]" value="{{ $x }}" class="uno h-4 w-4 rounded border-gray-300 text-indigo-600" checked>
                        <span class="flex-1 text-gray-500">{{ $x }}</span><span class="text-gray-400 text-[11px]">ya no existe</span>
                    </label>
                @endif
            @endforeach
        </div>
    </details>
@else
    <input type="text" name="{{ $nombre }}" value="{{ $todos ? '*' : implode(', ', $seleccion) }}" placeholder="* = todos · vacío = nadie" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm">
@endif
