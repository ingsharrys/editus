@extends('layouts.app')

@section('content')
@php $inp = 'w-full h-9 rounded-lg border-gray-200 text-xs'; $conProd = ($productos ?? collect())->isNotEmpty(); $conSubs = !$conProd && $subs->isNotEmpty(); @endphp
<div class="max-w-6xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    @if ($c->plan_medios)
        @include('campaigns.plan._nav')
    @else
        <div class="mt-8 lg:mt-10 mb-5">
            <a href="{{ route('campaigns.edit', $c) }}#inscripcion" class="text-sm text-indigo-700 hover:underline">← {{ $c->name }}</a>
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ __('Piezas de la campaña') }}</h1>
        </div>
        @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @endif

    <p class="text-sm text-gray-600 mb-4">{{ $conProd ? __('Asigna a cada publicación la pieza que se publicó. El tipo, el programa y el subprograma salen de la pieza; con esto se llenan el informe de métricas, los cupos y el certificado de cada medio.') : ($conSubs
        ? __('Asigna a cada publicación su subcampaña y su tipo de pieza. Con esto se llenan las métricas por subcampaña, los cupos y el certificado de cada medio.')
        : __('Clasifica cada publicación con su programa, subprograma y tipo de pieza. Con esto se llena la tabla del certificado de cada medio.')) }}</p>

    <form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
        <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Página') }}</label>
            <select name="pagina" class="h-9 rounded-lg border-gray-200 text-sm" onchange="this.form.submit()">
                <option value="">{{ __('Todas') }}</option>
                @foreach ($paginas as $pg)<option value="{{ $pg->id }}" @selected((string) request('pagina') === (string) $pg->id)>{{ $pg->name }}</option>@endforeach
            </select></div>
        @if ($conSubs || $conProd)
            <div><label class="block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1">{{ __('Subcampaña') }}</label>
                <select name="sub" class="h-9 rounded-lg border-gray-200 text-sm" onchange="this.form.submit()">
                    <option value="">{{ __('Todas') }}</option>
                    <option value="0" @selected(request('sub') === '0')>{{ __('Sin subcampaña') }}</option>
                    @foreach ($subs as $s)<option value="{{ $s->id }}" @selected((string) request('sub') === (string) $s->id)>{{ $s->nombre }}</option>@endforeach
                </select></div>
        @endif
        <label class="flex items-center gap-2 h-9 text-sm text-gray-700"><input type="checkbox" name="sin" value="1" @checked(request('sin')) onchange="this.form.submit()" class="h-4 w-4 rounded border-gray-300 text-indigo-600"> {{ __('Solo sin clasificar') }}</label>
    </form>

    <datalist id="dlTipos">@foreach ($tipos as $t)<option value="{{ $t }}">@endforeach</datalist>

    <form method="POST" action="{{ route('campaigns.piezas.guardar', $c) }}">
        @csrf
        <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left">{{ __('Publicación') }}</th>
                        @if ($conProd)
                            <th class="px-3 py-2 text-left w-80">{{ __('Pieza') }}</th>
                        @elseif ($conSubs)
                            <th class="px-3 py-2 text-left w-64">{{ __('Subcampaña') }}</th>
                        @else
                            <th class="px-3 py-2 text-left w-64">{{ __('Programa') }}</th><th class="px-3 py-2 text-left w-48">{{ __('Subprograma') }}</th>
                        @endif
                        @unless ($conProd)<th class="px-3 py-2 text-left w-44">{{ __('Tipo de pieza') }}</th>@endunless
                    </tr>
                </thead>
                <tbody>
                    @forelse ($posts as $post)
                        <tr class="border-t border-gray-100 align-top">
                            <td class="px-3 py-2">
                                <div class="text-xs font-semibold text-gray-900">{{ $post->page?->name ?? '—' }} <span class="font-normal text-gray-400">· {{ $post->network === 'instagram' ? 'Instagram' : 'Facebook' }} · {{ $post->published_at?->format('d/m/Y') }}</span></div>
                                <div class="text-xs text-gray-600 line-clamp-2 max-w-md">{{ \Illuminate\Support\Str::limit((string) $post->message, 140) ?: '—' }}</div>
                                @if ($post->fb_permalink_url)<a href="{{ $post->fb_permalink_url }}" target="_blank" rel="noopener" class="text-[11px] text-indigo-700 hover:underline">{{ __('Ver publicación') }}</a>@endif
                            </td>
                            @if ($conProd)
                                <td class="px-3 py-2">
                                    <select name="piezas[{{ $post->id }}][producto_id]" class="{{ $inp }}">
                                        <option value="">{{ __('— Sin pieza —') }}</option>
                                        @foreach ($productos->groupBy(fn($x) => $x->subcampana?->nombre ?? __('Sin subcampaña')) as $grupo => $lista)
                                            <optgroup label="{{ $grupo }}">@foreach ($lista as $pr)<option value="{{ $pr->id }}" @selected((int) $post->producto_id === (int) $pr->id)>{{ $pr->codigo() }} · {{ \Illuminate\Support\Str::limit($pr->nombre, 60) }}</option>@endforeach</optgroup>
                                        @endforeach
                                    </select>
                                    @if ($post->tipo_pieza)<div class="text-[10px] text-gray-400 mt-0.5">{{ $post->tipo_pieza }}{{ $post->programa ? ' · ' . $post->programa : '' }}</div>@endif
                                </td>
                            @elseif ($conSubs)
                                <td class="px-3 py-2">
                                    <select name="piezas[{{ $post->id }}][subcampana_id]" class="{{ $inp }}">
                                        <option value="">{{ __('— Sin subcampaña —') }}</option>
                                        @foreach ($subs as $s)<option value="{{ $s->id }}" @selected((int) $post->subcampana_id === (int) $s->id)>{{ $s->nombre }}</option>@endforeach
                                    </select>
                                    @if (!$post->subcampana_id && $post->programa)<div class="text-[10px] text-gray-400 mt-0.5">{{ $post->programa }}{{ $post->subprograma ? ' · ' . $post->subprograma : '' }}</div>@endif
                                </td>
                            @else
                                <td class="px-3 py-2"><input name="piezas[{{ $post->id }}][programa]" maxlength="190" value="{{ $post->programa }}" class="{{ $inp }}"></td>
                                <td class="px-3 py-2"><input name="piezas[{{ $post->id }}][subprograma]" maxlength="190" value="{{ $post->subprograma }}" class="{{ $inp }}"></td>
                            @endif
                            @unless ($conProd)<td class="px-3 py-2"><input name="piezas[{{ $post->id }}][tipo_pieza]" list="dlTipos" maxlength="60" value="{{ $post->tipo_pieza }}" placeholder="{{ $cert->tipoPieza($post) }}" class="{{ $inp }}"></td>@endunless
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
