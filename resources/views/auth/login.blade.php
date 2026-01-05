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

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Puedes colocar ajustes extra aquí si quieres */
    </style>
</head>

<body class="antialiased bg-white text-gray-900">
    <div class="flex min-h-screen">
        <div class="hidden md:block md:w-1/2">
            <img src="https://www.triario.co/hs-fs/hubfs/blog-files/Blog%20-%2010%20herramientas%20de%20marketing/herramientas.png?width=4230&height=2467&name=herramientas.png"
                alt="Login Side Image" class="w-full h-full object-cover">
        </div>
        <!-- Login Form - Left Side -->
        <div class="w-full md:w-1/2 flex items-center justify-center px-6 py-12 bg-white">
            <div class="w-full max-w-md space-y-6">
                <!-- Logo -->
                <div class="text-center justify-center flex">
                    <img src="{{ asset('img/logo.jpg') }}" alt="">
                </div>

                <!-- Session Status -->
                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}" class="space-y-6">
                    @csrf

                    <!-- Email -->
                    <div>
                        <x-input-label for="email" :value="__('Correo Electronico')" />
                        <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus
                            autocomplete="username" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div>
                        <x-input-label for="password" :value="__('Contraseña')" />
                        <x-text-input id="password" type="password" name="password" required
                            autocomplete="current-password" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>



                    <!-- Button -->
                    <div class="space-y-4">
                        <x-primary-button class="w-full justify-center bg-blue-700 hover:bg-blue-900">
                            {{ __('Log in') }}
                        </x-primary-button>

                        <div class="flex items-center gap-3">
                            <div class="h-px bg-gray-200 flex-1"></div>
                            <span class="text-xs text-gray-500">o</span>
                            <div class="h-px bg-gray-200 flex-1"></div>
                        </div>

                        <a href="{{ route('facebook.login') }}"
                            class="w-full inline-flex items-center justify-center px-4 py-2 border rounded-md hover:bg-gray-50 gap-2">
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

                </form>
            </div>
        </div>

        <!-- Right Side: Background Image -->

    </div>
</body>

</html>
