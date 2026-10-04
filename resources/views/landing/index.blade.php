@php
    $fmt = fn($n) => '$' . number_format((int) $n, 0, ',', '.');
    $b = $planes['basico'] ?? null;
    $f = $planes['full'] ?? null;
    $suscribir = fn($plan, $periodo = 'mensual') => route('landing.suscribirse', ['plan' => $plan, 'periodo' => $periodo]);
    $icono = fn($d) => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>editus · Publica, transmite en vivo y automatiza tus redes</title>
    <meta name="description" content="editus: publica en Facebook e Instagram, transmite en vivo con varias cámaras a Facebook y YouTube, y automatiza tu WordPress con el plugin SharryStreem. Planes desde {{ $fmt($b['mensual'] ?? 0) }} COP al mes.">
    <meta property="og:title" content="editus · Estrategia profesional en medios digitales">
    <meta property="og:description" content="Publica, transmite en vivo y automatiza tus redes desde un solo lugar.">
    <meta property="og:image" content="{{ asset('img/editus-logo.png') }}">
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/jpeg">
    @include('landing._estilos')
</head>
<body>

<nav class="nav" aria-label="Principal">
    <div class="contenedor">
        <a href="#inicio" aria-label="editus, inicio"><img src="{{ asset('img/logo-editus-blanco.png') }}" alt="editus" style="height:32px;width:auto"></a>
        <div class="nav-links">
            <a href="#funciones">Funciones</a>
            <a href="#en-vivo">En vivo</a>
            <a href="#plugin">Plugin WordPress</a>
            <a href="#planes">Planes</a>
            <a href="#preguntas">Preguntas</a>
        </div>
        <div class="nav-acciones">
            <a class="nav-ingresar" href="{{ route('login') }}">Ingresar</a>
            <a class="btn btn-primario" href="#planes">Suscribirme</a>
        </div>
    </div>
</nav>

<header class="hero" id="inicio">
    <div class="contenedor hero-grid">
        <div>
            <span class="chip" style="background:rgba(255,255,255,.1);color:#dfe3ff;border:1px solid rgba(255,255,255,.18)">● Para medios digitales, periodistas y marcas</span>
            <h1 style="margin-top:18px">Publica, transmite <em>en vivo</em> y automatiza tus redes desde un solo lugar</h1>
            <p>Lleva tus noticias a Facebook e Instagram en segundos, sal en vivo con varias cámaras a Facebook y YouTube a la vez, y publica automáticamente cada entrada de tu WordPress.</p>
            <div class="hero-ctas">
                <a class="btn btn-primario" href="#planes">Ver planes</a>
                <a class="btn btn-claro" href="#funciones">Cómo funciona</a>
            </div>
            <div class="hero-datos">
                <div><strong>Desde {{ $fmt($b['mensual'] ?? 0) }}</strong>COP al mes</div>
                <div><strong>Hasta {{ $f['camaras'] ?? 6 }} cámaras</strong>en un solo en vivo</div>
                <div><strong>1 mes gratis</strong>en el plan anual</div>
            </div>
        </div>
        <div class="maqueta" aria-hidden="true">
            <div class="ventana">
                <div class="ventana-barra"><i></i><i></i><i></i><span>Estudio en vivo · editus</span></div>
                <div class="programa">
                    <span class="en-vivo">● EN VIVO</span>
                    <div class="pip"></div>
                    <div class="persona"></div>
                    <div class="rotulo"><b>Ana Pérez</b><small>Periodista · Neiva</small></div>
                </div>
                <div class="destinos">
                    <span class="chip"><b></b>Facebook · Diario Uno</span>
                    <span class="chip"><b></b>Facebook · Diario Dos</span>
                    <span class="chip"><b></b>YouTube</span>
                </div>
            </div>
            <div class="flotante espectadores"><b>1.248</b>espectadores</div>
            <div class="flotante publicado">
                <strong>Nueva nota publicada</strong>
                <div class="fila"><span class="ok">✓</span> Facebook · 2 páginas</div>
                <div class="fila"><span class="ok">✓</span> Instagram</div>
                <div class="fila"><span class="ok">✓</span> Desde tu WordPress</div>
            </div>
        </div>
    </div>
</header>

<div class="publico"><div class="contenedor">
    <span>Medios digitales</span><span>Emisoras y canales</span><span>Periodistas</span><span>Comercios y marcas</span><span>Campañas e instituciones</span>
</div></div>

<section id="funciones">
    <div class="contenedor">
        <div class="seccion-titulo">
            <div class="eyebrow">Todo en un solo lugar</div>
            <h2 style="margin-top:10px">Las herramientas de una sala de redacción digital</h2>
            <p class="lead">Conecta tus páginas una vez y trabaja desde la web de editus o desde tu sitio WordPress.</p>
        </div>
        <div class="funciones">
            <div class="funcion"><div class="icono">{!! $icono('<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>') !!}</div><h3>Publica en varias páginas</h3><p>Una sola publicación con foto, video o texto sale a la vez en tus páginas de Facebook y cuentas de Instagram.</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>') !!}</div><h3>Estudio en vivo</h3><p>Transmite desde el navegador a Facebook y YouTube al mismo tiempo, con monitor de programa y salida en un clic.</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>') !!}</div><h3>Cámaras invitadas</h3><p>Envía un enlace a un reportero o entrevistado: entra con la cámara de su celular, sin instalar nada.</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 15h18"/><path d="M7 19h6"/>') !!}</div><h3>Rótulos y plantillas</h3><p>Nombre y cargo en pantalla, título, etiqueta EN VIVO, cortinillas y, en el plan Full, tu logo y tu marco.</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>') !!}</div><h3>Autopost desde WordPress</h3><p>Con el plugin SharryStreem cada entrada que publicas sale sola a tus redes, con su imagen y su enlace.</p></div>
            <div class="funcion"><div class="icono">{!! $icono('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>') !!}</div><h3>Seguro y bajo tu control</h3><p>Usamos la conexión oficial de Facebook. Tú eliges en qué páginas publicar y puedes desconectarlas cuando quieras.</p></div>
        </div>
    </div>
</section>

<section class="franja" id="en-vivo">
    <div class="contenedor dividido">
        <div>
            <div class="eyebrow">Estudio en vivo</div>
            <h2 style="margin-top:10px">Una producción profesional, desde el navegador</h2>
            <p class="lead" style="margin-top:14px">Arma la escena antes de salir: elige la cámara principal, el diseño y los rótulos. Cuando estés listo, sales al aire en todas tus páginas y canales a la vez.</p>
            <ul class="lista">
                <li><span>Facebook y YouTube en la misma transmisión</span></li>
                <li><span>Hasta {{ $b['camaras'] ?? 2 }} cámaras en el plan Básico y {{ $f['camaras'] ?? 6 }} en el Full</span></li>
                <li><span>Diseños: una cámara, dos cámaras, imagen en imagen y cuadrícula</span></li>
                <li><span>Comparte la pantalla del computador y saca cortinillas o comerciales al aire</span></li>
                <li><span>Espectadores en vivo y enlace de cada video al terminar</span></li>
            </ul>
        </div>
        <div class="maqueta" aria-hidden="true">
            <div class="ventana">
                <div class="ventana-barra"><i></i><i></i><i></i><span>Programa · lo que ve la audiencia</span></div>
                <div class="programa" style="background:radial-gradient(circle at 70% 40%, #4a5bd6 0%, #18206a 50%, #0a0f33 100%)">
                    <span class="en-vivo">● EN VIVO · 12:48</span>
                    <div class="persona" style="left:30%"></div>
                    <div class="persona" style="left:72%;opacity:.7;background:linear-gradient(180deg,#fda4af,#e11d48)"></div>
                    <div class="rotulo"><b>Entrevista en directo</b><small>Rueda de prensa · Gobernación</small></div>
                </div>
                <div class="destinos"><span class="chip"><b></b>Diseño: dos cámaras</span><span class="chip"><b></b>2 invitados conectados</span></div>
            </div>
        </div>
    </div>
</section>

<section id="plugin">
    <div class="contenedor dividido invertido">
        <div>
            <div class="eyebrow">Plugin SharryStreem</div>
            <h2 style="margin-top:10px">Tu WordPress publica solo en tus redes</h2>
            <p class="lead" style="margin-top:14px">Instala el plugin, pega la llave de tu suscripción y listo: cada entrada que publiques sale a tus páginas de Facebook e Instagram.</p>
            <ul class="lista">
                <li><span>Imagen destacada en Facebook e Instagram; sin imagen, se publica como enlace</span></li>
                <li><span>Mensaje con tu plantilla: título, extracto, categorías y etiquetas</span></li>
                <li><span>En cada entrada eliges si se publica y en qué páginas</span></li>
                <li><span>Llave protegida y cifrada: solo funciona en tu sitio y mientras tu plan esté activo</span></li>
            </ul>
        </div>
        <div class="tarjeta-wp" aria-hidden="true">
            <div class="cab"><span style="width:22px;height:22px;border-radius:50%;background:#21759b;color:#fff;font-size:12px;display:flex;align-items:center;justify-content:center;font-weight:700">W</span> Ajustes → SharryStreem</div>
            <div class="cuerpo">
                <div class="llave">ss_k3h9x2m1q8wz_••••••••••••••••</div>
                <div class="paso-wp"><span class="n">1</span>Licencia activa · Plan Full<span class="estado">● Activa</span></div>
                <div class="paso-wp"><span class="n">2</span>Publicas «Nueva vía entre Garzón y Gigante»<span class="estado">Publicada</span></div>
                <div class="paso-wp"><span class="n">3</span>Sale a Facebook e Instagram<span class="estado">✓ 3 páginas</span></div>
            </div>
        </div>
    </div>
</section>

<section class="franja" id="planes">
    <div class="contenedor">
        <div class="seccion-titulo">
            <div class="eyebrow">Planes</div>
            <h2 style="margin-top:10px">Elige tu plan y empieza hoy</h2>
            <p class="lead">Precios en pesos colombianos. Paga mes a mes o anual, con un mes gratis.</p>
        </div>
        <div style="text-align:center">
            <div class="alternar" role="group" aria-label="Periodo de pago">
                <button type="button" class="activo" data-periodo="mensual" aria-pressed="true">Mensual</button>
                <button type="button" data-periodo="anual" aria-pressed="false">Anual <span class="ahorro">1 mes gratis</span></button>
            </div>
        </div>
        <div class="planes">
            @foreach ($planes as $clave => $p)
                <div class="plan {{ $clave === 'full' ? 'destacado' : '' }}">
                    @if ($clave === 'full')<span class="chip insignia">Más completo</span>@endif
                    <h3>{{ $p['nombre'] }}</h3>
                    <div class="para">{{ $clave === 'full' ? 'Para medios con varias marcas y producción en vivo.' : 'Para empezar a publicar y transmitir.' }}</div>
                    <div class="precio"><b data-mensual="{{ $fmt($p['mensual']) }}" data-anual="{{ $fmt($p['anual']) }}">{{ $fmt($p['mensual']) }}</b><span data-mensual="COP / mes" data-anual="COP / año">COP / mes</span></div>
                    <div class="precio-nota" data-mensual="o {{ $fmt($p['anual']) }} al año (un mes gratis)" data-anual="equivale a {{ $fmt(round($p['anual'] / 12)) }} al mes">o {{ $fmt($p['anual']) }} al año (un mes gratis)</div>
                    <ul class="lista">
                        <li><span>Publica en Facebook e Instagram en <strong>{{ $p['paginas'] }} páginas</strong></span></li>
                        <li><span>En vivo con hasta <strong>{{ $p['camaras'] }} cámaras</strong>, a Facebook y YouTube</span></li>
                        <li><span>Cámaras invitadas por enlace y rótulos</span></li>
                        <li><span>Plugin SharryStreem: autopost en {{ $p['sitios'] }} {{ $p['sitios'] == 1 ? 'sitio' : 'sitios' }} WordPress</span></li>
                        <li class="{{ $p['plantillas_logo'] ? '' : 'no' }}"><span>Plantillas con tu logo y marco en el en vivo</span></li>
                    </ul>
                    <a class="btn {{ $clave === 'full' ? 'btn-primario' : 'btn-oscuro' }}" data-plan="{{ $clave }}" href="{{ $suscribir($clave) }}">Suscribirme al {{ $p['nombre'] }}</a>
                </div>
            @endforeach
        </div>
        <p class="pagos">Pago seguro con <strong>Wompi</strong> (Bancolombia): tarjeta débito o crédito, PSE, Nequi y botón Bancolombia.</p>

        <div class="comparar">
            <table>
                <thead><tr><th>Comparación</th>@foreach ($planes as $p)<th>{{ $p['nombre'] }}</th>@endforeach</tr></thead>
                <tbody>
                    <tr><td>Precio mensual</td>@foreach ($planes as $p)<td>{{ $fmt($p['mensual']) }}</td>@endforeach</tr>
                    <tr><td>Precio anual (1 mes gratis)</td>@foreach ($planes as $p)<td>{{ $fmt($p['anual']) }}</td>@endforeach</tr>
                    <tr><td>Páginas de Facebook / Instagram</td>@foreach ($planes as $p)<td>{{ $p['paginas'] }}</td>@endforeach</tr>
                    <tr><td>Cámaras por transmisión</td>@foreach ($planes as $p)<td>{{ $p['camaras'] }}</td>@endforeach</tr>
                    <tr><td>Transmisión a Facebook y YouTube</td>@foreach ($planes as $p)<td class="si">✓</td>@endforeach</tr>
                    <tr><td>Logo y marco en el en vivo</td>@foreach ($planes as $p)<td class="{{ $p['plantillas_logo'] ? 'si' : 'no-c' }}">{{ $p['plantillas_logo'] ? '✓' : '—' }}</td>@endforeach</tr>
                    <tr><td>Sitios WordPress con SharryStreem</td>@foreach ($planes as $p)<td>{{ $p['sitios'] }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section>
    <div class="contenedor">
        <div class="seccion-titulo"><div class="eyebrow">Cómo empezar</div><h2 style="margin-top:10px">En minutos estás publicando</h2></div>
        <div class="pasos">
            <div class="paso"><h3>Elige tu plan</h3><p>Crea tu cuenta y paga con Wompi. Tu plan se activa apenas se aprueba el pago.</p></div>
            <div class="paso"><h3>Conecta tus páginas</h3><p>Inicia sesión con Facebook y elige las páginas e Instagram en las que vas a publicar.</p></div>
            <div class="paso"><h3>Publica y transmite</h3><p>Desde la web de editus o con el plugin en tu WordPress. Sal en vivo cuando quieras.</p></div>
        </div>
    </div>
</section>

<section class="franja" id="preguntas">
    <div class="contenedor">
        <div class="seccion-titulo"><div class="eyebrow">Preguntas frecuentes</div><h2 style="margin-top:10px">Lo que suelen preguntarnos</h2></div>
        <div class="faq">
            <details><summary>¿Cómo pago?</summary><p>Con Wompi, la pasarela de Bancolombia: tarjeta débito o crédito, PSE, Nequi o botón Bancolombia. El pago es mensual o anual; el anual trae un mes gratis.</p></details>
            <details><summary>¿Qué pasa cuando vence mi plan?</summary><p>Antes de la fecha de vencimiento puedes renovarlo desde «Mi suscripción». Si vence, se pausan las publicaciones, las transmisiones y el plugin; al renovar vuelven a funcionar sin perder tu configuración.</p></details>
            <details><summary>¿Puedo cambiar de plan?</summary><p>Sí. Al pasar al otro plan empieza un periodo nuevo desde ese día con los límites del plan elegido.</p></details>
            <details><summary>¿Qué páginas puedo usar?</summary><p>Las páginas de Facebook que administras y sus cuentas de Instagram profesionales vinculadas. Eliges cuáles usa tu plan: 2 en el Básico y 10 en el Full.</p></details>
            <details><summary>¿Necesito instalar algo para transmitir en vivo?</summary><p>No. El estudio funciona en el navegador del computador y las cámaras invitadas entran desde un enlace, también desde el celular.</p></details>
            <details><summary>¿Cómo funciona la llave del plugin SharryStreem?</summary><p>Al activar tu plan recibes una llave en «Mi suscripción». La pegas en Ajustes → SharryStreem de tu WordPress. Funciona solo en tus sitios vinculados y mientras tu suscripción esté activa.</p></details>
        </div>
    </div>
</section>

<section>
    <div class="contenedor">
        <div class="cta">
            <div><h2>Empieza a publicar y transmitir hoy</h2><p>Planes desde {{ $fmt($b['mensual'] ?? 0) }} COP al mes. Un mes gratis en el plan anual.</p></div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <a class="btn btn-primario" href="{{ $suscribir('full') }}">Suscribirme</a>
                @if ($whatsapp)<a class="btn btn-claro" href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('Hola, quiero información sobre los planes de editus') }}" target="_blank" rel="noopener">Hablar por WhatsApp</a>@endif
            </div>
        </div>
    </div>
</section>

<footer>
    <div class="contenedor">
        <div class="pie">
            <div>
                <img src="{{ asset('img/logo-editus-blanco.png') }}" alt="editus" style="height:30px;width:auto;margin-bottom:14px">
                <p style="margin:0;max-width:320px">Estrategia profesional en medios digitales: publicación, transmisión en vivo y automatización para tus redes.</p>
            </div>
            <div>
                <h4>Producto</h4>
                <a href="#funciones">Funciones</a><a href="#en-vivo">Estudio en vivo</a><a href="#plugin">Plugin SharryStreem</a><a href="#planes">Planes</a>
            </div>
            <div>
                <h4>Contacto</h4>
                @if ($email)<a href="mailto:{{ $email }}">{{ $email }}</a>@endif
                @if ($whatsapp)<a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener">WhatsApp</a>@endif
                <a href="https://web.facebook.com/dimediasas" target="_blank" rel="noopener">Facebook</a>
                <a href="https://www.instagram.com/dimediasas/" target="_blank" rel="noopener">Instagram</a>
                <a href="{{ route('login') }}">Ingresar a editus</a>
            </div>
        </div>
        <div class="pie-legal">
            <span>© {{ date('Y') }} Dime Media S.A.S. Todos los derechos reservados.</span>
            <span><a href="{{ route('privacy') }}">Política de privacidad</a> · <a href="{{ route('data-deletion') }}">Eliminación de datos</a> · Desarrollado por <a href="https://sharrys.com/" target="_blank" rel="noopener" style="text-decoration:underline">Sharrys Tech</a></span>
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
