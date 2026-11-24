@php
    $cajas = fn ($n) => number_format((float) $n);
    $signo = fn ($n) => $n > 0 ? '+'.number_format($n) : ($n < 0 ? '−'.number_format(-$n) : '0');
@endphp
<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #111827; margin: 0; padding: 16px;">
    <h2 style="margin: 0 0 8px;">Programa semanal{{ $cultivo ? ' · '.$cultivo : '' }}</h2>
    <p style="margin: 0 0 12px;">Te compartimos el programa de {{ count($semanas) === 1 ? 'la semana' : 'las semanas' }}
        {{ implode(', ', $semanas) }}. Cada imagen es una semana y empieza con el viernes y el sábado de la semana anterior para ver cómo cerró.</p>
    @if ($nota)
        <p style="white-space: pre-line; border-left: 3px solid #1f4e3d; padding-left: 10px; margin: 0 0 16px;">{{ $nota }}</p>
    @endif

    @foreach ($imagenes as $i => $imagen)
        @php($r = $imagen['resumen'])
        @php($k = $r['kpis'])
        <h3 style="margin: 20px 0 4px; font-size: 16px;">{{ $semanas[$i] ?? 'Semana '.$r['semana'] }}</h3>
        <p style="margin: 0 0 4px; font-size: 14px;">
            Producción <b>{{ $cajas($k['produccion']) }}</b> cj ·
            Necesidad <b>{{ $cajas($k['necesidad']) }}</b> cj ·
            Disponible para venta <b style="color: {{ $k['disponible'] < 0 ? '#b42318' : ($k['disponible'] > 0 ? '#1a7343' : '#111827') }};">{{ $signo($k['disponible']) }}</b> cj
            @if (($k['empacado_sin_venta'] ?? 0) > 0)
                (<b>{{ $cajas($k['empacado_sin_venta']) }}</b> ya empacadas sin pedido)
            @endif
            ·
            Embarques <b>{{ $k['full'] }} F · {{ $k['sencillo'] }} S</b>
        </p>
        <p style="margin: 0 0 8px; font-size: 13px; color: #4b5563;">
            @if (count($r['faltantes']) > 0)
                Días con faltante:
                @foreach ($r['faltantes'] as $f)
                    {{ $f['dia'] }} <span style="color: #b42318; font-weight: bold;">{{ $signo($f['cajas']) }}</span>@if (! $loop->last) · @endif
                @endforeach
            @else
                Ningún día con faltante.
            @endif
        </p>
        <img src="{{ ($vistaPrevia ?? false) ? 'data:image/png;base64,'.base64_encode($imagen['png']) : $message->embedData($imagen['png'], $imagen['nombre'], 'image/png') }}" alt="Programa semana {{ $r['semana'] }}" width="{{ $imagen['ancho'] ?? 760 }}" style="max-width: 100%; height: auto; border: 1px solid #d9e1dc;">
    @endforeach

    <p style="margin: 20px 0 6px; font-size: 13px; color: #4b5563;">Las imágenes también van adjuntas.@if ($nombreArchivo) El detalle completo va en el Excel adjunto ({{ $nombreArchivo }}), con una hoja por semana.@endif Las cifras son proyecciones del programa al momento del envío.</p>
    <p style="margin: 0 0 6px;">Enviado por {{ $remitente }}</p>
    <p style="color: #6b7280; font-size: 12px; margin: 0;">Planeación · Splendid Farms · {{ config('app.name') }}</p>
</body>
</html>
