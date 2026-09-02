<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Editus</title>
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Tailwind CDN -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body
    class="w-full h-screen bg-center bg-cover bg-no-repeat relative"
    style="background-image: url('/img/fondo-editus-bg.jpg')"
  >
    <!-- Header -->
    <header class="w-full text-sm z-20 bg-white p-4 absolute top-0 left-0">
      @if (Route::has('login'))
                <nav class="flex items-center justify-between gap-4">
                    <!-- Logo -->
                    <div class="flex justify-center items-center">
                      <img
                        src="/img/editus-logo.png"
                        alt="Editus"
                        class="w-[100px]"
                      />
                    </div>
                    <div>
                    
                    @auth
                        <a
                            href="{{ url('/dashboard') }}"
                            class="inline-block px-5 py-1.5 text-[#002EFF] hover:text-blue-700 rounded-sm text-xl leading-normal"
                        >
                            Dashboard
                        </a>
                    @else
                        <a
                            href="{{ url('/') }}"
                            class="inline-block px-5 py-1.5 text-[#002EFF] hover:text-blue-700 rounded-sm text-xl leading-normal"
                        >
                            Inicio
                        </a>
                    @endauth
                    </div>
                </nav>
            @endif
    </header>

    <!-- Contenedor principal -->
    <div class="relative z-10 flex items-center justify-center h-full px-4">
      <!-- Card -->
      <div
        class="flex max-w-5xl w-full rounded-[25px] p-10 text-white" style="background: url('../img/Rectangle-fondo.png'); background-size: cover; background-repeat: no-repeat; background-position: center;"
      >

        <div class="hidden md:block md:w-1/2 content-center justify-items-center">
            <img src="/img/logo-transparente-home.png"
                alt="Login Side Image" class="object-cover" style="width: 400px;">
        </div>
        <!-- Login Form - Left Side -->
        <div class="w-full md:w-1/2 flex items-center justify-center px-6 py-12">
            <div class="w-full max-w-md space-y-6">

                <!-- Session Status -->
                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}" class="space-y-6">
                    @csrf

                    <!-- Email -->
                    <div>
                        <x-input-label for="email" :value="__('Correo Electrónico')" class="text-white"/>
                        <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus
                            autocomplete="username" class="block mt-1 w-full p-2 rounded-[25px] text-black focus:outline-blue-700" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div>
                        <x-input-label for="password" :value="__('Contraseña')" class="text-white" />
                        <x-text-input id="password" type="password" name="password" required
                            autocomplete="current-password" class="block mt-1 w-full p-2 rounded-[25px] text-black focus:outline-blue-700" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <!-- Button -->
                    <div class="space-y-4">
                        <button class="w-full justify-center bg-blue-700 hover:bg-[#00024f] py-2 rounded-[12px] hover:border">
                            {{ __('Ingresar') }}
                        </button>

                        <a href="{{ route('facebook.login') }}"
                            class="w-full inline-flex items-center justify-center px-4 py-2 border rounded-md bg-white text-black hover:bg-[#00024f] hover:text-white gap-2">
                            <!-- Ícono de Facebook -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor"
                                class="bi bi-facebook text-blue-600" viewBox="0 0 16 16">
                                <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05
              c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275
              c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98
              h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25
              V16c3.824-.604 6.75-3.934 6.75-7.951" />
                            </svg>
                            <!-- Texto -->
                            Registrarme / Iniciar sesión con Facebook
                        </a>
                    </div>

                    <div class="flex items-center gap-3">
                        <label><input type="checkbox" id="tyc" value="1" checked required /> Acepto las <a href="https://editus.online/privacy.html" target="_blank" class="text-blue-400">Condiciones del servicio y la Política de privacidad</a></label>
                    </div>

                </form>
            </div>
        </div>

        
      </div>
    </div>
  </body>

</html>
