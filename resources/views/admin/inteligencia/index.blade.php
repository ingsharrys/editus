@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8 mt-10">
        <div class="text-[#00024f]">
            <h1 class="text-3xl font-bold">Inteligencia de audiencia</h1>
            <p class="mt-1 text-sm">
                Qué temas conectan, con qué público, en qué red y a qué hora, y qué dice la gente. Análisis <strong>agregado por segmento</strong>
                (ciudad, edad, género, tema, horario): nunca por personas.
            </p>
        </div>
    </div>

    @if (session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-4">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-4">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 text-red-800 p-3 mb-4"><ul class="list-disc ml-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    @unless ($iaLista)
        <div class="rounded-lg border border-amber-200 bg-amber-50 text-amber-900 p-3 mb-6 text-sm">
            La IA no está configurada: falta <code>ANTHROPIC_API_KEY</code> en el <code>.env</code>. Se recolectan datos y estadísticas, pero no se clasifican temas, ni se leen comentarios, ni se redactan informes.
        </div>
    @endunless

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4">
            <h2 class="text-xl font-bold text-gray-800">Campañas</h2>
            @forelse ($campanas as $c)
                <a href="{{ route('inteligencia.show', $c) }}" class="block rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-gray-900">{{ $c->nombre }}</h3>
                                @if (!$c->activa)<span class="text-xs rounded-full bg-gray-100 text-gray-600 px-2 py-0.5">pausada</span>@endif
                            </div>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ $c->territorio ?: 'Sin territorio' }} · {{ $c->paginas->count() }} página(s) · {{ $c->temas_count }} tema(s)
                                @if ($c->desde) · {{ $c->desde->format('d/m/Y') }}{{ $c->hasta ? ' a ' . $c->hasta->format('d/m/Y') : '' }}@endif
                            </p>
                        </div>
                        <div class="flex -space-x-2">
                            @foreach ($c->paginas->take(5) as $p)
                                <img src="{{ $p->picture_url ?: $p->pictureUrl('small') }}" alt="{{ $p->name }}" title="{{ $p->name }}" class="w-8 h-8 rounded-full border-2 border-white bg-gray-200">
                            @endforeach
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-gray-500">
                    Aún no hay campañas. Crea la primera con el formulario de la derecha.
                </div>
            @endforelse
            <p class="text-xs text-gray-400">Publicaciones recolectadas en total: {{ number_format($totalPublicaciones) }}</p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-gray-800 mb-3">Nueva campaña</h2>
            <form method="POST" action="{{ route('inteligencia.store') }}" class="space-y-3 text-sm">
                @csrf
                <div><label class="block text-gray-600 mb-1">Nombre</label><input name="nombre" required maxlength="120" class="w-full rounded-lg border-gray-300" value="{{ old('nombre') }}" placeholder="Ej: Campaña Alcaldía 2027"></div>
                <div><label class="block text-gray-600 mb-1">Territorio</label><input name="territorio" maxlength="120" class="w-full rounded-lg border-gray-300" value="{{ old('territorio') }}" placeholder="Ej: Neiva y norte del Huila"></div>
                <div><label class="block text-gray-600 mb-1">Contexto (lo lee la IA)</label><textarea name="descripcion" rows="3" class="w-full rounded-lg border-gray-300" placeholder="Candidato, propuesta, público objetivo, tono…">{{ old('descripcion') }}</textarea></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="block text-gray-600 mb-1">Desde</label><input type="date" name="desde" class="w-full rounded-lg border-gray-300" value="{{ old('desde') }}"></div>
                    <div><label class="block text-gray-600 mb-1">Hasta</label><input type="date" name="hasta" class="w-full rounded-lg border-gray-300" value="{{ old('hasta') }}"></div>
                </div>
                <div>
                    <label class="block text-gray-600 mb-1">Páginas</label>
                    <div class="max-h-44 overflow-y-auto rounded-lg border border-gray-200 p-2 space-y-1">
                        @forelse ($paginas as $p)
                            <label class="flex items-center gap-2"><input type="checkbox" name="paginas[]" value="{{ $p->id }}" class="rounded"> <span>{{ $p->name }}</span>@if ($p->instagram_business_account_id)<span class="text-xs text-pink-600">+IG</span>@endif</label>
                        @empty
                            <span class="text-gray-400">Conecta páginas en Mis Páginas.</span>
                        @endforelse
                    </div>
                </div>
                <div><label class="block text-gray-600 mb-1">Temas iniciales (uno por línea o separados por coma)</label><textarea name="temas" rows="4" class="w-full rounded-lg border-gray-300" placeholder="Seguridad&#10;Empleo&#10;Salud&#10;Vías&#10;Candidato">{{ old('temas') }}</textarea></div>
                <button class="w-full rounded-lg bg-[#00024f] text-white font-semibold py-2.5 hover:opacity-90">Crear campaña</button>
            </form>
        </div>
    </div>
</div>
@endsection
