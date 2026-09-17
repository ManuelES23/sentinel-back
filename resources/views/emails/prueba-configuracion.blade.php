<!doctype html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #111827;">
    <h2>La configuración de correo funciona</h2>
    <p>Este es un correo de prueba enviado desde el panel de administración de {{ config('app.name') }}.</p>
    <p style="color: #6b7280; font-size: 12px;">Enviado el {{ now()->format('d/m/Y H:i') }}.</p>
</body>
</html>
