@props(['oscuro' => false])
{{-- Selector de idioma ES | EN --}}
@php $actual = app()->getLocale(); @endphp
<div {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full p-0.5 text-[11px] font-bold ' . ($oscuro ? 'bg-white/10 ring-1 ring-white/20' : 'bg-gray-100 ring-1 ring-gray-200')]) }} role="group" aria-label="{{ __('Idioma') }}">
    @foreach (config('region.idiomas') as $codigo => $nombre)
        <a href="{{ route('idioma', $codigo) }}" hreflang="{{ $codigo }}" title="{{ $nombre }}" lang="{{ $codigo }}"
           @class(['rounded-full px-2.5 py-1 uppercase transition',
                   $oscuro ? ($actual === $codigo ? 'bg-white text-[#00024f]' : 'text-white/80 hover:text-white') : ($actual === $codigo ? 'bg-white text-[#00024f] shadow-sm' : 'text-gray-500 hover:text-gray-800')])
           @if ($actual === $codigo) aria-current="true" @endif>{{ $codigo }}</a>
    @endforeach
</div>
