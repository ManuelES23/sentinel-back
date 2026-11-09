<?php
// sentinel-back/config/biometrics.php
return [
    // Distancia euclidiana máxima entre embeddings para considerar match (128-dim, @vladmandic/face-api)
    'match_threshold' => (float) env('BIOMETRICS_MATCH_THRESHOLD', 0.5),

    // Minutos de desfase entre el reloj del dispositivo y el servidor antes de marcar para revisión
    'clock_skew_tolerance_minutes' => (int) env('BIOMETRICS_CLOCK_SKEW_TOLERANCE_MINUTES', 10),

    // Ventana en minutos para considerar dos chequeos del mismo empleado+tipo como duplicado
    'duplicate_collapse_minutes' => (int) env('BIOMETRICS_DUPLICATE_COLLAPSE_MINUTES', 5),

    // Días de retención de la foto de evidencia de un chequeo ya resuelto (verified/manually_approved/rejected) antes de purgarla
    'evidence_retention_days' => (int) env('BIOMETRICS_EVIDENCE_RETENTION_DAYS', 90),

    // Días desde la revocación de una plantilla facial antes de purgar su foto y embedding
    'revoked_template_retention_days' => (int) env('BIOMETRICS_REVOKED_TEMPLATE_RETENTION_DAYS', 30),

    // Minutos que un SfFieldCheck puede quedarse en 'pending' antes de que
    // biometrics:requeue-stale-checks lo considere atascado y lo re-despache.
    // Default 60min: la ventana normal de reintento de VerifyFieldCheckJob
    // (tries=5, backoff 30/60/300/900s) es ~21.5min, así que esto deja margen
    // de sobra antes de asumir que el job se perdió (worker muerto, cola
    // vaciada, etc.) en vez de que solo esté reintentando.
    'stale_pending_requeue_minutes' => (int) env('BIOMETRICS_STALE_PENDING_REQUEUE_MINUTES', 60),

    // Checador de campo SF como app directa (dispositivo emparejado, modo sf_field):
    // días sin uso tras los cuales el dispositivo caduca (se mide contra last_used_at,
    // o created_at si nunca se usó).
    'sf_field_device_idle_days' => (int) env('BIOMETRICS_SF_FIELD_DEVICE_IDLE_DAYS', 30),

    // Slug de la empresa a la que pertenece el checador de campo.
    'sf_field_enterprise_slug' => env('BIOMETRICS_SF_FIELD_ENTERPRISE_SLUG', 'splendidfarms'),
];
