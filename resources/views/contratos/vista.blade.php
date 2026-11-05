@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-4">
        <a href="{{ route('campaigns.contratos', $c) }}#tipo-{{ $t->id }}" class="text-sm text-indigo-700 hover:underline">← {{ __('Medios y contratos') }}</a>
        <h1 class="text-2xl font-bold text-[#00024f] mt-1">{{ $t->nombre }}</h1>
        <p class="text-sm text-gray-500">{{ __('Vista previa con datos de ejemplo. Lo resaltado se llena con los datos de cada medio al firmar.') }}</p>
    </div>
    <div class="contrato-texto rounded-2xl border border-gray-200 bg-white shadow-sm p-6 sm:p-10 text-sm leading-relaxed text-gray-800">{!! $texto !!}</div>
</div>
<style>
    .contrato-texto p { margin: 0 0 .75em; text-align: justify; }
    .contrato-texto h2, .contrato-texto h3, .contrato-texto h4 { font-weight: 700; margin: 1em 0 .5em; color: #111827; }
    .contrato-texto ul { list-style: disc; padding-left: 1.4em; } .contrato-texto ol { list-style: decimal; padding-left: 1.4em; }
    .contrato-texto table { width: 100%; border-collapse: collapse; } .contrato-texto td, .contrato-texto th { border: 1px solid #d1d5db; padding: .3em .5em; }
    .contrato-texto .var { background: #eef2ff; border-radius: .25rem; padding: 0 .2em; font-weight: 600; }
</style>
@endsection
