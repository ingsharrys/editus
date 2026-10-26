<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Certificado {{ $participante->medio_nombre }} – {{ $campana->name }}</title>
    <style>
        @page { margin: 60px 70px; }
        body { margin: 0; }
    </style>
</head>
<body>
    @include('certificados._hoja')
</body>
</html>
