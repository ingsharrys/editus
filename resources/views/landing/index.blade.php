@php
    $usd = $moneda === 'USD';
    $pasarela = $pasarela ?? ($usd ? 'paypal' : 'wompi');
    $nombrePasarela = \App\Services\CobrosService::nombre($pasarela);
    // Monto sin código de moneda (el código va aparte en el precio grande)
    $fmt = fn($n) => $usd ? '$' . number_format((float) $n, fmod((float) $n, 1.0) == 0.0 ? 0 : 2) : '$' . number_format((int) $n, 0, ',', '.');
    $valor = fn($p, $periodo) => $usd ? ($p['usd'][$periodo] ?? 0) : ($p[$periodo] ?? 0);
    $b = $planes['basico'] ?? null;
    $f = $planes['full'] ?? null;
    $suscribir = fn($plan, $periodo = 'mensual') => route('landing.suscribirse', ['plan' => $plan, 'periodo' => $periodo]);
    $icono = fn($d) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    $desde = $fmt($valor($b ?? [], 'mensual')) . ' ' . $moneda;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('editus · Publica, transmite en vivo y automatiza tus redes') }}</title>
    <meta name="description" content="{{ __('editus: publica en Facebook e Instagram, transmite en vivo con varias cámaras a Facebook y YouTube, y automatiza tu WordPress con el plugin SharryStreem. Planes desde :precio al mes.', ['precio' => $desde]) }}">
    <meta property="og:title" content="{{ __('editus · Estrategia profesional en medios digitales') }}">
    <meta property="og:description" content="{{ __('Publica, transmite en vivo y automatiza tus redes desde un solo lugar.') }}">
    <meta property="og:image" content="{{ asset('img/editus-logo.png') }}">
    @foreach (array_keys(config('region.idiomas')) as $codigo)<link rel="alternate" hreflang="{{ $codigo }}" href="{{ route('idioma', $codigo) }}">@endforeach
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/jpeg">
    @include('landing._estilos')
</head>
<body>

<nav class="nav" aria-label="{{ __('Principal') }}">
    <div class="contenedor">
        <a href="#inicio" aria-label="{{ __('editus, inicio') }}"><img src="{{ asset('img/logo-editus-blanco.png') }}" alt="editus" style="height:32px;width:auto"></a>
        <div class="nav-links">
            <a href="#funciones">{{ __('Funciones') }}</a>
            <a href="#en-vivo">{{ __('En vivo') }}</a>
            <a href="#plugin">{{ __('Plugin WordPress') }}</a>
            <a href="#planes">{{ __('Planes') }}</a>
            <a href="#preguntas">{{ __('Preguntas') }}</a>
        </div>
        <div class="nav-acciones">
            <span class="idioma" role="group" aria-label="{{ __('Idioma') }}">@foreach (config('region.idiomas') as $codigo => $nombre)<a href="{{ route('idioma', $codigo) }}" hreflang="{{ $codigo }}" lang="{{ $codigo }}" title="{{ $nombre }}" class="{{ app()->getLocale() === $codigo ? 'activo' : '' }}">{{ $codigo }}</a>@endforeach</span>
            <a class="nav-ingresar" href="{{ route('login') }}">{{ __('Ingresar') }}</a>
            <a class="btn btn-primario" href="#planes">{{ __('Suscribirme') }}</a>
        </div>
    </div>
</nav>

<header class="hero" id="inicio">
    <div class="contenedor hero-grid">
        <div>
            <span class="chip" style="background:rgba(255,255,255,.1);color:#dfe3ff;border:1px solid rgba(255,255,255,.18)">● {{ __('Para medios digitales, periodistas y marcas') }}</span>
            <h1 style="margin-top:18px">{!! __('Publica, transmite <em>en vivo</em> y automatiza tus redes desde un solo lugar') !!}</h1>
            <p>{{ __('Lleva tus noticias a Facebook e Instagram en segundos, sal en vivo con varias cámaras a Facebook y YouTube a la vez, y publica automáticamente cada entrada de tu WordPress.') }}</p>
            <div class="hero-ctas">
                <a class="btn btn-primario" href="#planes">{{ __('Ver planes') }}</a>
                <a class="btn btn-claro" href="#funciones">{{ __('Cómo funciona') }}</a>
            </div>
            <div class="hero-datos">
                <div><strong>{{ __('Desde :precio', ['precio' => $fmt($valor($b ?? [], 'mensual'))]) }}</strong>{{ __(':moneda al mes', ['moneda' => $moneda]) }}</div>
                <div><strong>{{ __('Hasta :n cámaras', ['n' => $f['camaras'] ?? 6]) }}</strong>{{ __('en un solo en vivo') }}</div>
                <div><strong>{{ __('1 mes gratis') }}</strong>{{ __('en el plan anual') }}</div>
            </div>
        </div>
        <div class="maqueta" aria-hidden="true">
            <div class="ventana">
                <div class="ventana-barra"><i></i><i></i><i></i><span>{{ __('Estudio en vivo · editus') }}</span></div>
                <div class="programa">
                    <span class="en-vivo">● {{ __('EN VIVO') }}</span>
                    <div class="pip"></div>
                    <div class="persona"></div>
                    <div class="rotulo"><b>Ana Pérez</b><small>{{ __('Periodista · Neiva') }}</small></div>
                </div>
                <div class="destinos">
                    <span class="chip"><b></b>Facebook · Diario Uno</span>
                    <span class="chip"><b></b>Facebook · Diario Dos</span>
                    <span class="chip"><b></b>YouTube</span>
                </div>
            </div>
            <div class="flotante espectadores"><b>{{ app()->getLocale() === 'en' ? '1,248' : '1.248' }}</b>{{ __('espectadores') }}</div>
            <div class="flotante publicado">
                <strong>{{ __('Nueva nota publicada') }}</strong>
                <div class="fila"><span class="ok">✓</span> {{ __('Facebook · 2 páginas') }}</div>
                <div class="fila"><span class="ok">✓</span> Instagram</div>
                <div class="fila"><span class="ok">✓</span> {{ __('Desde tu WordPress') }}</div>
            </div>
        </div>
    </div>
</header>

<div class="publico"><div class="contenedor">
    <span>{{ __('Medios digitales') }}</span><span>{{ __('Emisoras y canales') }}</span><span>{{ __('Periodistas') }}</span><span>{{ __('Comercios y marcas') }}</span><span>{{ __('Campañas e instituciones') }}</span>
</div></div>

<section id="funciones">
    <div class="contenedor">
        <div class="seccion-titulo">
            <div class="eyebrow">{{ __('Todo en un solo lugar') }}</div>
            <h2 style="margin-top:10px">{{ __('Las herramientas de una sala de redacción digital') }}</h2>
            <p class="lead">{{ __('Conecta tus páginas una vez y trabaja desde la web de editus o desde tu sitio WordPress.') }}</p>
        </div>
        <div class="funciones">
            <div class="funcion"><div class="icono">{!! $icono('<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>') !!}</div><h3>{{ __('Publica en varias páginas') }}</h3><p>{{ __('Una sola publicación con foto, video o texto sale a la vez en tus páginas de Facebook y cuentas de Instagram.') }}</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>') !!}</div><h3>{{ __('Estudio en vivo') }}</h3><p>{{ __('Transmite desde el navegador a Facebook y YouTube al mismo tiempo, con monitor de programa y salida en un clic.') }}</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') !!}</div><h3>{{ __('Cámaras invitadas') }}</h3><p>{{ __('Envía un enlace a un reportero o entrevistado: entra con la cámara de su celular, sin instalar nada.') }}</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 15h18"/><path d="M7 19h6"/>') !!}</div><h3>{{ __('Rótulos y plantillas') }}</h3><p>{{ __('Nombre y cargo en pantalla, título, etiqueta EN VIVO, cortinillas y, en el plan Full, tu logo y tu marco.') }}</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>') !!}</div><h3>{{ __('Autopost desde WordPress') }}</h3><p>{{ __('Con el plugin SharryStreem cada entrada que publicas sale sola a tus redes, con su imagen y su enlace.') }}</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') !!}</div><h3>{{ __('Seguro y bajo tu control') }}</h3><p>{{ __('Usamos la conexión oficial de Facebook. Tú eliges en qué páginas publicar y puedes desconectarlas cuando quieras.') }}</p></div>
        </div>
    </div>
</section>

<section class="franja" id="en-vivo">
    <div class="contenedor dividido">
        <div>
            <div class="eyebrow">{{ __('Estudio en vivo') }}</div>
            <h2 style="margin-top:10px">{{ __('Una producción profesional, desde el navegador') }}</h2>
            <p class="lead" style="margin-top:14px">{{ __('Arma la escena antes de salir: elige la cámara principal, el diseño y los rótulos. Cuando estés listo, sales al aire en todas tus páginas y canales a la vez.') }}</p>
            <ul class="lista">
                <li><span>{{ __('Facebook y YouTube en la misma transmisión') }}</span></li>
                <li><span>{{ __('Hasta :b cámaras en el plan Básico y :f en el Full', ['b' => $b['camaras'] ?? 2, 'f' => $f['camaras'] ?? 6]) }}</span></li>
                <li><span>{{ __('Diseños: una cámara, dos cámaras, imagen en imagen y cuadrícula') }}</span></li>
                <li><span>{{ __('Comparte la pantalla del computador y saca cortinillas o comerciales al aire') }}</span></li>
                <li><span>{{ __('Espectadores en vivo y enlace de cada video al terminar') }}</span></li>
            </ul>
        </div>
        <div class="maqueta" aria-hidden="true">
            <div class="ventana">
                <div class="ventana-barra"><i></i><i></i><i></i><span>{{ __('Programa · lo que ve la audiencia') }}</span></div>
                <div class="programa" style="background:radial-gradient(circle at 70% 40%, #4a5bd6 0%, #18206a 50%, #0a0f33 100%)">
                    <span class="en-vivo">● {{ __('EN VIVO') }} · 12:48</span>
                    <div class="persona" style="left:30%"></div>
                    <div class="persona" style="left:72%;opacity:.7;background:linear-gradient(180deg,#fda4af,#e11d48)"></div>
                    <div class="rotulo"><b>{{ __('Entrevista en directo') }}</b><small>{{ __('Rueda de prensa · Gobernación') }}</small></div>
                </div>
                <div class="destinos"><span class="chip"><b></b>{{ __('Diseño: dos cámaras') }}</span><span class="chip"><b></b>{{ __('2 invitados conectados') }}</span></div>
            </div>
        </div>
    </div>
</section>

<section id="plugin">
    <div class="contenedor dividido invertido">
        <div>
            <div class="eyebrow">{{ __('Plugin SharryStreem') }}</div>
            <h2 style="margin-top:10px">{{ __('Tu WordPress publica solo en tus redes') }}</h2>
            <p class="lead" style="margin-top:14px">{{ __('Instala el plugin, pega la llave de tu suscripción y listo: cada entrada que publiques sale a tus páginas de Facebook e Instagram.') }}</p>
            <ul class="lista">
                <li><span>{{ __('Imagen destacada en Facebook e Instagram; sin imagen, se publica como enlace') }}</span></li>
                <li><span>{{ __('Mensaje con tu plantilla: título, extracto, categorías y etiquetas') }}</span></li>
                <li><span>{{ __('En cada entrada eliges si se publica y en qué páginas') }}</span></li>
                <li><span>{{ __('Llave protegida y cifrada: solo funciona en tu sitio y mientras tu plan esté activo') }}</span></li>
            </ul>
        </div>
        <div class="tarjeta-wp" aria-hidden="true">
            <div class="cab"><span style="width:22px;height:22px;border-radius:50%;background:#21759b;color:#fff;font-size:12px;display:flex;align-items:center;justify-content:center;font-weight:700">W</span> {{ __('Ajustes → SharryStreem') }}</div>
            <div class="cuerpo">
                <div class="llave">ss_k3h9x2m1q8wz_••••••••••••••••</div>
                <div class="paso-wp"><span class="n">1</span>{{ __('Licencia activa · Plan Full') }}<span class="estado">● {{ __('Activa') }}</span></div>
                <div class="paso-wp"><span class="n">2</span>{{ __('Publicas «Nueva vía entre Garzón y Gigante»') }}<span class="estado">{{ __('Publicada') }}</span></div>
                <div class="paso-wp"><span class="n">3</span>{{ __('Sale a Facebook e Instagram') }}<span class="estado">✓ {{ __('3 páginas') }}</span></div>
            </div>
        </div>
    </div>
</section>

<section class="franja" id="planes">
    <div class="contenedor">
        <div class="seccion-titulo">
            <div class="eyebrow">{{ __('Planes') }}</div>
            <h2 style="margin-top:10px">{{ __('Elige tu plan y empieza hoy') }}</h2>
            <p class="lead">{{ $usd ? __('Precios en dólares estadounidenses (USD). Paga mes a mes o anual, con un mes gratis.') : __('Precios en pesos colombianos. Paga mes a mes o anual, con un mes gratis.') }}</p>
            <p class="moneda">
                @if ($usd){{ __('¿Estás en Colombia?') }} <a href="{{ route('moneda', 'COP') }}">{{ __('Ver precios en pesos (COP)') }}</a>
                @else{{ __('¿Estás fuera de Colombia?') }} <a href="{{ route('moneda', 'USD') }}">{{ __('Ver precios en dólares (USD)') }}</a>@endif
            </p>
        </div>
        <div style="text-align:center">
            <div class="alternar" role="group" aria-label="{{ __('Periodo de pago') }}">
                <button type="button" class="activo" data-periodo="mensual" aria-pressed="true">{{ __('Mensual') }}</button>
                <button type="button" data-periodo="anual" aria-pressed="false">{{ __('Anual') }} <span class="ahorro">{{ __('1 mes gratis') }}</span></button>
            </div>
        </div>
        <div class="planes">
            @foreach ($planes as $clave => $p)
                @php $mes = $valor($p, 'mensual'); $anio = $valor($p, 'anual'); @endphp
                <div class="plan {{ $clave === 'full' ? 'destacado' : '' }}">
                    @if ($clave === 'full')<span class="chip insignia">{{ __('Más completo') }}</span>@endif
                    <h3>{{ __($p['nombre']) }}</h3>
                    <div class="para">{{ $clave === 'full' ? __('Para medios con varias marcas y producción en vivo.') : __('Para empezar a publicar y transmitir.') }}</div>
                    <div class="precio"><b data-mensual="{{ $fmt($mes) }}" data-anual="{{ $fmt($anio) }}">{{ $fmt($mes) }}</b><span data-mensual="{{ __(':moneda / mes', ['moneda' => $moneda]) }}" data-anual="{{ __(':moneda / año', ['moneda' => $moneda]) }}">{{ __(':moneda / mes', ['moneda' => $moneda]) }}</span></div>
                    <div class="precio-nota" data-mensual="{{ __('o :precio al año (un mes gratis)', ['precio' => $fmt($anio)]) }}" data-anual="{{ __('equivale a :precio al mes', ['precio' => $fmt($usd ? round($anio / 12, 2) : round($anio / 12))]) }}">{{ __('o :precio al año (un mes gratis)', ['precio' => $fmt($anio)]) }}</div>
                    <ul class="lista">
                        <li><span>{!! __('Publica en Facebook e Instagram en <strong>:n páginas</strong>', ['n' => (int) $p['paginas']]) !!}</span></li>
                        <li><span>{!! __('En vivo con hasta <strong>:n cámaras</strong>, a Facebook y YouTube', ['n' => (int) $p['camaras']]) !!}</span></li>
                        <li><span>{{ __('Cámaras invitadas por enlace y rótulos') }}</span></li>
                        <li><span>{{ trans_choice('Plugin SharryStreem: autopost en :n sitio WordPress|Plugin SharryStreem: autopost en :n sitios WordPress', (int) $p['sitios'], ['n' => (int) $p['sitios']]) }}</span></li>
                        <li class="{{ $p['plantillas_logo'] ? '' : 'no' }}"><span>{{ __('Plantillas con tu logo y marco en el en vivo') }}</span></li>
                    </ul>
                    <a class="btn {{ $clave === 'full' ? 'btn-primario' : 'btn-oscuro' }}" data-plan="{{ $clave }}" href="{{ $suscribir($clave) }}">{{ __('Suscribirme al :plan', ['plan' => __($p['nombre'])]) }}</a>
                </div>
            @endforeach
        </div>
        <p class="pagos">{!! match ($pasarela) { 'paypal' => __('Pago seguro con <strong>PayPal</strong>: con tu cuenta PayPal o con tarjeta de crédito o débito.'), 'square' => __('Pago seguro con <strong>Square</strong>: tarjeta de crédito o débito, Apple Pay y Google Pay.'), default => __('Pago seguro con <strong>Wompi</strong> (Bancolombia): tarjeta débito o crédito, PSE, Nequi y botón Bancolombia.') } !!}</p>

        <div class="comparar">
            <table>
                <thead><tr><th>{{ __('Comparación') }}</th>@foreach ($planes as $p)<th>{{ __($p['nombre']) }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td>{{ __('Precio mensual') }}</td>@foreach ($planes as $p)<td>{{ $fmt($valor($p, 'mensual')) }} {{ $moneda }}</td>@endforeach</tr>
                    <tr><td>{{ __('Precio anual (1 mes gratis)') }}</td>@foreach ($planes as $p)<td>{{ $fmt($valor($p, 'anual')) }} {{ $moneda }}</td>@endforeach</tr>
                    <tr><td>{{ __('Páginas de Facebook / Instagram') }}</td>@foreach ($planes as $p)<td>{{ $p['paginas'] }}</td>@endforeach</tr>
                    <tr><td>{{ __('Cámaras por transmisión') }}</td>@foreach ($planes as $p)<td>{{ $p['camaras'] }}</td>@endforeach</tr>
                    <tr><td>{{ __('Transmisión a Facebook y YouTube') }}</td>@foreach ($planes as $p)<td class="si">✓</td>@endforeach</tr>
                    <tr><td>{{ __('Logo y marco en el en vivo') }}</td>@foreach ($planes as $p)<td class="{{ $p['plantillas_logo'] ? 'si' : 'no-c' }}">{{ $p['plantillas_logo'] ? '✓' : '—' }}</td>@endforeach</tr>
                    <tr><td>{{ __('Sitios WordPress con SharryStreem') }}</td>@foreach ($planes as $p)<td>{{ $p['sitios'] }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section>
    <div class="contenedor">
        <div class="seccion-titulo"><div class="eyebrow">{{ __('Cómo empezar') }}</div><h2 style="margin-top:10px">{{ __('En minutos estás publicando') }}</h2></div>
        <div class="pasos">
            <div class="paso"><h3>{{ __('Elige tu plan') }}</h3><p>{{ __('Crea tu cuenta y paga con :pasarela. Tu plan se activa apenas se aprueba el pago.', ['pasarela' => $nombrePasarela]) }}</p></div>
            <div class="paso"><h3>{{ __('Conecta tus páginas') }}</h3><p>{{ __('Inicia sesión con Facebook y elige las páginas e Instagram en las que vas a publicar.') }}</p></div>
            <div class="paso"><h3>{{ __('Publica y transmite') }}</h3><p>{{ __('Desde la web de editus o con el plugin en tu WordPress. Sal en vivo cuando quieras.') }}</p></div>
        </div>
    </div>
</section>

<section class="franja" id="preguntas">
    <div class="contenedor">
        <div class="seccion-titulo"><div class="eyebrow">{{ __('Preguntas frecuentes') }}</div><h2 style="margin-top:10px">{{ __('Lo que suelen preguntarnos') }}</h2></div>
        <div class="faq">
            <details><summary>{{ __('¿Cómo pago?') }}</summary><p>{{ match ($pasarela) { 'paypal' => __('Fuera de Colombia pagas en dólares con PayPal: con tu cuenta PayPal o con tarjeta de crédito o débito. El pago es mensual o anual; el anual trae un mes gratis.'), 'square' => __('Fuera de Colombia pagas en dólares con Square: tarjeta de crédito o débito, Apple Pay o Google Pay. El pago es mensual o anual; el anual trae un mes gratis.'), default => __('Con Wompi, la pasarela de Bancolombia: tarjeta débito o crédito, PSE, Nequi o botón Bancolombia. El pago es mensual o anual; el anual trae un mes gratis.') } }}</p></details>
            <details><summary>{{ __('¿Qué pasa cuando vence mi plan?') }}</summary><p>{{ __('Antes de la fecha de vencimiento puedes renovarlo desde «Mi suscripción». Si vence, se pausan las publicaciones, las transmisiones y el plugin; al renovar vuelven a funcionar sin perder tu configuración.') }}</p></details>
            <details><summary>{{ __('¿Puedo cambiar de plan?') }}</summary><p>{{ __('Sí. Al pasar al otro plan empieza un periodo nuevo desde ese día con los límites del plan elegido.') }}</p></details>
            <details><summary>{{ __('¿Qué páginas puedo usar?') }}</summary><p>{{ __('Las páginas de Facebook que administras y sus cuentas de Instagram profesionales vinculadas. Eliges cuáles usa tu plan: :b en el Básico y :f en el Full.', ['b' => $b['paginas'] ?? 2, 'f' => $f['paginas'] ?? 10]) }}</p></details>
            <details><summary>{{ __('¿Necesito instalar algo para transmitir en vivo?') }}</summary><p>{{ __('No. El estudio funciona en el navegador del computador y las cámaras invitadas entran desde un enlace, también desde el celular.') }}</p></details>
            <details><summary>{{ __('¿Cómo funciona la llave del plugin SharryStreem?') }}</summary><p>{{ __('Al activar tu plan recibes una llave en «Mi suscripción». La pegas en Ajustes → SharryStreem de tu WordPress. Funciona solo en tus sitios vinculados y mientras tu suscripción esté activa.') }}</p></details>
        </div>
    </div>
</section>

<section>
    <div class="contenedor">
        <div class="cta">
            <div><h2>{{ __('Empieza a publicar y transmitir hoy') }}</h2><p>{{ __('Planes desde :precio al mes. Un mes gratis en el plan anual.', ['precio' => $desde]) }}</p></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <a class="btn btn-primario" href="{{ $suscribir('full') }}">{{ __('Suscribirme') }}</a>
                @if ($whatsapp)<a class="btn btn-claro" href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode(__('Hola, quiero información sobre los planes de editus')) }}" target="_blank" rel="noopener">{{ __('Hablar por WhatsApp') }}</a>@endif
            </div>
        </div>
    </div>
</section>

<footer>
    <div class="contenedor">
        <div class="pie">
            <div>
                <img src="{{ asset('img/logo-editus-blanco.png') }}" alt="editus" style="height:30px;width:auto;margin-bottom:14px">
                <p style="margin:0;max-width:320px">{{ __('Estrategia profesional en medios digitales: publicación, transmisión en vivo y automatización para tus redes.') }}</p>
            </div>
            <div>
                <h4>{{ __('Producto') }}</h4>
                <a href="#funciones">{{ __('Funciones') }}</a><a href="#en-vivo">{{ __('Estudio en vivo') }}</a><a href="#plugin">{{ __('Plugin SharryStreem') }}</a><a href="#planes">{{ __('Planes') }}</a>
            </div>
            <div>
                <h4>{{ __('Contacto') }}</h4>
                @if ($email)<a href="mailto:{{ $email }}">{{ $email }}</a>@endif
                @if ($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">WhatsApp</a>@endif
                <a href="https://web.facebook.com/dimediasas" target="_blank" rel="noopener">Facebook</a>
                <a href="https://www.instagram.com/dimediasas/" target="_blank" rel="noopener">Instagram</a>
                <a href="{{ route('login') }}">{{ __('Ingresar a editus') }}</a>
            </div>
        </div>
        <div class="pie-legal">
            <span>{{ __('© :anio Dime Media S.A.S. Todos los derechos reservados.', ['anio' => date('Y')]) }}</span>
            <span><a href="{{ route('privacy') }}">{{ __('Política de privacidad') }}</a> · <a href="{{ route('data-deletion') }}">{{ __('Eliminación de datos') }}</a> · {{ __('Desarrollado por') }} <a href="https://sharrys.com/" target="_blank" rel="noopener" style="text-decoration:underline">Sharrys Tech</a></span>
        </div>
    </div>
</footer>

<script>
  // Alternar mensual / anual: cambia precios y el periodo de los botones de suscripción
  (function () {
    const botones = document.querySelectorAll('[data-periodo]');
    const aplicar = (periodo) => {
      botones.forEach(b => { const on = b.dataset.periodo === periodo; b.classList.toggle('activo', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      document.querySelectorAll('[data-mensual]').forEach(el => { el.textContent = el.dataset[periodo]; });
      document.querySelectorAll('a[data-plan]').forEach(a => { const u = new URL(a.href); u.searchParams.set('periodo', periodo); a.href = u.toString(); });
    };
    botones.forEach(b => b.addEventListener('click', () => aplicar(b.dataset.periodo)));
  })();
</script>
</body>
</html>
