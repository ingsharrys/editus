@php
    $usd = $moneda === 'USD';
    $fmt = fn($n) => $usd ? '$' . number_format((float) $n, fmod((float) $n, 1.0) == 0.0 ? 0 : 2) : '$' . number_format((int) $n, 0, ',', '.');
    $valor = fn($p, $periodo) => $usd ? ($p['usd'][$periodo] ?? 0) : ($p[$periodo] ?? 0);
    $pasarela = $usd ? 'Square' : 'Wompi';
    $activa = $suscripcion && $suscripcion->activa();
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Suscribirme') }} · editus</title>
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
        .arriba { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .idioma { display: inline-flex; padding: 2px; border-radius: 999px; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); }
        .idioma a { padding: 4px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; color: rgba(255,255,255,.8); }
        .idioma a.activo { background: #fff; color: #00024f; }
        .trampa { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        @media (max-width: 900px) { .pagina { grid-template-columns: 1fr; } .lado, .formulario { padding: 28px 20px; } .lado h1 { margin-top: 24px; font-size: 28px; } }
    </style>
</head>
<body>
<div class="pagina">
    <aside class="lado">
        <div class="arriba">
            <a href="{{ route('landing') }}" aria-label="{{ __('Volver a editus') }}"><img src="{{ asset('img/logo-editus-blanco.png') }}" alt="editus" style="height:30px;width:auto"></a>
            <span class="idioma" role="group" aria-label="{{ __('Idioma') }}">@foreach (config('region.idiomas') as $codigo => $nombre)<a href="{{ route('idioma', $codigo) }}" hreflang="{{ $codigo }}" lang="{{ $codigo }}" title="{{ $nombre }}" class="{{ app()->getLocale() === $codigo ? 'activo' : '' }}">{{ $codigo }}</a>@endforeach</span>
        </div>
        <h1>{{ __('Activa tu plan de editus') }}</h1>
        <p>{{ __('Crea tu cuenta y paga de forma segura con :pasarela. Tu plan se activa apenas se aprueba el pago.', ['pasarela' => $pasarela]) }}</p>
        <div class="resumen" id="resumen">
            @foreach ($planes as $clave => $p)
                <div data-resumen="{{ $clave }}" @if ($clave !== $plan) hidden @endif>
                    <div style="font-size:13px;color:#9aa4e6;font-weight:600;letter-spacing:.08em;text-transform:uppercase">{{ __('Plan') }}</div>
                    <div style="font-size:22px;font-weight:700">{{ __($p['nombre']) }}</div>
                    <ul>
                        <li>{{ __(':n páginas de Facebook e Instagram', ['n' => $p['paginas']]) }}</li>
                        <li>{{ __('En vivo con hasta :n cámaras', ['n' => $p['camaras']]) }}</li>
                        <li>{{ trans_choice('Plugin SharryStreem (:n sitio)|Plugin SharryStreem (:n sitios)', (int) $p['sitios'], ['n' => (int) $p['sitios']]) }}</li>
                        @if ($p['plantillas_logo'])<li>{{ __('Plantillas con logo y marco') }}</li>@endif
                    </ul>
                    <div class="total"><span data-periodo-texto>{{ $periodo === 'anual' ? __('Total por 1 año') : __('Total por 1 mes') }}</span><b data-precio data-mensual="{{ $fmt($valor($p, 'mensual')) }}" data-anual="{{ $fmt($valor($p, 'anual')) }}">{{ $fmt($valor($p, $periodo)) }}</b></div>
                    <div style="font-size:12px;color:#9aa4e6;text-align:right;margin-top:4px">{{ $usd ? __('Dólares estadounidenses (USD)') : __('Pesos colombianos (COP)') }} · <a href="{{ route('moneda', $usd ? 'COP' : 'USD') }}" style="text-decoration:underline">{{ $usd ? __('Pagar en pesos (COP)') : __('Pagar en dólares (USD)') }}</a></div>
                </div>
            @endforeach
        </div>
        <p style="margin-top:auto;padding-top:28px;font-size:13px">{{ $usd ? __('Pago con tarjeta de crédito o débito, Apple Pay o Google Pay a través de Square. editus no guarda los datos de tu tarjeta.') : __('Pago con tarjeta, PSE, Nequi o botón Bancolombia a través de Wompi. editus no guarda los datos de tu tarjeta.') }}</p>
    </aside>

    <main class="formulario">
        <div class="caja">
            <h2>{{ $user ? __('Confirma tu plan') : __('Crea tu cuenta') }}</h2>
            <p style="color:var(--suave);margin:6px 0 18px">
                @if ($user) {!! __('Estás conectado como <strong>:email</strong>.', ['email' => e($user->email)]) !!} @else {{ __('¿Ya tienes cuenta?') }} <a href="{{ route('login') }}" style="color:var(--azul);font-weight:600">{{ __('Inicia sesión') }}</a> {{ __('y continúas aquí con tu plan.') }} @endif
            </p>

            @if (session('error'))<div class="aviso error">{{ session('error') }}</div>@endif
            @if ($errors->any())<div class="aviso error">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
            @if ($sinLimites)<div class="aviso info">{{ __('Tu cuenta es del equipo interno y no necesita plan.') }} <a href="{{ route('suscripcion.index') }}" style="font-weight:600;text-decoration:underline">{{ __('Ir a Mi suscripción') }}</a></div>@endif
            @if ($activa)<div class="aviso info">{{ __('Ya tienes el plan :plan activo hasta el :fecha. Si pagas el mismo plan, se suma a esa fecha.', ['plan' => __($suscripcion->nombrePlan()), 'fecha' => $suscripcion->vence_en->format(app()->getLocale() === 'en' ? 'm/d/Y' : 'd/m/Y')]) }}</div>@endif
            @unless ($pagosListos)<div class="aviso info">{{ __('Los pagos en línea se están configurando. Si quieres activar tu plan ya, escríbenos.') }}</div>@endunless

            <form method="POST" action="{{ route('landing.suscribir') }}" novalidate>
                @csrf
                <div class="trampa" aria-hidden="true"><label>{{ __('Sitio web') }} <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>

                <label class="campo">{{ __('Plan') }}</label>
                <div class="opciones">
                    @foreach ($planes as $clave => $p)
                        <label class="opcion"><input type="radio" name="plan" value="{{ $clave }}" @checked($plan === $clave)><span>{{ __($p['nombre']) }}<small>{{ __(':precio / mes', ['precio' => $fmt($valor($p, 'mensual')) . ' ' . $moneda]) }}</small></span></label>
                    @endforeach
                </div>
                <label class="campo">{{ __('Periodo') }}</label>
                <div class="opciones">
                    <label class="opcion"><input type="radio" name="periodo" value="mensual" @checked($periodo === 'mensual')><span>{{ __('Mensual') }}<small>{{ __('Pagas mes a mes') }}</small></span></label>
                    <label class="opcion"><input type="radio" name="periodo" value="anual" @checked($periodo === 'anual')><span>{{ __('Anual') }}<small>{{ __('1 mes gratis') }}</small></span></label>
                </div>

                @guest
                    <label class="campo" for="name">{{ __('Nombre o medio') }}</label>
                    <input class="entrada" id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="organization" placeholder="{{ __('Ej: Diario del Sur') }}">
                    <label class="campo" for="email">{{ __('Correo') }}</label>
                    <input class="entrada" id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder="{{ __('tu@correo.com') }}">
                    <label class="campo" for="password">{{ __('Contraseña') }}</label>
                    <input class="entrada" id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
                    <label class="campo" for="password_confirmation">{{ __('Repite la contraseña') }}</label>
                    <input class="entrada" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    <label style="display:flex;gap:10px;align-items:flex-start;margin-top:16px;font-size:14px;color:var(--suave)">
                        <input type="checkbox" name="acepto" value="1" @checked(old('acepto')) style="margin-top:3px"> <span>{{ __('Acepto la') }} <a href="{{ route('privacy') }}" target="_blank" rel="noopener" style="color:var(--azul);text-decoration:underline">{{ __('política de privacidad') }}</a> {{ __('de editus.') }}</span>
                    </label>
                @endguest

                <button class="btn btn-primario" style="width:100%;margin-top:22px;height:52px;font-size:16px" @disabled($sinLimites)>{{ __('Continuar al pago seguro') }}</button>
                <p style="text-align:center;color:var(--suave);font-size:13px;margin-top:12px">{{ __('Te llevaremos a :pasarela para pagar. Al volver, tu plan quedará activo.', ['pasarela' => $pasarela]) }}</p>
            </form>
        </div>
    </main>
</div>
<script>
  // El resumen sigue lo que elijas (plan y periodo)
  (function () {
    const T = @js(['anual' => __('Total por 1 año'), 'mensual' => __('Total por 1 mes')]);
    const plan = () => document.querySelector('input[name=plan]:checked')?.value;
    const periodo = () => document.querySelector('input[name=periodo]:checked')?.value || 'mensual';
    const pintar = () => {
      document.querySelectorAll('[data-resumen]').forEach(el => { el.hidden = el.dataset.resumen !== plan(); });
      document.querySelectorAll('[data-precio]').forEach(el => { el.textContent = el.dataset[periodo()]; });
      document.querySelectorAll('[data-periodo-texto]').forEach(el => { el.textContent = T[periodo()] || T.mensual; });
    };
    document.querySelectorAll('input[name=plan], input[name=periodo]').forEach(i => i.addEventListener('change', pintar));
  })();
</script>
</body>
</html>
