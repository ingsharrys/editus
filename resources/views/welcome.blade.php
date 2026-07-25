<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Editus | Estrategía profesional en medios digitales</title>
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/png">

    <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
  </head>

  <body>
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
                            href="{{ route('login') }}"
                            class="inline-block px-5 py-1.5 text-[#002EFF] hover:text-blue-700 rounded-sm text-xl leading-normal"
                        >
                            Iniciar Sesión
                        </a>
                    @endauth
                    </div>
                </nav>
            @endif
    </header>

    <!-- Contenedor principal -->
    <div class="z-10 flex items-center justify-center h-full px-4 w-full h-screen bg-center bg-cover bg-no-repeat relative" style="background-image: url('/img/fondo-editus-bg.jpg')">
      <!-- Card -->
      <div
        class="max-w-5xl w-full rounded-[25px] px-2 py-10 sm:p-10 text-white grid grid-cols-1 sm:grid-cols-2 gap-4" style="background: url('../img/Rectangle-fondo.png'); background-size: cover; background-repeat: no-repeat; background-position: center;"
      >
        <!-- Logo -->
        <div class="mb-2 sm:mb-6 py-1 my-1 sm:py-10 sm:my-10">
            <video width="100%" class="rounded-xl" controls autoplay muted loop>
              <source src="/img/video-editus.mp4" type="video/mp4">
              Your browser does not support HTML video.
            </video>
        </div>

        <!-- Contenido -->
        <div class="text-sm sm:text-lg leading-relaxed space-y-4 py-5 my-1 sm:py-10 sm:my-10">
          <p class="text-sm sm:text-2xl font-bold my-0 leading-none">
            Estrategia profesional en </br>
          <span class="text-2xl sm:text-5xl font-bold">
            medios digitales
          </span>
          </p>
          <p>
            Plataforma digital que permite gestionar, planificar y difundir campañas publicitarias de manera eficiente. Centraliza la administración de contenidos, la programación de publicaciones y el seguimiento de estrategias, optimizando la comunicación entre marcas, medios y audiencias
          </p>
        </div>

      </div>
    </div>
    
    <footer class="bg-white mt-10">

        <!-- Contenido principal -->
        <div class="max-w-7xl mx-auto px-6 py-8">
    
            <div class="flex flex-col md:flex-row justify-between gap-8">
    
                <!-- Redes sociales -->
                <div>
                    <h3 class="text-xl sm:text-2xl font-bold text-[#0B1560] mb-3">
                        Síguenos
                    </h3>
    
                    <div class="flex items-center gap-3">
    
                        <!-- Facebook -->
                        <a href="https://web.facebook.com/dimediasas" class="hover:scale-110 transition">
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-2 h-2 sm:w-5 sm:h-5 fill-current"
                            viewBox="0 0 24 24"
                          >
                            <path
                              d="M22 12A10 10 0 1 0 10.5 21.9v-7H8v-2.9h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.4h-1.2c-1.2 0-1.6.7-1.6 1.5v1.8H17l-.4 2.9h-2.3v7A10 10 0 0 0 22 12z"
                            />
                          </svg>
                        </a>
            
                        <!-- Instagram -->
                        <a href="https://www.instagram.com/dimediasas/" class="hover:scale-110 transition">
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-2 h-2 sm:w-5 sm:h-5 fill-current"
                            viewBox="0 0 24 24"
                          >
                            <path
                              d="M7 2C4.2 2 2 4.2 2 7v10c0 2.8 2.2 5 5 5h10c2.8 0 5-2.2 5-5V7c0-2.8-2.2-5-5-5H7zm5 5.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 7.4A2.9 2.9 0 1 0 9.1 12 2.9 2.9 0 0 0 12 14.9zm4.8-7.6a1 1 0 1 1-1-1 1 1 0 0 1 1 1z"
                            />
                          </svg>
                        </a>
            
                        <!-- TikTok -->
                        <a href="https://www.tiktok.com/@dimemediasas" class="hover:scale-110 transition">
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-2 h-2 sm:w-5 sm:h-5 fill-current"
                            viewBox="0 0 24 24"
                          >
                            <path
                              d="M16 2c.3 1.8 1.6 3.1 3.4 3.4v3.1c-1.3 0-2.6-.4-3.7-1.1v6.6a5 5 0 1 1-5-5c.3 0 .6 0 .9.1v3.2c-.3-.1-.6-.2-.9-.2a2 2 0 1 0 2 2V2h3.3z"
                            />
                          </svg>
                        </a>
    
                    </div>
                </div>
    
                <!-- Contacto -->
                <div class="text-left md:text-right">
    
                    <h3 class="text-xl sm:text-2xl font-extrabold text-[#0B1560] mb-3 uppercase">
                        Contáctanos
                    </h3>
    
                    <div class="space-y-2 text-[#0B1560] font-semibold">
    
                        <div class="flex md:justify-end items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="w-2 h-2 sm:w-5 sm:h-5 fill-current"><path d="M476.9 161.1C435 119.1 379.2 96 319.9 96C197.5 96 97.9 195.6 97.9 318C97.9 357.1 108.1 395.3 127.5 429L96 544L213.7 513.1C246.1 530.8 282.6 540.1 319.8 540.1L319.9 540.1C442.2 540.1 544 440.5 544 318.1C544 258.8 518.8 203.1 476.9 161.1zM319.9 502.7C286.7 502.7 254.2 493.8 225.9 477L219.2 473L149.4 491.3L168 423.2L163.6 416.2C145.1 386.8 135.4 352.9 135.4 318C135.4 216.3 218.2 133.5 320 133.5C369.3 133.5 415.6 152.7 450.4 187.6C485.2 222.5 506.6 268.8 506.5 318.1C506.5 419.9 421.6 502.7 319.9 502.7zM421.1 364.5C415.6 361.7 388.3 348.3 383.2 346.5C378.1 344.6 374.4 343.7 370.7 349.3C367 354.9 356.4 367.3 353.1 371.1C349.9 374.8 346.6 375.3 341.1 372.5C308.5 356.2 287.1 343.4 265.6 306.5C259.9 296.7 271.3 297.4 281.9 276.2C283.7 272.5 282.8 269.3 281.4 266.5C280 263.7 268.9 236.4 264.3 225.3C259.8 214.5 255.2 216 251.8 215.8C248.6 215.6 244.9 215.6 241.2 215.6C237.5 215.6 231.5 217 226.4 222.5C221.3 228.1 207 241.5 207 268.8C207 296.1 226.9 322.5 229.6 326.2C232.4 329.9 268.7 385.9 324.4 410C359.6 425.2 373.4 426.5 391 423.9C401.7 422.3 423.8 410.5 428.4 397.5C433 384.5 433 373.4 431.6 371.1C430.3 368.6 426.6 367.2 421.1 364.5z"/></svg>
                            <span>313 8071986</span>
                        </div>
    
                        <div class="flex md:justify-end items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="w-2 h-2 sm:w-5 sm:h-5 fill-current"><path d="M112 128C85.5 128 64 149.5 64 176C64 191.1 71.1 205.3 83.2 214.4L291.2 370.4C308.3 383.2 331.7 383.2 348.8 370.4L556.8 214.4C568.9 205.3 576 191.1 576 176C576 149.5 554.5 128 528 128L112 128zM64 260L64 448C64 483.3 92.7 512 128 512L512 512C547.3 512 576 483.3 576 448L576 260L377.6 408.8C343.5 434.4 296.5 434.4 262.4 408.8L64 260z"/></svg>
                            <span>comercial@dimedia.com.co</span>
                        </div>
    
                        <div class="flex md:justify-end items-center gap-2">
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" class="w-2 h-2 sm:w-5 sm:h-5 fill-current"><path d="M128 252.6C128 148.4 214 64 320 64C426 64 512 148.4 512 252.6C512 371.9 391.8 514.9 341.6 569.4C329.8 582.2 310.1 582.2 298.3 569.4C248.1 514.9 127.9 371.9 127.9 252.6zM320 320C355.3 320 384 291.3 384 256C384 220.7 355.3 192 320 192C284.7 192 256 220.7 256 256C256 291.3 284.7 320 320 320z"/></svg>
                            <span>Neiva, Huila, Colombia</span>
                        </div>
    
                    </div>
    
                </div>
    
            </div>
    
            <!-- Línea separadora -->
            <div class="border-t border-[#0B1560]/30 mt-8 pt-5">
    
                <div class="flex flex-col md:flex-row justify-between items-center gap-3">
    
                    <p class="text-[#002EFF] text-sm">
                        © 2026 Dime Media S.A.S. Todos los derechos reservados.
                    </p>
    
                    <p class="text-[#002EFF] text-sm">
                        Desarrollado por
                        <a href="https://sharrys.com/"
                            class="underline hover:text-[#0B1560] font-medium">
                            Sharrys Tech
                        </a>
                    </p>
    
                </div>
    
            </div>
    
        </div>
    
    </footer>

  </body>
</html>
