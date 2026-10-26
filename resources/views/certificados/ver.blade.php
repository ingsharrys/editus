@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            @if ($esAdmin)
                <a href="{{ route('campaigns.edit', $campana) }}#inscripcion" class="text-sm text-indigo-700 hover:underline">← {{ $campana->name }}</a>
            @endif
            <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ __('Certificado de publicación') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $participante->medio_nombre }} · {{ $campana->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ((int) $participante->user_id === (int) auth()->id())
                <a href="{{ route('certificados.formulario', $campana) }}" class="h-10 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Editar mis datos o firma') }}</a>
            @endif
            @if ($participante->firmado())
                <a href="{{ route('certificados.pdf', $participante) }}" class="h-10 inline-flex items-center rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Descargar PDF') }}</a>
            @endif
        </div>
    </div>

    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @unless ($participante->firmado())
        <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{{ __('Este medio todavía no ha firmado el certificado.') }}</div>
    @endunless

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm px-6 sm:px-14 py-10 sm:py-14">
        @include('certificados._hoja')
    </div>

    @if ($participante->firmado())
        <p class="mt-3 text-[11px] text-gray-400">
            {{ __('Firmado el :fecha · términos aceptados el :terminos · IP :ip', ['fecha' => $participante->firmado_at->format('d/m/Y H:i'), 'terminos' => $participante->acepto_terminos_at?->format('d/m/Y H:i') ?? '—', 'ip' => $participante->ip ?: '—']) }}
        </p>
    @endif
</div>
@endsection
