@extends('layouts.app')

@section('content')
@php
    $editando = $c->exists;
    $mediosSel = old('medios', (array) ($c->medios ?? []));
    $extraSel = array_map('intval', old('paginas_extra', $extra));
    $sinMedio = $paginas->whereNull('medio_slug');
@endphp
<div class="max-w-4xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <a href="{{ route('campaigns.index') }}" class="text-sm text-indigo-700 hover:underline">← Campañas</a>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ $editando ? 'Editar campaña' : 'Nueva campaña' }}</h1>
        <p class="mt-1 text-sm text-gray-500">Los medios que elijas definen en qué páginas se publica. Al publicar podrás excluir páginas puntuales.</p>
    </div>

    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ $editando ? route('campaigns.update', $c) : route('campaigns.store') }}" class="space-y-5">
        @csrf

        {{-- 1. Datos --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
            <div class="flex items-center gap-2"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">1</span><h2 class="text-base font-bold text-gray-900">Datos de la campaña</h2></div>
            <div class="grid sm:grid-cols-[minmax(0,1fr)_14rem] gap-3">
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Nombre *</label><input name="name" required maxlength="120" value="{{ old('name', $c->name) }}" placeholder="Ej: Alcaldía Neiva 2027, Ferretería El Tornillo" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Tipo *</label>
                    <select name="tipo" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm">
                        @foreach ($tipos as $k => $et)<option value="{{ $k }}" @selected(old('tipo', $c->tipo) === $k)>{{ $et }}</option>@endforeach
                    </select></div>
            </div>
            <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Descripción corta</label><input name="description" maxlength="500" value="{{ old('description', $c->description) }}" placeholder="Para qué es la campaña, en una línea" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
            <div class="grid sm:grid-cols-3 gap-3">
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Territorio</label><input name="territorio" maxlength="120" value="{{ old('territorio', $c->territorio) }}" placeholder="Ej: Neiva y norte del Huila" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Desde</label><input type="date" name="starts_on" value="{{ old('starts_on', $c->starts_on?->toDateString()) }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Hasta</label><input type="date" name="ends_on" value="{{ old('ends_on', $c->ends_on?->toDateString()) }}" class="w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm"></div>
            </div>
        </div>

        {{-- 2. Medios --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <div class="flex items-center gap-2 mb-1"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">2</span><h2 class="text-base font-bold text-gray-900">¿En qué medios se publica? *</h2></div>
            <p class="text-xs text-gray-500 mb-4 ml-8">Se usan las páginas de Facebook e Instagram de cada medio. Asigna el medio de cada página en App del editor.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach ($medios as $slug => $nombre)
                    @php $pags = $porMedio->get($slug, collect()); @endphp
                    <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-3 cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                        <input type="checkbox" name="medios[]" value="{{ $slug }}" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(in_array($slug, $mediosSel, true))>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-gray-900">{{ $nombre }}</span>
                            <span class="block text-[11px] text-gray-500 truncate" title="{{ $pags->pluck('name')->implode(', ') }}">{{ $pags->count() ? $pags->count() . ' página(s): ' . $pags->pluck('name')->take(3)->implode(', ') . ($pags->count() > 3 ? '…' : '') : 'Sin páginas asignadas' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <details class="mt-4 rounded-xl border border-gray-200" @if ($extraSel) open @endif>
                <summary class="cursor-pointer select-none px-3 py-2.5 text-sm font-semibold text-gray-700">Páginas adicionales sin medio <span class="text-gray-400 font-normal">({{ count($extraSel) }} elegida(s))</span></summary>
                <div class="border-t border-gray-100 p-3">
                    <input type="search" placeholder="Buscar página" class="w-full h-9 rounded-lg border-gray-200 text-sm shadow-sm mb-2" oninput="const q=this.value.toLowerCase(); this.parentElement.querySelectorAll('[data-nombre]').forEach(e=>e.style.display=!q||e.dataset.nombre.includes(q)?'':'none')">
                    <div class="max-h-60 overflow-y-auto grid sm:grid-cols-2 gap-1">
                        @forelse ($sinMedio as $p)
                            <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 text-sm" data-nombre="{{ mb_strtolower($p->name) }}">
                                <input type="checkbox" name="paginas_extra[]" value="{{ $p->id }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(in_array($p->id, $extraSel, true))>
                                <span class="truncate">{{ $p->name }}</span>@if ($p->instagram_business_account_id)<span class="text-[10px] text-pink-600">IG</span>@endif
                            </label>
                        @empty
                            <p class="text-xs text-gray-500">Todas las páginas tienen medio asignado.</p>
                        @endforelse
                    </div>
                </div>
            </details>
        </div>

        {{-- 3. IA --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
            <div class="flex items-center gap-2"><span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center">3</span><h2 class="text-base font-bold text-gray-900">Para el análisis con IA</h2></div>
            <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Contexto que lee la IA</label>
                <textarea name="contexto" rows="4" maxlength="3000" placeholder="Candidato o marca, propuesta, público objetivo, tono, competencia…" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('contexto', $c->contexto) }}</textarea></div>
            @if ($editando)
                <p class="text-xs text-gray-500">Los temas de la campaña se gestionan en <a href="{{ $c->perfil ? route('inteligencia.show', [$c->perfil, 'tab' => 'temas']) : '#' }}" class="text-indigo-700 underline">Inteligencia → Temas</a>.</p>
            @else
                <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">Temas a seguir (uno por línea o separados por coma)</label>
                    <textarea name="temas" rows="4" maxlength="2000" placeholder="Seguridad&#10;Empleo&#10;Salud&#10;Vías" class="w-full rounded-lg border-gray-200 text-sm shadow-sm">{{ old('temas') }}</textarea>
                    <p class="text-[11px] text-gray-400 mt-1">La IA clasifica cada publicación de la campaña en uno de estos temas.</p></div>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2">
            <a href="{{ route('campaigns.index') }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancelar</a>
            <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ $editando ? 'Guardar cambios' : 'Crear campaña' }}</button>
        </div>
    </form>
</div>
@endsection
