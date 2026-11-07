<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $ok ? __('Cuenta conectada') : __('No se pudo conectar') }} · editus</title>
{{-- Página final de la conexión iniciada desde la app: muestra el resultado y devuelve al usuario a la app (deep link).
     Si falló, se queda aquí con el porqué y los botones para reintentar. --}}
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f5f7; color: #111; font-family: Inter, system-ui, -apple-system, sans-serif; padding: 24px; }
  main { max-width: 420px; width: 100%; background: #fff; border-radius: 16px; padding: 28px 22px; box-shadow: 0 10px 30px rgba(0,0,0,.08); text-align: center; }
  .icono { width: 64px; height: 64px; border-radius: 32px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; font-size: 30px; color: #fff; background: {{ $ok ? '#16a34a' : '#dc2626' }}; }
  h1 { font-size: 20px; margin: 0 0 8px; }
  p { margin: 0 0 18px; color: #475569; font-size: 15px; line-height: 1.45; white-space: pre-line; text-align: {{ $ok ? 'center' : 'left' }}; }
  a.boton { display: block; padding: 14px; border-radius: 12px; background: #111; color: #fff; text-decoration: none; font-weight: 700; font-size: 16px; margin-top: 10px; }
  a.boton.claro { background: #fff; color: #111; border: 1px solid #cbd5e1; font-weight: 600; font-size: 14px; }
  .nota { margin-top: 16px; font-size: 13px; color: #475569; line-height: 1.4; }
  .nota a { color: #1d4ed8; }
  small { display: block; margin-top: 14px; color: #94a3b8; font-size: 12px; }
</style>
</head>
<body>
<main>
  <div class="icono">{{ $ok ? '✓' : '!' }}</div>
  <h1>{{ $ok ? ($red === 'youtube' ? __('Canal de YouTube conectado') : __('Facebook conectado')) : __('No se pudo conectar') }}</h1>
  <p>{{ $mensaje }}</p>
  @if (!empty($reintentar['normal']))
    <a class="boton" href="{{ $reintentar['normal'] }}">{{ __('Volver a conectar mis páginas') }}</a>
  @endif
  <a class="boton {{ !empty($reintentar['normal']) ? 'claro' : '' }}" href="{{ $volver }}">{{ __('Volver a la app') }}</a>
  @if (!empty($reintentar['portafolio']))
    @if ($ok)
      <div class="nota">{{ __('¿Falta una página que solo manejas desde un portafolio comercial (Business Manager)?') }}
        <a id="portafolio" href="{{ $reintentar['portafolio'] }}">{{ __('Conéctala desde el portafolio') }}</a></div>
    @else
      <a id="portafolio" class="boton claro" href="{{ $reintentar['portafolio'] }}">{{ __('Conectar desde el portafolio comercial (solo si lo administras)') }}</a>
    @endif
  @endif
  <small>{{ $volverSola ? __('Si la app no se abre sola, toca el botón o cierra esta ventana.') : __('Toca «Volver a la app» cuando termines.') }}</small>
</main>
@if ($volverSola)
<script>
  // Vuelve a la app por sí sola (la app cierra el navegador al recibir el enlace), salvo que toque otro enlace
  var volver = setTimeout(function () { window.location.href = @json($volver); }, {{ (int) $espera }});
  var otro = document.getElementById('portafolio');
  if (otro) otro.addEventListener('click', function () { clearTimeout(volver); });
</script>
@endif
</body>
</html>
