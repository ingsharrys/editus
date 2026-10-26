@extends('layouts.app')

@section('content')
@php $inp = 'w-full h-9 rounded-lg border-gray-200 text-xs'; @endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-5">
        <a href="{{ route('campaigns.edit', $c) }}#inscripcion" class="text-sm text-indigo-700 hover:underline">← {{ $c->name }}</a>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ __('Piezas de la campaña') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('Clasifica cada publicación con su programa, subprograma y tipo de pieza. Con esto se llena la tabla del certificado de cada medio.') }}</p>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Página') }}</label>
            <select name="pagina" class="h-9 rounded-lg border-gray-200 text-sm" onchange="this.form.submit()">
                <option value="">{{ __('Todas') }}</option>
                @foreach ($paginas as $pg)<option value="{{ $pg->id }}" @selected((string) request('pagina') === (string) $pg->id)>{{ $pg->name }}</option>@endforeach
            </select></div>
        <label class="flex items-center gap-2 h-9 text-sm text-gray-700"><input type="checkbox" name="sin" value="1" @checked(request('sin')) onchange="this.form.submit()" class="h-4 w-4 rounded border-gray-300 text-indigo-600"> {{ __('Solo sin programa') }}</label>
    </form>

    <datalist id="dlProgramas">@foreach ($programas as $pr)<option value="{{ $pr['programa'] }}">@endforeach</datalist>
    <datalist id="dlSubprogramas">@foreach (collect($programas)->pluck('subprogramas')->flatten()->unique() as $sp)<option value="{{ $sp }}">@endforeach</datalist>
    <datalist id="dlTipos">@foreach ($tipos as $t)<option value="{{ $t }}">@endforeach</datalist>

    <form method="POST" action="{{ route('campaigns.piezas.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr><th class="px-3 py-2 text-left">{{ __('Publicación') }}</th><th class="px-3 py-2 text-left w-64">{{ __('Programa') }}</th><th class="px-3 py-2 text-left w-48">{{ __('Subprograma') }}</th><th class="px-3 py-2 text-left w-40">{{ __('Tipo de pieza') }}</th></tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        <tr class="border-t border-gray-100 align-top">
                            <td class="px-3 py-2">
                                <div class="text-xs font-semibold text-gray-900">{{ $post->page?->name ?? '—' }} <span class="font-normal text-gray-400">· {{ $post->network === 'instagram' ? 'Instagram' : 'Facebook' }} · {{ $post->published_at?->format('d/m/Y') }}</span></div>
                                <div class="text-xs text-gray-600 line-clamp-2 max-w-md">{{ \Illuminate\Support\Str::limit((string) $post->message, 140) ?: '—' }}</div>
                                @if ($post->fb_permalink_url)<a href="{{ $post->fb_permalink_url }}" target="_blank" rel="noopener" class="text-[11px] text-indigo-700 hover:underline">{{ __('Ver publicación') }}</a>@endif
                            </td>
                            <td class="px-3 py-2"><input name="piezas[{{ $post->id }}][programa]" list="dlProgramas" maxlength="190" value="{{ $post->programa }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2"><input name="piezas[{{ $post->id }}][subprograma]" list="dlSubprogramas" maxlength="190" value="{{ $post->subprograma }}" class="{{ $inp }}"></td>
                            <td class="px-3 py-2"><input name="piezas[{{ $post->id }}][tipo_pieza]" list="dlTipos" maxlength="60" value="{{ $post->tipo_pieza }}" placeholder="{{ $cert->tipoPieza($post) }}" class="{{ $inp }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-sm text-gray-500">{{ __('No hay publicaciones exitosas en esta campaña.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <div>{{ $posts->links() }}</div>
            @if ($posts->count())
                <button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Guardar clasificación') }}</button>
            @endif
        </div>
    </form>
</div>
@endsection
