@extends('layouts.app')

@section('content')
    <div class="max-w-3xl mx-auto px-4 py-8 mt-10" x-data="{
        previewUrl: null,
        showToast: {{ session('ok') ? 'true' : 'false' }},
        init() { if (this.showToast) setTimeout(() => this.showToast = false, 2500); },
        onFileChange(e) {
            const f = e.target.files?.[0];
            this.previewUrl = f ? URL.createObjectURL(f) : null;
        },
        clearSelected() { this.previewUrl = null;
            $refs.fileInput.value = ''; }
    }">
        {{-- Toast éxito --}}
        <div class="fixed top-4 right-4 z-50" x-show="showToast" x-transition.opacity.duration.250ms>
            <div class="flex items-center gap-3 bg-green-600 text-white px-4 py-3 rounded-xl shadow-lg">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M16.707 5.293a1 1 0 010 1.414l-7.25 7.25a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.543-6.543a1 1 0 011.414 0z"
                        clip-rule="evenodd" />
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

        <div class="border rounded-2xl bg-white shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-xl font-semibold">Editar métricas</h1>
                @if ($post->fb_permalink_url)
                    <a href="{{ $post->fb_permalink_url }}" target="_blank"
                        class="inline-flex items-center gap-2 text-indigo-600 hover:underline text-sm">
                        Ver publicación en Facebook
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h6m0 0v6m0-6L10 16" />
                        </svg>
                    </a>
                @endif
            </div>

            <form method="POST" action="{{ route('mis-posts.update', $post) }}" enctype="multipart/form-data"
                class="space-y-6">
                @csrf
                @method('PUT')

                {{-- Grid 2x2 --}}
                <div class="grid md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Alcance</label>
                        <input type="number" name="alcance" min="0" value="{{ old('alcance', $post->alcance) }}"
                            class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        @error('alcance')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Visualizaciones</label>
                        <input type="number" name="visualizaciones" min="0"
                            value="{{ old('visualizaciones', $post->visualizaciones) }}"
                            class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        @error('visualizaciones')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Interacciones</label>
                        <input type="number" name="interacciones" min="0"
                            value="{{ old('interacciones', $post->interacciones) }}"
                            class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        @error('interacciones')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Pantallazo (imagen)</label>
                        <input x-ref="fileInput" type="file" name="evidencia" accept="image/*"
                            @change="onFileChange($event)"
                            class="w-full border rounded-xl px-3 py-2 focus:ring-2 focus:ring-indigo-500">
                        @error('evidencia')
                            <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                        @enderror

                        {{-- Preview de la imagen seleccionada (antes de guardar) --}}
                        <template x-if="previewUrl">
                            <div class="mt-3">
                                <img :src="previewUrl" alt="Preview"
                                    class="rounded-lg border max-h-48 w-auto object-contain">
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

                {{-- Imagen ya guardada (si existe) con botón para quitarla del servidor --}}
                @if ($post->evidencia_path)
                    <div class="pt-2 border-t">
                        <p class="text-sm text-gray-600 mb-2">Pantallazo guardado:</p>
                        <div class="flex items-start gap-4">
                            <img src="{{ \Illuminate\Support\Facades\Storage::url($post->evidencia_path) }}"
                                alt="Pantallazo" class="rounded-lg border max-h-48 w-auto object-contain">
                            <button type="submit" name="remove_evidencia" value="1"
                                class="text-xs px-3 py-1 rounded-lg border hover:bg-gray-50">
                                Quitar actual
                            </button>
                        </div>
                    </div>
                @endif

                <div class="pt-2">
                    <button class="bg-indigo-600 text-white px-4 py-2 rounded-xl hover:bg-indigo-700 transition">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
