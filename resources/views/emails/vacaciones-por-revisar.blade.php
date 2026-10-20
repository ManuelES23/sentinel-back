<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #111827;">
    <h2>Solicitud de vacaciones por revisar</h2>
    @if ($nombre)
        <p>Hola {{ $nombre }},</p>
    @endif
    <p>{{ $solicitante }} solicitó {{ $dias }} de vacaciones {{ $periodo }} y te toca revisarla como jefe de su departamento.</p>
    <p>Entra a <a href="{{ $portalUrl }}">{{ $portalUrl }}</a> con tu número de empleado y el PIN de tu checador, y abre la pestaña «Por revisar».</p>
    <p style="color: #6b7280; font-size: 12px;">Recursos Humanos · {{ config('app.name') }}</p>
</body>
</html>
