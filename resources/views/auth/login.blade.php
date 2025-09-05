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
            <img src="https://www.triario.co/hs-fs/hubfs/blog-files/Blog%20-%2010%20herramientas%20de%20marketing/herramientas.png?width=4230&height=2467&name=herramientas.png" alt="Login Side Image" class="w-full h-full object-cover">
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
                        <x-text-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" class="block mt-1 w-full"/>
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div>
                        <x-input-label for="password" :value="__('Contraseña')" />
                        <x-text-input id="password" type="password" name="password" required autocomplete="current-password" class="block mt-1 w-full"/>
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    

                    <!-- Button -->
                    <div>
                        <x-primary-button class="w-full justify-center bg-blue-700 hover:bg-blue-900">
                            {{ __('Log in') }}
                        </x-primary-button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Side: Background Image -->
       
    </div>
</body>
</html>
