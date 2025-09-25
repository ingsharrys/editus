@extends('layouts.app')

@section('content')
    <div class="max-w-3xl mx-auto px-4 py-8 mt-10"
         x-data="{
            previewUrl: null,
            showToast: {{ session('ok') ? 'true' : 'false' }},
            init() { if (this.showToast) setTimeout(() => this.showToast = false, 2500); },
            onFileChange(e) { const f = e.target.files?.[0]; this.previewUrl = f ? URL.createObjectURL(f) : null; },
            clearSelected() { this.previewUrl = null; $refs.fileInput.value = ''; }
         }">
        {{-- Toast éxito --}}
        <div class="fixed top-4 right-4 z-50" x-show="showToast" x-transition.opacity.duration.250ms>
            <div class="flex items-center gap-3 bg-green-600 text-white px-4 py-3 rounded-xl shadow-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <span class="font-medium">{{ session('ok') }}</span>
            </div>
        </div>

        <div class="flex justify-end mb-6">
            <a href="{{ route('mis-posts.index') }}"
               class="bg-white border border-gray-200 shadow-sm px-4 py-2 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                ← Volver
            </a>
        </div>

        @php
            $allowedRound   = $post->metrics_next_round;               // 1, 2 o null
            $requestedRound = (int) request('round', $allowedRound ?: 1);
            $round          = in_array($requestedRound, [1,2]) ? $requestedRound : 1;
            $metric         = $post->metricRound($round);
            $canEdit        = $post->metrics_next_round === $round;   // solo edita la ronda abierta
            $r1Complete     = $post->first_metric?->is_complete ?? false;
            $r2Complete     = $post->second_metric?->is_complete ?? false;
        @endphp

        <div class="border rounded-2xl bg-white shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-semibold">Editar {{ $round }} - Metrica</h1>

                <div class="flex items-center gap-2 text-xs text-gray-600">
                    <a href="{{ request()->url() }}?round=1"
                       class="px-3 py-1 rounded-lg border {{ $round === 1 ? 'bg-indigo-600 text-white border-indigo-600' : 'hover:bg-gray-50' }}">
                       1 Métrica {!! $r1Complete ? '✔' : '✳' !!}
                    </a>
                    <a href="{{ request()->url() }}?round=2"
                       class="px-3 py-1 rounded-lg border {{ $round === 2 ? 'bg-indigo-600 text-white border-indigo-600' : 'hover:bg-gray-50' }}">
                       2 Métrica {!! $r2Complete ? '✔' : '✳' !!}
                    </a>
                </div>
            </div>

            @if (!$canEdit)
                <div class="mb-4 text-sm text-gray-600 italic">
                    {{ $post->metrics_state_message }}
                </div>
            @endif

            <div class="flex items-center justify-between mb-4 text-xs text-gray-500">
                <span>Publicación: {{ $post->effective_at?->timezone(config('app.timezone'))?->format('d/m/Y H:i') ?? '—' }}</span>
                
            </div>

            <form method="POST" action="{{ route('mis-posts.update', $post) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="round" value="{{ $round }}"/>

                {{-- Grid 2x2 --}}
                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Alcance</label>
                        <input type="number" name="alcance" min="0" value="{{ old('alcance', $metric?->alcance) }}"
                               class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500"
                               {{ $canEdit ? '' : 'disabled' }}>
                        @error('alcance')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Visualizaciones</label>
                        <input type="number" name="visualizaciones" min="0"
                               value="{{ old('visualizaciones', $metric?->visualizaciones) }}"
                               class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500"
                               {{ $canEdit ? '' : 'disabled' }}>
                        @error('visualizaciones')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Interacciones</label>
                        <input type="number" name="interacciones" min="0"
                               value="{{ old('interacciones', $metric?->interacciones) }}"
                               class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500"
                               {{ $canEdit ? '' : 'disabled' }}>
                        @error('interacciones')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Pantallazo (imagen)</label>
                        <input x-ref="fileInput" type="file" name="evidencia" accept="image/*"
                               @change="onFileChange($event)"
                               class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500"
                               {{ $canEdit ? '' : 'disabled' }}>
                        @error('evidencia')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror

                        {{-- Preview antes de guardar --}}
                        <template x-if="previewUrl">
                            <div class="mt-3">
                                <img :src="previewUrl" alt="Preview" class="rounded-lg border max-h-48 w-auto object-contain">
                                <div class="mt-2">
                                    <button type="button" @click="clearSelected()"
                                            class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50">
                                        Quitar seleccionada
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Imagen guardada (si existe) con botón para quitar --}}
                @if ($metric?->evidencia_path)
                    <div class="pt-2 border-t">
                        <p class="text-sm text-gray-600 mb-2">Pantallazo guardado:</p>
                        <div class="flex items-start gap-4">
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($metric->evidencia_path) }}"
                                 alt="Pantallazo" class="rounded-lg border max-h-48 w-auto object-contain">
                            @if ($canEdit)
                                <button type="submit" name="remove_evidencia" value="1"
                                        class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50">
                                    Quitar actual
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="pt-2">
                    @if ($canEdit)
                        <button class="bg-indigo-600 text-white px-4 py-2 rounded-xl hover:bg-indigo-700 transition">
                            Guardar
                        </button>
                    @else
                        <button type="button" disabled
                                class="bg-gray-200 text-gray-600 px-4 py-2 rounded-xl cursor-not-allowed">
                            {{ $post->metrics_state_message }}
                        </button>
                    @endif
                </div>
            </form>
        </div>
    </div>
@endsection
