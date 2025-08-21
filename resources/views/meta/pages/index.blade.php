<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl">Páginas de Meta</h2>
    </x-slot>

    @if (session('error'))
        <div class="bg-red-100 p-3">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="bg-green-100 p-3">{{ session('success') }}</div>
    @endif

    @php
        $hasFb = \App\Models\SocialAccount::where('user_id', auth()->id())
            ->where('provider', 'facebook')
            ->exists();
    @endphp

    <div class="mb-4 flex items-center gap-3">
        @if (!$hasFb)
            <a href="{{ route('facebook.redirect') }}" class="px-4 py-2 bg-indigo-600 text-white rounded">
                Conectar con Facebook
            </a>
            <span class="text-sm text-gray-600">Primero conecta tu Facebook, luego sincroniza.</span>
        @else
            <span class="inline-flex items-center px-3 py-1 bg-green-100 text-green-800 rounded">
                ✅ Facebook conectado
            </span>
        @endif
    </div>

    <form method="POST" action="{{ route('meta.pages.sync') }}" class="mb-4">
        @csrf
        <button class="px-4 py-2 bg-blue-600 text-white rounded">Sincronizar mis páginas</button>
    </form>

    <form method="POST" action="{{ route('meta.pages.publish') }}" class="space-y-3">
        @csrf
        <textarea name="message" class="w-full border p-2" rows="3" placeholder="Escribe el mensaje..." required></textarea>
        <input type="url" name="link" class="w-full border p-2"
            placeholder="Opcional: link a compartir (https://...)" />

        <div class="border rounded p-3">
            <p class="font-semibold mb-2">Selecciona páginas para publicar:</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach ($pages as $p)
                    <label class="flex items-center gap-2 border p-2 rounded">
                        <input type="checkbox" name="page_ids[]" value="{{ $p->id }}">
                        @if ($p->picture_url)
                            <img src="{{ $p->picture_url }}" class="w-8 h-8 rounded-full" alt="">
                        @endif
                        <span>{{ $p->name }} <small class="text-gray-500">({{ $p->page_id }})</small></span>
                    </label>
                @endforeach
            </div>
        </div>

        @auth
            @if (auth()->user()->isAdmin())
                <button class="px-4 py-2 bg-emerald-600 text-white rounded">Publicar (Admin)</button>
            @else
                <p class="text-sm text-gray-500">Solo el admin puede publicar en múltiples páginas.</p>
            @endif
        @endauth
    </form>

    <div class="mt-6">
        {{ $pages->links() }}
    </div>

    @if (session('publish_results'))
        <div class="mt-6 border p-3 rounded">
            <h3 class="font-semibold mb-2">Resultados:</h3>
            <ul class="list-disc ml-5">
                @foreach (session('publish_results') as $r)
                    <li>
                        <strong>{{ $r['page'] }}:</strong>
                        {!! $r['ok'] ? '<span class="text-green-700">OK</span>' : '<span class="text-red-700">Error</span>' !!}
                        @if (!$r['ok'])
                            <pre class="text-xs bg-gray-100 p-2 mt-1">{{ $r['error'] }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-app-layout>
