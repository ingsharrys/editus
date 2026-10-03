{{--
  Selector de periodistas (usuarios de la app) que ven una página o canal de la organización.
  $nombre: nombre del campo (p. ej. "usuarios[12]" o "usuarios"); se envía como lista.
  $seleccion: ['*'] = todos, [] = nadie, ['willy', ...] = algunos.
  $periodistas: lista del backend [{username, role}]. Si está vacía, cae a un campo de texto.
--}}
@php
    $todos = in_array('*', $seleccion, true);
    $conocidos = collect($periodistas)->map(fn($u) => strtolower($u['username']))->all();
    $resumen = $todos ? 'Todos los periodistas' : (empty($seleccion) ? 'Nadie (solo desde la app)' : implode(', ', $seleccion));
@endphp
@if (!empty($periodistas))
    <details class="periodistas relative">
        <summary class="list-none cursor-pointer select-none flex items-center justify-between gap-2 rounded-xl border border-gray-300 bg-white px-3 py-1.5 text-sm hover:border-gray-400">
            <span class="resumen truncate {{ $todos ? 'text-gray-800' : (empty($seleccion) ? 'text-red-600' : 'text-indigo-700 font-semibold') }}">{{ $resumen }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0 text-gray-400"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="absolute z-30 mt-1 w-64 max-h-64 overflow-auto rounded-xl border border-gray-200 bg-white shadow-lg p-2 text-sm">
            <input type="hidden" name="{{ $nombre }}[]" value="">
            <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 font-semibold text-gray-800 cursor-pointer">
                <input type="checkbox" name="{{ $nombre }}[]" value="*" class="todos h-4 w-4 rounded border-gray-300 text-indigo-600" {{ $todos ? 'checked' : '' }}>
                ★ Todos los periodistas
            </label>
            <div class="border-t border-gray-100 my-1"></div>
            @foreach ($periodistas as $u)
                <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="{{ $nombre }}[]" value="{{ strtolower($u['username']) }}" class="uno h-4 w-4 rounded border-gray-300 text-indigo-600" {{ !$todos && in_array(strtolower($u['username']), $seleccion, true) ? 'checked' : '' }}>
                    <span class="text-gray-800">{{ $u['username'] }}</span>
                    @if ($u['role'])<span class="text-gray-400 text-xs">· {{ $u['role'] }}</span>@endif
                </label>
            @endforeach
            @foreach ($seleccion as $x)
                @if ($x !== '*' && !in_array($x, $conocidos, true))
                    <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="{{ $nombre }}[]" value="{{ $x }}" class="uno h-4 w-4 rounded border-gray-300 text-indigo-600" checked>
                        <span class="text-gray-500">{{ $x }}</span><span class="text-gray-400 text-xs">· ya no existe</span>
                    </label>
                @endif
            @endforeach
        </div>
    </details>
@else
    <input type="text" name="{{ $nombre }}" value="{{ $todos ? '*' : implode(', ', $seleccion) }}" placeholder="* = todos; vacío = nadie" class="w-full rounded-xl border-gray-300 text-sm py-1.5">
@endif
