<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Editus') }}</title>
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased">
    <div class="flex min-h-screen bg-blue-400">
        <!-- Sidebar -->
        <div class="hidden lg:flex lg:w-56 lg:fixed lg:h-full lg:bg-white lg:shadow-lg">
            @include('layouts.sidebar')
        </div>

        <div class="flex-1 flex flex-col lg:ml-56">
            @include('layouts.navigation')

            @if (isset($header))
                <header class="bg-white">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <main class="flex-1 p-6">
                @yield('content')
            </main>
        </div>
    </div>
    @yield('scripts')

</body>

</html>
