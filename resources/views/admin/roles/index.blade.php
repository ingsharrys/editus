@extends('layouts.app')

@section('content')
@php
    $total = collect($grupos)->flatten(1)->count();
    $nuevo = ['id' => null, 'name' => '', 'descripcion' => '', 'permisos' => []];
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8" x-data="roles()">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Administración</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Roles y permisos</h1>
            <p class="mt-1.5 text-sm text-gray-500 max-w-2xl">Cada rol decide qué módulos de editus ve y usa una persona. Asigna el rol a cada quien en <a href="{{ route('usuarios.index') }}" class="text-indigo-700 underline">Usuarios</a>. Los cambios aplican al instante.</p>
        </div>
        <button type="button" @click="abrir(@js($nuevo))" class="h-11 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">+ Nuevo rol</button>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">{{ $errors->first() }}</div>@endif

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach ($roles as $r)
            @php $lista = $r->listaPermisos(); @endphp
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 flex flex-col min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap"><h3 class="text-lg font-bold text-gray-900 truncate">{{ $r->name }}</h3>
                            @if ($r->esAdmin())<span class="rounded-full bg-purple-50 text-purple-700 px-2 py-0.5 text-[10px] font-bold">TODO</span>@elseif ($r->sistema)<span class="rounded-full bg-gray-100 text-gray-600 px-2 py-0.5 text-[10px] font-bold">DEL SISTEMA</span>@endif</div>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $r->descripcion ?: 'Sin descripción' }}</p>
                    </div>
                    <span class="shrink-0 text-right"><span class="block text-xl font-bold text-gray-900 leading-none">{{ $r->users_count }}</span><span class="text-[10px] uppercase tracking-wide text-gray-400">usuario{{ $r->users_count === 1 ? '' : 's' }}</span></span>
                </div>
                <div class="mt-3">
                    <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1"><span>Permisos</span><span>{{ count($lista) }} de {{ $total }}</span></div>
                    <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden"><div class="h-1.5 rounded-full {{ $r->esAdmin() ? 'bg-purple-500' : 'bg-indigo-500' }}" style="width: {{ $total ? round(100 * count($lista) / $total) : 0 }}%"></div></div>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @forelse (array_slice($lista, 0, 8) as $p)<span class="rounded-md bg-gray-100 text-gray-700 px-2 py-0.5 text-[11px]">{{ \App\Models\Role::nombrePermiso($p) }}</span>@empty<span class="text-xs text-gray-400">Sin permisos: solo puede ver su perfil.</span>@endforelse
                    @if (count($lista) > 8)<span class="rounded-md bg-gray-100 text-gray-500 px-2 py-0.5 text-[11px]">+{{ count($lista) - 8 }} más</span>@endif
                </div>
                <div class="mt-auto pt-4 flex flex-wrap gap-2">
                    @if ($r->esAdmin())
                        <span class="text-xs text-gray-500">El administrador tiene todos los permisos siempre.</span>
                    @else
                        <button type="button" @click="abrir(@js(['id' => $r->id, 'name' => $r->name, 'descripcion' => $r->descripcion, 'permisos' => $lista]))" class="h-9 rounded-lg bg-[#00024f] text-white px-4 text-xs font-semibold">Editar permisos</button>
                        @unless ($r->sistema)
                            <form method="POST" action="{{ route('roles.destroy', $r) }}">@csrf @method('DELETE')<button class="h-9 rounded-lg border border-rose-200 px-3 text-xs font-semibold text-rose-700 hover:bg-rose-50" onclick="return confirm(@js('¿Borrar el rol «' . $r->name . '»?'))">Borrar</button></form>
                        @endunless
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Ventana: crear / editar rol --}}
    <div x-show="visible" x-cloak class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="visible = false">
        <form method="POST" :action="f.id ? @js(url('/admin/roles')) + '/' + f.id : @js(route('roles.store'))" class="w-full sm:max-w-2xl max-h-[92vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl bg-white shadow-2xl" @click.outside="visible = false">
            @csrf
            <template x-if="f.id"><input type="hidden" name="_method" value="PUT"></template>
            <div class="sticky top-0 z-10 bg-white flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-900" x-text="f.id ? 'Editar rol' : 'Nuevo rol'"></h3>
                <button type="button" @click="visible = false" class="h-9 w-9 rounded-full hover:bg-gray-100 text-gray-500 text-xl leading-none">×</button>
            </div>
            <div class="p-5 space-y-4">
                <div class="grid sm:grid-cols-2 gap-3">
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Nombre *</label><input name="name" x-model="f.name" required maxlength="60" placeholder="Periodista, Editor, Cliente…" class="w-full h-10 rounded-lg border-gray-200 text-sm"></div>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Descripción</label><input name="descripcion" x-model="f.descripcion" maxlength="255" placeholder="Para qué es este rol" class="w-full h-10 rounded-lg border-gray-200 text-sm"></div>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-900">Permisos <span class="text-gray-400 font-normal" x-text="'(' + f.permisos.length + ')'"></span></span>
                    <span class="flex gap-3 text-xs font-semibold"><button type="button" @click="todos(true)" class="text-indigo-700 hover:underline">Marcar todos</button><button type="button" @click="todos(false)" class="text-gray-500 hover:underline">Quitar todos</button></span>
                </div>
                @foreach ($grupos as $grupo => $permisos)
                    <div class="rounded-xl border border-gray-200 overflow-hidden">
                        <div class="flex items-center justify-between bg-gray-50 px-4 py-2.5">
                            <span class="text-[12px] font-bold uppercase tracking-wide text-gray-600">{{ $grupo }}</span>
                            <button type="button" @click="grupo(@js(array_keys($permisos)))" class="text-[11px] font-semibold text-indigo-700 hover:underline">Todo el grupo</button>
                        </div>
                        <div class="divide-y divide-gray-100">
                            @foreach ($permisos as $clave => [$nombre, $detalle])
                                @php $puedeDar = $esAdmin || in_array($clave, $mios, true); @endphp
                                <label class="flex items-start gap-3 px-4 py-2.5 {{ $puedeDar ? 'cursor-pointer hover:bg-gray-50' : 'opacity-50' }}">
                                    <input type="checkbox" name="permisos[]" value="{{ $clave }}" x-model="f.permisos" @disabled(!$puedeDar) class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600">
                                    <span class="min-w-0"><span class="block text-sm font-medium text-gray-800">{{ $nombre }}</span><span class="block text-xs text-gray-500">{{ $detalle }}{{ $puedeDar ? '' : ' · tú no tienes este permiso' }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="sticky bottom-0 bg-white px-5 py-4 border-t border-gray-100 flex justify-end gap-2">
                <button type="button" @click="visible = false" class="h-10 rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700">Cancelar</button>
                <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold" x-text="f.id ? 'Guardar rol' : 'Crear rol'"></button>
            </div>
        </form>
    </div>
</div>

<script>
function roles() {
  const permitidos = @json($esAdmin ? \App\Models\Role::claves() : $mios);
  return {
    visible: false, f: { permisos: [] },
    abrir(datos) { this.f = Object.assign({}, datos, { permisos: [...(datos.permisos || [])] }); this.visible = true; },
    todos(on) { this.f.permisos = on ? [...permitidos] : []; },
    grupo(claves) {
      const dar = claves.filter(c => permitidos.includes(c));
      const todosMarcados = dar.every(c => this.f.permisos.includes(c));
      this.f.permisos = todosMarcados ? this.f.permisos.filter(c => !dar.includes(c)) : [...new Set([...this.f.permisos, ...dar])];
    },
  };
}
</script>
@endsection
