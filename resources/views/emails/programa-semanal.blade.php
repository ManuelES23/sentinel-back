<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #111827;">
    <h2>Programa semanal{{ $cultivo ? ' · '.$cultivo : '' }}</h2>
    <p>Te compartimos el programa de {{ count($semanas) === 1 ? 'la semana' : 'las semanas' }}:</p>
    <ul>
        @foreach ($semanas as $semana)
            <li>{{ $semana }}</li>
        @endforeach
    </ul>
    @if ($nota)
        <p style="white-space: pre-line; border-left: 3px solid #1f4e3d; padding-left: 10px;">{{ $nota }}</p>
    @endif
    <p>El archivo adjunto ({{ $nombreArchivo }}) trae una hoja por semana. Las cifras son proyecciones del programa al momento del envío.</p>
    <p>Enviado por {{ $remitente }}</p>
    <p style="color: #6b7280; font-size: 12px;">Planeación · Splendid Farms · {{ config('app.name') }}</p>
</body>
</html>
