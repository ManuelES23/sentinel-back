<?php

return [
    // Enlace del portal que va en los correos y en los mensajes de WhatsApp.
    'portal_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/').'/vacaciones',

    // false = el correo se envía en la misma petición (SMTP con timeout de 10 s, ver MailSettings);
    // true = se manda por la cola (necesita un worker corriendo: `php artisan queue:work`).
    'correo_en_cola' => (bool) env('VACACIONES_CORREO_EN_COLA', false),
];
