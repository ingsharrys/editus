@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
  <h1 class="text-xl font-semibold mb-4">Publicaciones enviadas</h1>

  @if($groups->isEmpty())
    <div class="text-gray-500">Aún no hay publicaciones.</div>
  @else
    <div class="space-y-3">
      @foreach($groups as $g)
        <a href="{{ route('meta.posts.show', $g->batch_uuid) }}"
           class="block rounded-xl border border-gray-200 p-4 hover:bg-gray-50">
          <div class="flex items-start justify-between">
            <div>
              <div class="text-sm text-gray-500">
                {{ strtoupper($g->type) }} · {{ \Illuminate\Support\Str::limit($g->message, 100) ?: '—' }}
              </div>
              <div class="text-xs text-gray-400">
                Enviado: {{ \Carbon\Carbon::parse($g->first_at)->format('Y-m-d H:i') }}
              </div>
            </div>
            <div class="flex items-center gap-2 text-xs">
              <span class="px-2 py-1 rounded-full bg-green-100 text-green-700">OK {{ $g->ok }}</span>
              <span class="px-2 py-1 rounded-full bg-red-100 text-red-700">Fail {{ $g->fails }}</span>
              <span class="px-2 py-1 rounded-full bg-gray-100 text-gray-700">Total {{ $g->total }}</span>
            </div>
          </div>
        </a>
      @endforeach
    </div>

    <div class="mt-4">
      {{ $groups->links() }}
    </div>
  @endif
</div>
@endsection
