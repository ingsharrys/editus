@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6 mt-10">

  <div class="mb-5 flex items-center justify-between">
    <h1 class="text-2xl font-semibold text-white/90">Publicaciones enviadas</h1>
    {{-- espacio para filtros futuros --}}
  </div>

  @if($groups->isEmpty())
    <div class="rounded-2xl bg-white/95 shadow-sm border border-white/30 p-8 text-center">
      <p class="text-gray-500">Aún no hay publicaciones.</p>
    </div>
  @else

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-5">
      @foreach($groups as $g)
        @php
          $typeBadge = match($g->type) {
            'text'  => 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-100',
            'photo' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
            'video' => 'bg-violet-50 text-violet-700 ring-1 ring-violet-100',
            default => 'bg-gray-50 text-gray-700 ring-1 ring-gray-100',
          };
        @endphp

        <a href="{{ route('meta.posts.show', $g->batch_uuid) }}"
           class="group block rounded-2xl bg-white/95 border border-white/30 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200">
          <div class="p-4">
            <div class="flex items-start justify-between gap-3">
              <span class="px-2 py-1 rounded-full text-[11px] font-medium {{ $typeBadge }}">
                {{ strtoupper($g->type) }}
              </span>
              <svg class="h-4 w-4 text-gray-300 group-hover:text-gray-400 transition"
                   viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 18l6-6-6-6"/>
              </svg>
            </div>

            <div class="mt-3">
              <div class="text-sm text-gray-900 line-clamp-2">
                {{ \Illuminate\Support\Str::limit($g->message ?? '—', 120) }}
              </div>
              <div class="mt-1 text-xs text-gray-500">
                Enviado: {{ \Carbon\Carbon::parse($g->first_at)->format('Y-m-d H:i') }}
              </div>
            </div>

            <div class="mt-4 flex items-center gap-2 text-[11px]">
              <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">OK {{ $g->ok }}</span>
              <span class="px-2 py-1 rounded-full bg-red-100 text-red-700">Fail {{ $g->fails }}</span>
              <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700">Total {{ $g->total }}</span>
            </div>
          </div>
        </a>
      @endforeach
    </div>

    <div class="mt-6">
      {{ $groups->links() }}
    </div>
  @endif

</div>
@endsection
