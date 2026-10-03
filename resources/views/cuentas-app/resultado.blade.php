<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $ok ? 'Cuenta conectada' : 'No se pudo conectar' }} · editus</title>
{{-- Página final de la conexión iniciada desde la app: muestra el resultado y devuelve al usuario a la app (deep link). --}}
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f5f7; color: #111; font-family: Inter, system-ui, -apple-system, sans-serif; padding: 24px; }
  main { max-width: 420px; width: 100%; background: #fff; border-radius: 16px; padding: 28px 22px; box-shadow: 0 10px 30px rgba(0,0,0,.08); text-align: center; }
  .icono { width: 64px; height: 64px; border-radius: 32px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; font-size: 30px; color: #fff; background: {{ $ok ? '#16a34a' : '#dc2626' }}; }
  h1 { font-size: 20px; margin: 0 0 8px; }
  p { margin: 0 0 18px; color: #475569; font-size: 15px; line-height: 1.45; white-space: pre-line; }
  a.boton { display: block; padding: 14px; border-radius: 12px; background: #111; color: #fff; text-decoration: none; font-weight: 700; font-size: 16px; }
  small { display: block; margin-top: 14px; color: #94a3b8; font-size: 12px; }
</style>
</head>
<body>
<main>
  <div class="icono">{{ $ok ? '✓' : '!' }}</div>
  <h1>{{ $ok ? ($red === 'youtube' ? 'Canal de YouTube conectado' : 'Facebook conectado') : 'No se pudo conectar' }}</h1>
  <p>{{ $mensaje }}</p>
  <a class="boton" href="{{ $volver }}">Volver a la app</a>
  <small>Si la app no se abre sola, toca el botón o cierra esta ventana.</small>
</main>
<script>
  // Vuelve a la app por sí sola (la app cierra el navegador al recibir el enlace)
  setTimeout(function () { window.location.href = @json($volver); }, 700);
</script>
</body>
</html>
