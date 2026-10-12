@extends('layouts.app')

@section('content')
@php
    $nuevo = ['id' => null, 'name' => '', 'email' => '', 'role_id' => optional($asignables->firstWhere('slug', 'user') ?? $asignables->first())->id, 'cargo' => '', 'telefono' => '', 'activo' => true];
@endphp
<div class="max-w-7xl mx-auto px-2 sm:px-4 py-6 lg:py-8" x-data="usuarios()">
    <div class="mt-8 lg:mt-10 mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-indigo-600 mb-1">Administración</div>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight">Usuarios</h1>
            <p class="mt-1.5 text-sm text-gray-500">Quién entra a editus y con qué rol. Lo que puede hacer cada rol se define en <a href="{{ route('roles.index') }}" class="text-indigo-700 underline">Roles y permisos</a>.</p>
        </div>
        <button type="button" @click="abrir(@js($nuevo))" class="h-11 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">+ Nuevo usuario</button>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm break-words">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        @foreach ([['Usuarios', $resumen['total']], ['Activos', $resumen['activos']], ['Desactivados', $resumen['inactivos']], ['Entraron esta semana', $resumen['semana']]] as [$et, $v])
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3.5 shadow-sm"><div class="text-[11px] font-medium uppercase tracking-wide text-gray-400">{{ $et }}</div><div class="text-xl font-bold text-gray-900">{{ $v }}</div></div>
        @endforeach
    </div>

    <form method="GET" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-3 mb-4 grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_12rem_10rem_auto] gap-2">
        <input name="q" value="{{ $q }}" placeholder="Buscar por nombre, correo o cargo" class="h-10 rounded-lg border-gray-200 text-sm">
        <select name="rol" class="h-10 rounded-lg border-gray-200 text-sm"><option value="">Todos los roles</option>@foreach ($roles as $r)<option value="{{ $r->id }}" @selected((string) $rol === (string) $r->id)>{{ $r->name }} ({{ $r->users_count }})</option>@endforeach</select>
        <select name="estado" class="h-10 rounded-lg border-gray-200 text-sm"><option value="">Todos</option><option value="activos" @selected($estado === 'activos')>Activos</option><option value="inactivos" @selected($estado === 'inactivos')>Desactivados</option></select>
        <button class="h-10 rounded-lg bg-gray-900 text-white px-4 text-sm font-semibold">Filtrar</button>
    </form>

    {{-- Tabla (escritorio) / tarjetas (celular) --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="hidden md:grid grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_8rem_16.5rem] gap-3 px-4 py-3 text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-100">
            <div>Usuario</div><div>Rol</div><div>Último acceso</div><div>Estado</div><div class="text-right">Acciones</div>
        </div>
        @forelse ($usuarios as $usr)
            @php
                $puede = $acceso->puedeGestionar($actor, $usr);
                $datos = ['id' => $usr->id, 'name' => $usr->name, 'email' => $usr->email, 'role_id' => $usr->role_id, 'cargo' => $usr->cargo, 'telefono' => $usr->telefono, 'activo' => $usr->estaActivo(), 'yo' => $usr->id === $actor->id];
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_8rem_16.5rem] gap-2 md:gap-3 px-4 py-3 border-b border-gray-100 last:border-0 md:items-center {{ $usr->estaActivo() ? '' : 'bg-gray-50' }}">
                <div class="flex items-center gap-3 min-w-0">
                    @if ($usr->avatarUrl())<img src="{{ $usr->avatarUrl() }}" alt="" class="h-10 w-10 rounded-full object-cover shrink-0">
                    @else<span class="h-10 w-10 rounded-full bg-indigo-100 text-indigo-800 grid place-items-center text-sm font-bold shrink-0">{{ $usr->iniciales() }}</span>@endif
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 truncate">{{ $usr->name }} @if ($usr->id === $actor->id)<span class="text-[10px] font-semibold text-indigo-700">(tú)</span>@endif</div>
                        <div class="text-xs text-gray-500 truncate">{{ $usr->email }}{{ $usr->cargo ? ' · ' . $usr->cargo : '' }}</div>
                    </div>
                </div>
                <div class="text-sm"><span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $usr->isAdmin() ? 'bg-purple-50 text-purple-700' : 'bg-indigo-50 text-indigo-700' }}">{{ $usr->role->name ?? 'Sin rol' }}</span>
                    <span class="text-[11px] text-gray-400 ml-1">{{ $usr->meta_pages_count }} página(s)</span></div>
                <div class="text-xs text-gray-500">{{ $usr->ultimo_acceso ? $usr->ultimo_acceso->diffForHumans() : 'Nunca' }}</div>
                <div>@if ($usr->estaActivo())<span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Activo</span>@else<span class="inline-flex items-center gap-1 text-xs font-semibold text-gray-500"><span class="h-2 w-2 rounded-full bg-gray-400"></span>Desactivado</span>@endif</div>
                <div class="flex flex-wrap md:justify-end gap-2">
                    @if ($puede)
                        <button type="button" @click="abrir(@js($datos))" class="h-8 rounded-lg border border-gray-200 px-3 text-xs font-semibold text-gray-700 hover:bg-gray-50">Editar</button>
                        @if ($usr->id !== $actor->id)
                            <form method="POST" action="{{ route('usuarios.activo', $usr) }}">@csrf<button class="h-8 rounded-lg border px-3 text-xs font-semibold {{ $usr->estaActivo() ? 'border-amber-200 text-amber-800 hover:bg-amber-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50' }}" onclick="return confirm(@js($usr->estaActivo() ? '¿Desactivar a ' . $usr->name . '? Ya no podrá entrar a editus.' : '¿Volver a activar a ' . $usr->name . '?'))">{{ $usr->estaActivo() ? 'Desactivar' : 'Activar' }}</button></form>
                            <form method="POST" action="{{ route('usuarios.destroy', $usr) }}">@csrf @method('DELETE')<button class="h-8 rounded-lg border border-rose-200 px-3 text-xs font-semibold text-rose-700 hover:bg-rose-50" onclick="return confirm(@js('¿Borrar a ' . $usr->name . '? Se pierde su usuario (sus publicaciones quedan). Esto no se puede deshacer.'))">Borrar</button></form>
                        @endif
                    @else
                        <span class="text-[11px] text-gray-400">Solo un administrador puede cambiarlo</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-10 text-center text-sm text-gray-500">No hay usuarios con esos filtros.</div>
        @endforelse
        <div class="px-4 py-3">{{ $usuarios->links() }}</div>
    </div>

    {{-- Ventana: crear / editar --}}
    <div x-show="visible" x-cloak class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="visible = false">
        <form method="POST" :action="f.id ? @js(url('/admin/usuarios')) + '/' + f.id : @js(route('usuarios.store'))" class="w-full sm:max-w-lg max-h-[92vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl bg-white shadow-2xl" @click.outside="visible = false">
            @csrf
            <template x-if="f.id"><input type="hidden" name="_method" value="PUT"></template>
            <div class="sticky top-0 bg-white flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-900" x-text="f.id ? 'Editar usuario' : 'Nuevo usuario'"></h3>
                <button type="button" @click="visible = false" class="h-9 w-9 rounded-full hover:bg-gray-100 text-gray-500 text-xl leading-none">×</button>
            </div>
            <div class="p-5 space-y-4">
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Nombre *</label><input name="name" x-model="f.name" required maxlength="255" class="w-full h-10 rounded-lg border-gray-200 text-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Correo *</label><input type="email" name="email" x-model="f.email" required maxlength="255" class="w-full h-10 rounded-lg border-gray-200 text-sm"></div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Cargo</label><input name="cargo" x-model="f.cargo" maxlength="100" placeholder="Periodista, editor…" class="w-full h-10 rounded-lg border-gray-200 text-sm"></div>
                    <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Teléfono</label><input name="telefono" x-model="f.telefono" maxlength="40" class="w-full h-10 rounded-lg border-gray-200 text-sm"></div>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Rol *</label>
                    <select name="role_id" x-model="f.role_id" :disabled="f.yo" class="w-full h-10 rounded-lg border-gray-200 text-sm">
                        @foreach ($asignables as $r)<option value="{{ $r->id }}">{{ $r->name }}{{ $r->descripcion ? ' — ' . \Illuminate\Support\Str::limit($r->descripcion, 60) : '' }}</option>@endforeach
                    </select>
                    <template x-if="f.yo"><input type="hidden" name="role_id" :value="f.role_id"></template>
                    <p class="text-[11px] text-gray-500 mt-1" x-show="f.yo">No puedes cambiar tu propio rol.</p>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1" x-text="f.id ? 'Nueva contraseña (opcional)' : 'Contraseña'"></label>
                    <input type="text" name="password" x-model="f.password" minlength="8" maxlength="100" autocomplete="new-password" :placeholder="f.id ? 'Déjala vacía para no cambiarla' : 'Vacía = se genera una temporal'" class="w-full h-10 rounded-lg border-gray-200 text-sm">
                    <button type="button" @click="f.password = generar()" class="mt-1 text-[11px] font-semibold text-indigo-700 hover:underline">Generar una segura</button>
                </div>
                <template x-if="!f.id"><label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="activo" value="1" checked class="h-4 w-4 rounded border-gray-300 text-indigo-600"> Puede entrar desde ya</label></template>
            </div>
            <div class="sticky bottom-0 bg-white px-5 py-4 border-t border-gray-100 flex justify-end gap-2">
                <button type="button" @click="visible = false" class="h-10 rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700">Cancelar</button>
                <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold" x-text="f.id ? 'Guardar cambios' : 'Crear usuario'"></button>
            </div>
        </form>
    </div>
</div>

<script>
function usuarios() {
  return {
    visible: false, f: {},
    abrir(datos) { this.f = Object.assign({ password: '' }, datos, { role_id: datos.role_id ? String(datos.role_id) : '' }); this.visible = true; },
    generar() {
      const c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789', a = new Uint32Array(12);
      crypto.getRandomValues(a); return Array.from(a, n => c[n % c.length]).join('');
    },
  };
}
</script>
@endsection
