<x-guest-layout>
    <div class="space-y-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">{{ __('Invitación a una campaña') }}</p>
            <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $c->name }}</h1>
            @if ($c->description)<p class="text-sm text-gray-600 mt-1">{{ $c->description }}</p>@endif
        </div>

        <ol class="text-sm text-gray-700 space-y-1.5 list-decimal list-inside">
            <li>{{ __('Entra con tu cuenta de editus o crea una nueva.') }}</li>
            <li>{{ __('Llena los datos de tu medio y sube su logo.') }}</li>
            <li>{{ __('Acepta los términos y condiciones y firma en la pantalla.') }}</li>
        </ol>
        <p class="text-xs text-gray-500">{{ __('Con tu firma se genera el certificado de las publicaciones de esta campaña en tu medio; la tabla de piezas se llena sola.') }}</p>

        <div class="grid gap-2 pt-1">
            <a href="{{ route('login') }}" class="h-11 inline-flex items-center justify-center rounded-xl bg-[#00024f] text-white text-sm font-semibold hover:opacity-90">{{ __('Ya tengo cuenta: iniciar sesión') }}</a>
            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="h-11 inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white text-gray-800 text-sm font-semibold hover:bg-gray-50">{{ __('Crear mi cuenta') }}</a>
            @endif
            <a href="{{ route('facebook.login') }}" class="h-11 inline-flex items-center justify-center rounded-xl bg-[#1877f2] text-white text-sm font-semibold hover:opacity-90">{{ __('Continuar con Facebook') }}</a>
        </div>
    </div>
</x-guest-layout>
