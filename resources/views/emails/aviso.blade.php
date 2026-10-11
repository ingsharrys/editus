{{-- Correo de aviso de editus (en vivos y pregrabados programados). Estilos en línea para los clientes de correo. --}}
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $titulo }}</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb">
      <tr><td style="background:#00024f;padding:18px 24px;color:#ffffff;font-size:18px;font-weight:bold;letter-spacing:.5px">editus</td></tr>
      <tr><td style="padding:24px">
        <p style="margin:0 0 6px;font-size:13px;color:#6b7280">Hola {{ $nombre }}:</p>
        <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#00024f">{{ $titulo }}</h1>
        <p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#374151">{{ $cuerpo }}</p>
        @foreach ($lineas as $l)
          <p style="margin:0 0 8px;font-size:14px;line-height:1.5;color:#374151">{{ $l }}</p>
        @endforeach
        @if (!empty($accion['url']))
          <p style="margin:20px 0 4px"><a href="{{ $accion['url'] }}" style="display:inline-block;background:#e11d48;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 20px;border-radius:10px">{{ $accion['texto'] ?? 'Abrir editus' }}</a></p>
        @endif
      </td></tr>
      <tr><td style="padding:14px 24px;background:#f9fafb;font-size:12px;color:#9ca3af">Este aviso lo envía editus porque programaste una transmisión. </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
