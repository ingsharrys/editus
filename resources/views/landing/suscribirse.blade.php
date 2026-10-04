@php
    $fmt = fn($n) => '$' . number_format((int) $n, 0, ',', '.');
    $activa = $suscripcion && $suscripcion->activa();
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suscribirme · editus</title>
    <meta name="robots" content="noindex">
    <link rel="icon" href="{{ asset('img/logo.jpg') }}" type="image/jpeg">
    @include('landing._estilos')
    <style>
        .pagina { min-height: 100vh; display: grid; grid-template-columns: 1fr 1.1fr; }
        .lado { background: linear-gradient(180deg, var(--navy), var(--navy-2)); color: #fff; padding: 40px 48px; display: flex; flex-direction: column; }
        .lado h1 { font-size: 34px; margin: 40px 0 12px; }
        .lado p { color: #c7cdf7; }
        .resumen { margin-top: 28px; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14); border-radius: 18px; padding: 22px; }
        .resumen .total { display: flex; justify-content: space-between; align-items: baseline; border-top: 1px solid rgba(255,255,255,.14); margin-top: 16px; padding-top: 16px; }
        .resumen .total b { font-size: 30px; }
        .resumen ul { list-style: none; padding: 0; margin: 14px 0 0; display: grid; gap: 8px; color: #dfe3ff; font-size: 14px; }
        .resumen li::before { content: '✓  '; color: #6ee7b7; font-weight: 700; }
        .formulario { padding: 40px 48px; display: flex; align-items: center; justify-content: center; background: var(--fondo); }
        .caja { width: 100%; max-width: 460px; }
        .caja h2 { font-size: 26px; }
        .opciones { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 8px 0 18px; }
        .opcion { position: relative; }
        .opcion input { position: absolute; opacity: 0; }
        .opcion span { display: block; border: 1.5px solid var(--borde); background: #fff; border-radius: 12px; padding: 12px 14px; cursor: pointer; font-weight: 600; color: var(--navy); }
        .opcion span small { display: block; font-weight: 500; color: var(--suave); font-size: 12px; margin-top: 2px; }
        .opcion input:checked + span { border-color: var(--azul); box-shadow: 0 0 0 3px rgba(27,59,255,.12); }
        .opcion input:focus-visible + span { outline: 3px solid #8ea2ff; outline-offset: 2px; }
        label.campo { display: block; font-size: 13px; font-weight: 600; color: var(--navy); margin: 14px 0 6px; }
        input.entrada { width: 100%; height: 46px; border: 1px solid var(--borde); border-radius: 10px; padding: 0 14px; font: inherit; font-size: 15px; background: #fff; }
        input.entrada:focus { border-color: var(--azul); outline: none; box-shadow: 0 0 0 3px rgba(27,59,255,.12); }
        .aviso { border-radius: 12px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; }
        .aviso.error { background: #fff1f2; color: #9f1239; border: 1px solid #fecdd3; }
        .aviso.info { background: var(--azul-claro); color: var(--navy); border: 1px solid #c7d2fe; }
        .trampa { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        @media (max-width: 900px) { .pagina { grid-template-columns: 1fr; } .lado, .formulario { padding: 28px 20px; } .lado h1 { margin-top: 24px; font-size: 28px; } }
    </style>
</head>
<body>
<div class="pagina">
    <aside class="lado">
        <a href="{{ route('landing') }}" aria-label="Volver a editus"><img src="{{ asset('img/logo-editus-blanco.png') }}" alt="editus" style="height:30px;width:auto"></a>
        <h1>Activa tu plan de editus</h1>
        <p>Crea tu cuenta y paga de forma segura con Wompi. Tu plan se activa apenas se aprueba el pago.</p>
        <div class="resumen" id="resumen">
            @foreach ($planes as $clave => $p)
                <div data-resumen="{{ $clave }}" @if ($clave !== $plan) hidden @endif>
                    <div style="font-size:13px;color:#9aa4e6;font-weight:600;letter-spacing:.08em;text-transform:uppercase">Plan</div>
                    <div style="font-size:22px;font-weight:700">{{ $p['nombre'] }}</div>
                    <ul>
                        <li>{{ $p['paginas'] }} páginas de Facebook e Instagram</li>
                        <li>En vivo con hasta {{ $p['camaras'] }} cámaras</li>
                        <li>Plugin SharryStreem ({{ $p['sitios'] }} {{ $p['sitios'] == 1 ? 'sitio' : 'sitios' }})</li>
                        @if ($p['plantillas_logo'])<li>Plantillas con logo y marco</li>@endif
                    </ul>
                    <div class="total"><span data-periodo-texto>{{ $periodo === 'anual' ? 'Total por 1 año' : 'Total por 1 mes' }}</span><b data-precio data-mensual="{{ $fmt($p['mensual']) }}" data-anual="{{ $fmt($p['anual']) }}">{{ $fmt($p[$periodo]) }}</b></div>
                    <div style="font-size:12px;color:#9aa4e6;text-align:right;margin-top:4px">Pesos colombianos (COP)</div>
                </div>
            @endforeach
        </div>
        <p style="margin-top:auto;padding-top:28px;font-size:13px">Pago con tarjeta, PSE, Nequi o botón Bancolombia a través de Wompi. editus no guarda los datos de tu tarjeta.</p>
    </aside>

    <main class="formulario">
        <div class="caja">
            <h2>{{ $user ? 'Confirma tu plan' : 'Crea tu cuenta' }}</h2>
            <p style="color:var(--suave);margin:6px 0 18px">
                @if ($user) Estás conectado como <strong>{{ $user->email }}</strong>. @else ¿Ya tienes cuenta? <a href="{{ route('login') }}" style="color:var(--azul);font-weight:600">Inicia sesión</a> y continúas aquí con tu plan. @endif
            </p>

            @if (session('error'))<div class="aviso error">{{ session('error') }}</div>@endif
            @if ($errors->any())<div class="aviso error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
            @if ($sinLimites)<div class="aviso info">Tu cuenta es del equipo interno y no necesita plan. <a href="{{ route('suscripcion.index') }}" style="font-weight:600;text-decoration:underline">Ir a Mi suscripción</a></div>@endif
            @if ($activa)<div class="aviso info">Ya tienes el plan {{ $suscripcion->nombrePlan() }} activo hasta el {{ $suscripcion->vence_en->format('d/m/Y') }}. Si pagas el mismo plan, se suma a esa fecha.</div>@endif
            @unless ($wompiListo)<div class="aviso info">Los pagos en línea se están configurando. Si quieres activar tu plan ya, escríbenos.</div>@endunless

            <form method="POST" action="{{ route('landing.suscribir') }}" novalidate>
                @csrf
                <div class="trampa" aria-hidden="true"><label>Sitio web <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>

                <label class="campo">Plan</label>
                <div class="opciones">
                    @foreach ($planes as $clave => $p)
                        <label class="opcion"><input type="radio" name="plan" value="{{ $clave }}" @checked($plan === $clave)><span>{{ $p['nombre'] }}<small>{{ $fmt($p['mensual']) }} / mes</small></span></label>
                    @endforeach
                </div>
                <label class="campo">Periodo</label>
                <div class="opciones">
                    <label class="opcion"><input type="radio" name="periodo" value="mensual" @checked($periodo === 'mensual')><span>Mensual<small>Pagas mes a mes</small></span></label>
                    <label class="opcion"><input type="radio" name="periodo" value="anual" @checked($periodo === 'anual')><span>Anual<small>1 mes gratis</small></span></label>
                </div>

                @guest
                    <label class="campo" for="name">Nombre o medio</label>
                    <input class="entrada" id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="organization" placeholder="Ej: Diario del Sur">
                    <label class="campo" for="email">Correo</label>
                    <input class="entrada" id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="tu@correo.com">
                    <label class="campo" for="password">Contraseña</label>
                    <input class="entrada" id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
                    <label class="campo" for="password_confirmation">Repite la contraseña</label>
                    <input class="entrada" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    <label style="display:flex;gap:10px;align-items:flex-start;margin-top:16px;font-size:14px;color:var(--suave)">
                        <input type="checkbox" name="acepto" value="1" @checked(old('acepto')) style="margin-top:3px"> <span>Acepto la <a href="{{ route('privacy') }}" target="_blank" rel="noopener" style="color:var(--azul);text-decoration:underline">política de privacidad</a> de editus.</span>
                    </label>
                @endguest

                <button class="btn btn-primario" style="width:100%;margin-top:22px;height:52px;font-size:16px" @disabled($sinLimites)>Continuar al pago seguro</button>
                <p style="text-align:center;color:var(--suave);font-size:13px;margin-top:12px">Te llevaremos a Wompi para pagar. Al volver, tu plan quedará activo.</p>
            </form>
        </div>
    </main>
</div>
<script>
  // El resumen sigue lo que elijas (plan y periodo)
  (function () {
    const plan = () => document.querySelector('input[name=plan]:checked')?.value;
    const periodo = () => document.querySelector('input[name=periodo]:checked')?.value || 'mensual';
    const pintar = () => {
      document.querySelectorAll('[data-resumen]').forEach(el => { el.hidden = el.dataset.resumen !== plan(); });
      document.querySelectorAll('[data-precio]').forEach(el => { el.textContent = el.dataset[periodo()]; });
      document.querySelectorAll('[data-periodo-texto]').forEach(el => { el.textContent = periodo() === 'anual' ? 'Total por 1 año' : 'Total por 1 mes'; });
    };
    document.querySelectorAll('input[name=plan], input[name=periodo]').forEach(i => i.addEventListener('change', pintar));
  })();
</script>
</body>
</html>
