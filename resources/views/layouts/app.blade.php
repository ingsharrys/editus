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

    <!-- Tailwind CDN -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

</head>

<body class="font-sans antialiased">
@auth
@php $roleId = auth()->user()->role_id; $u = auth()->user(); @endphp

        
    <!-- Wrapper -->
<div class="flex h-screen bg-gray-100">

<!-- Overlay Mobile -->
<div id="overlay"
     class="fixed inset-0 bg-black/50 z-10 hidden lg:hidden"
     onclick="toggleSidebar()"></div>

    <!-- Contenido -->
    <div class="flex-1 flex flex-col min-w-0">

        <!-- Topbar (mobile) -->
        <header class="lg:hidden bg-white shadow p-4 flex items-center justify-between">
            <button onclick="toggleSidebar()" aria-label="{{ __('Menú') }}">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div class="flex items-center gap-3"><x-idioma /><img src="/img/editus-logo-2026.png" class="w-20" alt="editus"></div>
        </header>

        <!-- Main -->
        <main class="w-full p-2 sm:p-6" style="background: #e5e7eb;">
            <!-- Logo -->
            <div class="hidden lg:flex bg-white shadow p-4 items-center justify-center fixed top-0 left-0 w-full z-30">
            
                <!-- Logo -->
                <nav class="flex items-center justify-between gap-4 mx-auto" style="width: 65rem;">
                <!-- Logo -->
                <div class="flex justify-center items-center">
                  <img
                    src="/img/editus-logo-2026.png"
                    alt="Editus"
                    class="w-[100px]"
                  />
                </div>
                <div class="flex items-center gap-3">
                    <x-idioma />
                    <p class="inline-block px-5 py-1.5 text-[#1b1b18] rounded-sm text-sm leading-normal">
                        {{ __('Gestiona | Conecta | Crece') }}
                    </p>
                </div>
            </nav>
                     
            </div>
            
            <div class="flex items-start justify-center flex-col sm:flex-row gap-4 w-full">
                <div class="mt-20">
                    <!-- Sidebar -->
                    <aside id="sidebar"
                        class="group fixed lg:static z-10
                               w-20 hover:w-64 max-h-max
                               bg-[#183EEB] text-white
                               flex flex-col
                               transition-all duration-300 ease-in-out
                               overflow-hidden
                                gap-10 rounded-xl
                               -translate-x-full lg:translate-x-0">
                    
                        <!-- TOP -->
                        <div style="margin-bottom: 4rem;">
                    
                            <!-- Menú -->
                            <ul class="mt-6 space-y-2 px-3">
                    
                                @if ($u->tienePermiso('paginas'))
                                <!-- Mis páginas -->
                                <li>
                                    <a href="{{ route('meta.pages.index') }}"
                                       class="flex items-center gap-4 rounded-xl px-4 py-3
                                              hover:bg-[#00024f] transition-all">
                    
                                        <!-- Icono -->
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-facebook" color="white" viewBox="0 0 16 16"> <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275 c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951" /> </svg>
                    
                                        <!-- Texto -->
                                        <span class="whitespace-nowrap
                                                     opacity-0 group-hover:opacity-100
                                                     hidden group-hover:block text-sm transition-all duration-300">
                                            {{ __('Mis Páginas') }}
                                        </span>
                                    </a>
                                </li>
                                @endif

                                @if ($u->tienePermiso('en_vivo'))
                                <!-- En vivo -->
                                <li>
                                    <a href="{{ route('en-vivo.web') }}"
                                       class="flex items-center gap-4 rounded-xl px-4 py-3
                                              hover:bg-[#00024f] transition-all">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                        <span class="whitespace-nowrap
                                                     opacity-0 group-hover:opacity-100
                                                     hidden group-hover:block text-sm transition-all duration-300">
                                            {{ __('En vivo') }}
                                        </span>
                                    </a>
                                </li>
                                @endif

                                <!-- Suscripción -->
                                @if ($u->tienePermiso('suscripciones_admin') || $u->tienePermiso('suscripcion'))
                                <li>
                                    <a href="{{ $u->tienePermiso('suscripciones_admin') ? route('admin.suscripciones') : route('suscripcion.index') }}"
                                       class="flex items-center gap-4 rounded-xl px-4 py-3
                                              hover:bg-[#00024f] transition-all">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                                        <span class="whitespace-nowrap
                                                     opacity-0 group-hover:opacity-100
                                                     hidden group-hover:block text-sm transition-all duration-300">
                                            {{ $u->tienePermiso('suscripciones_admin') ? 'Suscripciones' : 'Mi suscripción' }}
                                        </span>
                                    </a>
                                </li>
                                @endif
                    
                                    @if ($u->tienePermiso('publicaciones'))
                                <!-- Publicaciones -->
                                    <li>
                                        <a href="{{ route('meta.posts.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">
                    
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-facebook" color="white" viewBox="0 0 16 16"> <path d="M11 8h2V6h-2z" /> <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm8.5.5a.5.5 0 0 0-1 0v7a.5.5 0 0 0 1 0zM2 5.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5M2.5 7a.5.5 0 0 0 0 1H6a.5.5 0 0 0 0-1zM2 9.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5m8-4v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5" /> </svg>
                    
                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('Mis Publicaciones') }}
                                            </span>
                                        </a>
                                    </li>
                                @endif
                    
                                    @if ($u->tienePermiso('informe_admin'))
                                <!-- Informes -->
                                    <li>
                                        <a href="{{ route('informe.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">
                    
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-graph-down" color="white" viewBox="0 0 16 16"> <path fill-rule="evenodd" d="M0 0h1v15h15v1H0zm14.817 11.887a.5.5 0 0 0 .07-.704l-4.5-5.5a.5.5 0 0 0-.74-.037L7.06 8.233 3.404 3.206a.5.5 0 0 0-.808.588l4 5.5a.5.5 0 0 0 .758.06l2.609-2.61 4.15 5.073a.5.5 0 0 0 .704.07" /> </svg>
                    
                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('Informes') }}
                                            </span>
                                        </a>
                                    </li>
                                @endif

                                    @if ($u->tienePermiso('app_editor'))
                                <!-- App del editor -->
                                    <li>
                                        <a href="{{ route('editor-app.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">

                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-phone" color="white" viewBox="0 0 16 16"> <path d="M11 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM5 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/> <path d="M8 14a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/> </svg>

                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('App del editor') }}
                                            </span>
                                        </a>
                                    </li>
                                @endif

                                    @if ($u->tienePermiso('plantillas'))
                                <!-- Plantillas de imagen (app) -->
                                    <li>
                                        <a href="{{ route('editor-app.plantillas.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-image" color="white" viewBox="0 0 16 16"> <path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/> <path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.062L1.002 12V3a1 1 0 0 1 1-1z"/> </svg>
                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('Plantillas de imagen') }}
                                            </span>
                                        </a>
                                    </li>
                                @endif

                                    @if ($u->tienePermiso('inteligencia'))
                                <!-- Inteligencia de audiencia -->
                                    <li>
                                        <a href="{{ route('inteligencia.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">

                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-lightbulb" color="white" viewBox="0 0 16 16"> <path d="M2 6a6 6 0 1 1 10.174 4.31c-.203.196-.359.4-.453.619l-.762 1.769A.5.5 0 0 1 10.5 13a.5.5 0 0 1 0 1 .5.5 0 0 1 0 1l-.224.447a1 1 0 0 1-.894.553H6.618a1 1 0 0 1-.894-.553L5.5 15a.5.5 0 0 1 0-1 .5.5 0 0 1 0-1 .5.5 0 0 1-.46-.302l-.761-1.77a2 2 0 0 0-.453-.618A5.98 5.98 0 0 1 2 6m6-5a5 5 0 0 0-3.479 8.592c.263.254.514.564.676.941L5.83 12h4.342l.632-1.467c.162-.377.413-.687.676-.941A5 5 0 0 0 8 1"/> </svg>

                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('Inteligencia') }}
                                            </span>
                                        </a>
                                    </li>
                                @endif
                    
                                    <!-- Mis publicaciones (si no ve todas) -->
                                    @if ($u->tienePermiso('mis_publicaciones') && !$u->tienePermiso('publicaciones'))
                                    <li>
                                        <a href="{{ route('mis-posts.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">
                    
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="shrink-0" color="white" viewBox="0 0 16 16"> <path d="M11 8h2V6h-2z" /> <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm8.5.5a.5.5 0 0 0-1 0v7a.5.5 0 0 0 1 0zM2 5.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5M2.5 7a.5.5 0 0 0 0 1H6a.5.5 0 0 0 0-1zM2 9.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5m8-4v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5" /> </svg>
                    
                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('Mis Publicaciones') }}
                                            </span>
                                        </a>
                                    </li>
                                    @endif

                                    <!-- Campañas -->
                                    @if ($u->tienePermiso('campanas'))
                                    <li>
                                        <a href="{{ route('campaigns.index') }}"
                                           class="flex items-center gap-4 rounded-xl px-4 py-3
                                                  hover:bg-[#00024f] transition-all">

                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-flag-fill" color="white" viewBox="0 0 16 16"> <path d="M14.778.085A.5.5 0 0 1 15 .5V8a.5.5 0 0 1-.314.464L14.5 8l.186.464-.003.001-.006.003-.023.009a12 12 0 0 1-.397.15c-.264.095-.631.223-1.047.35-.816.252-1.879.523-2.71.523-.847 0-1.548-.28-2.158-.525l-.028-.01C7.68 8.71 7.14 8.5 6.5 8.5c-.7 0-1.638.23-2.437.477A20 20 0 0 0 3 9.342V15.5a.5.5 0 0 1-1 0V.5a.5.5 0 0 1 1 0v.282c.226-.079.496-.17.79-.26C4.606.272 5.67 0 6.5 0c.84 0 1.524.277 2.121.519l.043.018C9.286.788 9.828 1 10.5 1c.7 0 1.638-.23 2.437-.477a20 20 0 0 0 1.349-.476l.019-.007.004-.002h.001" /> </svg>

                                            <span class="whitespace-nowrap
                                                         opacity-0 group-hover:opacity-100
                                                         hidden group-hover:block text-sm transition-all duration-300">
                                                {{ __('Campañas') }}
                                            </span>
                                        </a>
                                    </li>
                                @endif

                                @if ($u->tienePermiso('estadisticas'))
                                <!-- Estadísticas -->
                                <li>
                                    <a href="{{ route('stats.index') }}"
                                       class="flex items-center gap-4 rounded-xl px-4 py-3
                                              hover:bg-[#00024f] transition-all">

                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-bar-chart-fill" color="white" viewBox="0 0 16 16"> <path d="M1 11a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm5-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm5-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1z" /> </svg>

                                        <span class="whitespace-nowrap
                                                     opacity-0 group-hover:opacity-100
                                                     hidden group-hover:block text-sm transition-all duration-300">
                                            {{ __('Estadísticas') }}
                                        </span>
                                    </a>
                                </li>
                                @endif

                                @if ($u->tienePermiso('informes'))
                                <!-- Informe de publicaciones -->
                                <li>
                                    <a href="{{ route('reports.posts') }}"
                                       class="flex items-center gap-4 rounded-xl px-4 py-3
                                              hover:bg-[#00024f] transition-all">

                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-table" color="white" viewBox="0 0 16 16"> <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm15 2h-4v3h4zm0 4h-4v3h4zm0 4h-4v3h3a1 1 0 0 0 1-1zm-5 3v-3H6v3zm-5 0v-3H1v2a1 1 0 0 0 1 1zm-4-4h4V8H1zm0-4h4V4H1zm5-3v3h4V4zm4 4H6v3h4z" /> </svg>

                                        <span class="whitespace-nowrap
                                                     opacity-0 group-hover:opacity-100
                                                     hidden group-hover:block text-sm transition-all duration-300">
                                            {{ __('Informe de publicaciones') }}
                                        </span>
                                    </a>
                                </li>
                                @endif

                                @if ($u->tienePermiso('informes'))
                                <!-- Análisis de rendimiento -->
                                <li>
                                    <a href="{{ route('reports.analytics') }}"
                                       class="flex items-center gap-4 rounded-xl px-4 py-3
                                              hover:bg-[#00024f] transition-all">

                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-graph-up-arrow" color="white" viewBox="0 0 16 16"> <path fill-rule="evenodd" d="M0 0h1v15h15v1H0zm10 3.5a.5.5 0 0 1 .5-.5h4a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-1 0V4.9l-3.613 4.417a.5.5 0 0 1-.74.037L7.06 6.767l-3.656 5.027a.5.5 0 0 1-.808-.588l4-5.5a.5.5 0 0 1 .758-.06l2.609 2.61L13.445 4H10.5a.5.5 0 0 1-.5-.5" /> </svg>

                                        <span class="whitespace-nowrap
                                                     opacity-0 group-hover:opacity-100
                                                     hidden group-hover:block text-sm transition-all duration-300">
                                            {{ __('Análisis de rendimiento') }}
                                        </span>
                                    </a>
                                </li>
                                @endif

                                @if ($u->tienePermiso('usuarios'))
                                <!-- Usuarios -->
                                <li>
                                    <a href="{{ route('usuarios.index') }}" class="flex items-center gap-4 rounded-xl px-4 py-3 hover:bg-[#00024f] transition-all">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                        <span class="whitespace-nowrap opacity-0 group-hover:opacity-100 hidden group-hover:block text-sm transition-all duration-300">{{ __('Usuarios') }}</span>
                                    </a>
                                </li>
                                @endif
                                @if ($u->tienePermiso('roles'))
                                <!-- Roles y permisos -->
                                <li>
                                    <a href="{{ route('roles.index') }}" class="flex items-center gap-4 rounded-xl px-4 py-3 hover:bg-[#00024f] transition-all">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                                        <span class="whitespace-nowrap opacity-0 group-hover:opacity-100 hidden group-hover:block text-sm transition-all duration-300">{{ __('Roles y permisos') }}</span>
                                    </a>
                                </li>
                                @endif

                            </ul>
                        </div>

                        <!-- USER -->
                        <div class="border-t border-white/10 p-3 relative">
                    
                            <!-- Trigger -->
                            <button onclick="toggleUserMenu()"
                                class="w-full flex items-center gap-3 rounded-xl p-2
                                       hover:bg-[#00024f] transition-all">
                    
                                <!-- Avatar -->
                                @if ($u->avatarUrl())
                                    <img src="{{ $u->avatarUrl() }}" alt="" class="w-11 h-11 rounded-full object-cover shrink-0 ring-2 ring-white/40">
                                @else
                                    <span class="w-11 h-11 rounded-full shrink-0 bg-white/15 ring-2 ring-white/30 grid place-items-center text-sm font-bold">{{ $u->iniciales() }}</span>
                                @endif
                    
                                <!-- Info -->
                                <div class="opacity-0 group-hover:opacity-100
                                            hidden group-hover:block
                                            text-left transition-all duration-300">
                    
                                    <p class="text-sm font-semibold whitespace-nowrap">
                                        {{ auth()->user()->name }}
                                    </p>
                    
                                    <p class="text-xs text-gray-300 whitespace-nowrap">
                                        {{ auth()->user()->role->name ?? __('Usuario') }}
                                    </p>
                                </div>
                            </button>
                    
                            <!-- Dropdown -->
                            <div id="userMenu"
                                 class="hidden absolute bottom-20 left-3
                                        w-52 rounded-2xl bg-white text-black
                                        shadow-2xl overflow-hidden border border-gray-200">
                    
                                <a href="{{ route('profile.edit') }}"
                                   class="block px-4 py-3 text-sm hover:bg-gray-100 transition">
                                    {{ __('Mi Perfil') }}
                                </a>
                    
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                    
                                    <button type="submit"
                                            class="w-full text-left px-4 py-3 text-sm hover:bg-gray-100 transition">
                                        {{ __('Cerrar Sesión') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </aside>
                </div>
                <div class="px-2 w-full min-w-0 flex-1">
                    <!-- Tu contenido -->
                    @yield('content')
                </div>
            </div>
        </main>

    </div>

</div>
@endauth
    @yield('scripts')
    
    <script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');

        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    }

    function toggleUserMenu() {
        const menu = document.getElementById('userMenu');
        menu.classList.toggle('hidden');
    }
</script>

</body>

</html>
