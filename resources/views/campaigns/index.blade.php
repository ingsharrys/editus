@extends('layouts.app')

@section('content')
    <div class="max-w-5xl mx-auto mt-20 space-y-5">
        @php $fmt = fn($n) => number_format((int) $n, 0, ',', '.'); @endphp

        <div>
            <h2 class="font-semibold text-xl">Campañas</h2>
            <p class="text-xs text-gray-500">
                Toda publicación debe pertenecer a una campaña. Crea una por cada estrategia o cliente
                y luego mide su rendimiento en los informes.
            </p>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3">
                @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
            </div>
        @endif

        {{-- Crear campaña --}}
        <form method="POST" action="{{ route('campaigns.store') }}"
              class="rounded-xl border border-gray-200 bg-white p-4 flex flex-wrap items-end gap-3">
            @csrf
            <div class="flex-1 min-w-[200px]">
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Nombre de la nueva campaña *</label>
                <input type="text" name="name" required maxlength="120" placeholder="Ej: Salud 2026, Elecciones, Cliente X…"
                       value="{{ old('name') }}" class="w-full rounded-lg border-gray-200 text-sm">
            </div>
            <div class="flex-[2] min-w-[240px]">
                <label class="block text-[11px] font-medium text-gray-600 mb-1">Descripción (opcional)</label>
                <input type="text" name="description" maxlength="500" value="{{ old('description') }}"
                       class="w-full rounded-lg border-gray-200 text-sm">
            </div>
            <button class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm hover:bg-blue-700">➕ Crear campaña</button>
        </form>

        {{-- Listado --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
                        <th class="py-2.5 px-3">Campaña</th>
                        <th class="py-2.5 px-3">Estado</th>
                        <th class="py-2.5 px-3 text-right">Publicaciones</th>
                        <th class="py-2.5 px-3 text-right">Alcance total</th>
                        <th class="py-2.5 px-3 text-right">Interacciones</th>
                        <th class="py-2.5 px-3">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($campaigns as $c)
                        <tr class="border-b last:border-0 {{ $c->is_active ? '' : 'opacity-60' }}">
                            <td class="py-2 px-3">
                                <div class="font-medium">
                                    {{ $c->name }}
                                    @if ($c->is_system)
                                        <span class="ml-1 text-[10px] text-purple-700 bg-purple-50 border border-purple-200 rounded px-1.5 py-0.5">⚙️ Sistema</span>
                                    @endif
                                </div>
                                @if ($c->description)
                                    <div class="text-[11px] text-gray-500 max-w-[340px] truncate" title="{{ $c->description }}">{{ $c->description }}</div>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                @if ($c->is_active)
                                    <span class="text-[10px] text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1.5 py-0.5">Activa</span>
                                @else
                                    <span class="text-[10px] text-gray-600 bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5">Inactiva</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-right">{{ $fmt($c->posts_count) }}</td>
                            <td class="py-2 px-3 text-right">{{ $fmt($c->total_reach) }}</td>
                            <td class="py-2 px-3 text-right">{{ $fmt($c->total_inter) }}</td>
                            <td class="py-2 px-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('reports.posts', ['campaign_id' => $c->id]) }}"
                                       class="text-xs text-blue-600 hover:underline">Ver informe</a>
                                    @unless ($c->is_system)
                                        <button type="button" onclick="editCampaign({{ $c->id }}, @js($c->name), @js($c->description))"
                                                class="text-xs text-gray-600 hover:underline">Editar</button>
                                        <form method="POST" action="{{ route('campaigns.toggle', $c) }}"
                                              onsubmit="return confirm('{{ $c->is_active ? '¿Desactivar' : '¿Activar' }} la campaña «{{ $c->name }}»?');">
                                            @csrf
                                            <button class="text-xs {{ $c->is_active ? 'text-amber-600' : 'text-emerald-600' }} hover:underline">
                                                {{ $c->is_active ? 'Desactivar' : 'Activar' }}
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Modal simple de edición --}}
        <div id="editModal" class="fixed inset-0 z-[100] hidden">
            <div class="absolute inset-0 bg-black/40" onclick="closeEdit()"></div>
            <div class="relative mx-auto mt-28 w-full max-w-md rounded-2xl bg-white shadow-xl p-5">
                <h3 class="font-semibold mb-3">Editar campaña</h3>
                <form method="POST" id="editForm">
                    @csrf
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Nombre *</label>
                    <input type="text" name="name" id="editName" required maxlength="120"
                           class="w-full rounded-lg border-gray-200 text-sm mb-3">
                    <label class="block text-[11px] font-medium text-gray-600 mb-1">Descripción</label>
                    <input type="text" name="description" id="editDescription" maxlength="500"
                           class="w-full rounded-lg border-gray-200 text-sm mb-4">
                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="closeEdit()" class="px-3 py-2 text-sm rounded-lg border border-gray-200">Cancelar</button>
                        <button class="px-3 py-2 text-sm rounded-lg bg-blue-600 text-white">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function editCampaign(id, name, description) {
            document.getElementById('editForm').action = '{{ url('campanas') }}/' + id;
            document.getElementById('editName').value = name || '';
            document.getElementById('editDescription').value = description || '';
            document.getElementById('editModal').classList.remove('hidden');
        }
        function closeEdit() {
            document.getElementById('editModal').classList.add('hidden');
        }
    </script>
@endsection
