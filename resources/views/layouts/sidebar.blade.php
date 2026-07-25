<link rel="stylesheet" href="https://unpkg.com/boxicons@2.0.7/css/boxicons.min.css" />
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

@auth
@php $roleId = auth()->user()->role_id; @endphp

{{-- ====== MOBILE: FAB + Drawer (solo < md) ====== --}}
<div class="md:hidden" x-data="{ open:false }">
  {{-- Botón flotante inferior derecho --}}
  <button
    @click="open = true"
    class="fixed z-50
           right-4 bottom-[calc(env(safe-area-inset-bottom)+16px)]
           inline-flex items-center gap-2 px-4 py-3
           rounded-full bg-blue-600 text-white shadow-lg ring-1 ring-blue-200
           hover:bg-blue-700 active:scale-[0.98]"
    aria-label="Abrir menú">
    <i class='bx bx-menu text-2xl'></i>
    
  </button>

  {{-- Drawer / Off-canvas --}}
  <div x-show="open" x-transition.opacity class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/40" @click="open=false" aria-hidden="true"></div>

    <nav class="absolute left-0 top-0 h-full w-72 max-w-[85vw] bg-white shadow-2xl p-3 overflow-y-auto"
         x-show="open" x-transition.origin.left>
      <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
          <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-50 ring-1 ring-indigo-100">
            <i class='bx bxl-facebook text-xl text-blue-600'></i>
          </span>
          <span class="font-semibold">Navegación</span>
        </div>
        <button class="p-2 rounded hover:bg-gray-100" @click="open=false" aria-label="Cerrar menú">
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
        @endif
        <li>
          <a href="{{ route('stats.index') }}"
             class="flex items-center gap-2 px-3 py-2 rounded hover:bg-gray-100">
            <i class='bx bx-bar-chart-alt-2 text-xl text-indigo-600'></i>
            <span class="text-sm">Estadísticas</span>
          </a>
        </li>

        @if ($roleId === 2)
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

{{-- ====== DESKTOP: tu sidebar original (solo >= md) ====== --}}
<div class="min-h-screen md:flex md:flex-row bg-gray-200 mt-[50px]">
  <div class="hidden md:flex flex-col w-56 bg-white rounded-r-3xl shadow-xl overflow-hidden">
    <ul class="flex flex-col py-4">
      <li>
        <a href="{{ route('meta.pages.index') }}"
           class="flex flex-row items-center pl-2 pr-4 h-12 transform hover:translate-x-2 transition-transform ease-in duration-200  text-gray-500 hover:text-gray-800">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
               class="bi bi-facebook" color="blue" viewBox="0 0 16 16">
            <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275
                     c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157
                     1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303
                     1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604
                     6.75-3.934 6.75-7.951" />
          </svg>
          <span class="text-sm font-medium text-black ml-2">Mis Paginas</span>
        </a>
      </li>

      @if ($roleId === 1)
        <li>
          <a href="{{ route('meta.posts.index') }}"
             class="flex flex-row items-center pl-2 pr-4 h-12 transform hover:translate-x-2 transition-transform ease-in duration-200  text-gray-500 hover:text-gray-800">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                 class="bi bi-facebook" color="blue" viewBox="0 0 16 16">
              <path d="M11 8h2V6h-2z" />
              <path
                d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm8.5.5a.5.5 0 0 0-1 0v7a.5.5 0 0 0 1 0zM2 5.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5M2.5 7a.5.5 0 0 0 0 1H6a.5.5 0 0 0 0-1zM2 9.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5m8-4v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5" />
            </svg>
            <span class="text-sm font-medium text-black ml-2">Mis Publicaciones</span>
          </a>
        </li>
        <li>
          <a href="{{ route('informe.index') }}"
             class="flex flex-row items-center pl-2 pr-4 h-12 transform hover:translate-x-2 transition-transform ease-in duration-200  text-gray-500 hover:text-gray-800">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                 class="bi bi-graph-down"  color="blue" viewBox="0 0 16 16">
              <path fill-rule="evenodd"
                d="M0 0h1v15h15v1H0zm14.817 11.887a.5.5 0 0 0 .07-.704l-4.5-5.5a.5.5 0 0 0-.74-.037L7.06 8.233 3.404 3.206a.5.5 0 0 0-.808.588l4 5.5a.5.5 0 0 0 .758.06l2.609-2.61 4.15 5.073a.5.5 0 0 0 .704.07" />
            </svg>
            <span class="text-sm font-medium text-black ml-2">Informe</span>
          </a>
        </li>
      @endif
      <li>
        <a href="{{ route('stats.index') }}"
           class="flex flex-row items-center pl-2 pr-4 h-12 transform hover:translate-x-2 transition-transform ease-in duration-200  text-gray-500 hover:text-gray-800">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
               class="bi bi-bar-chart" color="blue" viewBox="0 0 16 16">
            <path d="M4 11H2v3h2zm5-4H7v7h2zm5-5v12h-2V2zm-2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM6 7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm-5 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1z" />
          </svg>
          <span class="text-sm font-medium text-black ml-2">Estadísticas</span>
        </a>
      </li>

      @if ($roleId === 2)
        <li>
          <a href="{{ route('mis-posts.index') }}"
             class="flex flex-row items-center pl-2 pr-4 h-12 transform hover:translate-x-2 transition-transform ease-in duration-200  text-gray-500 hover:text-gray-800">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
                 class="bi bi-facebook" color="blue" viewBox="0 0 16 16">
              <path d="M11 8h2V6h-2z" />
              <path
                d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm8.5.5a.5.5 0 0 0-1 0v7a.5.5 0 0 0 1 0zM2 5.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5M2.5 7a.5.5 0 0 0 0 1H6a.5.5 0 0 0 0-1zM2 9.5a.5.5 0 0 0 .5.5H6a.5.5 0 0 0 0-1H2.5a.5.5 0 0 0-.5.5m8-4v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3a.5.5 0 0 0-.5.5" />
            </svg>
            <span class="text-sm font-medium text-black ml-2">Mis Publicaciones</span>
          </a>
        </li>
      @endif
    </ul>
  </div>
</div>
@endauth
