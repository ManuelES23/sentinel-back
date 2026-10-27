<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #111827;">
    <h2>Solicitud de vacaciones por revisar</h2>
    @if ($nombre)
        <p>Hola {{ $nombre }},</p>
    @endif
    <p>
        {{ $solicitante }} solicitó {{ $dias }} de vacaciones {{ $periodo }} y te toca revisarla{{ $comoJefe ? ' como jefe de su departamento' : '' }}.
    </p>
    @if ($motivo)
        <p>Motivo: {{ $motivo }}</p>
    @endif

    @if ($via === 'portal')
        <p>Entra a <a href="{{ $portalUrl }}">{{ $portalUrl }}</a> con tu número de empleado y el PIN de tu checador, y abre la pestaña «Por revisar».</p>
    @elseif ($via === 'portal_o_sentinel')
        <p>Puedes aprobarla o rechazarla en Sentinel, en tus aprobaciones pendientes: <a href="{{ $bandejaUrl }}">{{ $bandejaUrl }}</a>.</p>
        <p>O desde el portal de vacaciones, <a href="{{ $portalUrl }}">{{ $portalUrl }}</a>, con tu número de empleado y el PIN de tu checador (pestaña «Por revisar»).</p>
    @elseif ($via === 'rh')
        <p>Resuélvela en Sentinel, en RH → Gestión → Vacaciones (pestaña «Por atender»): <a href="{{ $sentinelUrl }}">{{ $sentinelUrl }}</a>.</p>
    @else
        <p>Apruébala o recházala en Sentinel, en tus aprobaciones pendientes: <a href="{{ $bandejaUrl }}">{{ $bandejaUrl }}</a>.</p>
    @endif
    <p style="color: #6b7280; font-size: 12px;">Recursos Humanos · {{ config('app.name') }}</p>
</body>
</html>
