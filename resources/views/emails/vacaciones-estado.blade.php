<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #111827;">
    <h2>{{ $titulo }}</h2>
    @if ($nombre)
        <p>Hola {{ $nombre }},</p>
    @endif
    @foreach ($lineas as $linea)
        <p>{{ $linea }}</p>
    @endforeach
    <p>Consulta el estado de tus solicitudes en <a href="{{ $portalUrl }}">{{ $portalUrl }}</a>. Entra con tu número de empleado y el PIN de tu checador.</p>
    <p style="color: #6b7280; font-size: 12px;">Recursos Humanos · {{ config('app.name') }}</p>
</body>
</html>
