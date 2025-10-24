<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Editus') }}</title>
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/png">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

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
    @auth
        @php $roleId = auth()->user()->role_id; @endphp

        {{-- ===== MOBILE: FAB + Drawer (visible solo en < lg) ===== --}}
        <div class="lg:hidden" x-data="{ open: false }" x-cloak>
            {{-- FAB inferior derecha --}}
            <button @click="open = true"
                class="fixed z-[100] right-4 bottom-[calc(env(safe-area-inset-bottom)+16px)]
           inline-flex items-center gap-2 px-4 py-3 rounded-full
           bg-blue-600 text-white shadow-lg ring-1 ring-blue-200
           hover:bg-blue-700 active:scale-[0.98]"
                aria-label="Abrir menú">
                <i class='bx bx-menu text-2xl'></i>
            </button>

            {{-- Overlay + Drawer --}}
            <div x-show="open" x-transition.opacity class="fixed inset-0 z-[100]">
                <div class="absolute inset-0 bg-black/40" @click="open=false" aria-hidden="true"></div>

                <nav class="absolute left-0 top-0 h-full w-72 max-w-[85vw]
                bg-white shadow-2xl p-3 overflow-y-auto"
                    x-show="open" x-transition.origin.left>
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-50 ring-1 ring-indigo-100">
                                <i class='bx bxl-facebook text-xl text-blue-600'></i>
                            </span>
                            <span class="font-semibold">Navegación</span>
                        </div>
                        <button class="p-2 rounded hover:bg-gray-100" @click="open=false" aria-label="Cerrar">
                            <i class='bx bx-x text-2xl'></i>
                        </button>
                    </div>

                    <ul class="mt-2 space-y-1">
                        <li>
                            <a href="{{ route('meta.pages.index') }}"
                                class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                                <i class='bx bxl-facebook-square text-xl text-blue-600'></i>
                                <span class="text-sm">Mis Paginas</span>
                            </a>
                        </li>

                        @if ($roleId === 1)
                            <li>
                                <a href="{{ route('meta.posts.index') }}"
                                    class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                                    <i class='bx bx-spreadsheet text-xl'></i>
                                    <span class="text-sm">Mis Publicaciones</span>
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('informe.index') }}"
                                    class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                                    <i class='bx bx-line-chart-down text-xl'></i>
                                    <span class="text-sm">Informe</span>
                                </a>
                            </li>
                        @elseif ($roleId === 2)
                            <li>
                                <a href="{{ route('mis-posts.index') }}"
                                    class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
                                    <i class='bx bx-notepad text-xl'></i>
                                    <span class="text-sm">Mis Publicaciones</span>
                                </a>
                            </li>
                        @endif
                    </ul>
                </nav>
            </div>
        </div>
    @endauth
    @yield('scripts')

</body>

</html>
