<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('biometrics:purge')->dailyAt('03:00');

// Umbral default de 60min (config('biometrics.stale_pending_requeue_minutes'))
// deja margen de sobra sobre la ventana normal de reintento de
// VerifyFieldCheckJob (~21.5min) — correrlo cada hora detecta un check
// atascado en pending poco después de cruzar ese umbral sin sobrecargar
// la cola con redespachos redundantes de checks que todavía están
// reintentando normalmente.
Schedule::command('biometrics:requeue-stale-checks')->hourly();

// Recordatorios de Agenda (CRM): granularidad de 5 min es razonable para
// un recordatorio de agenda comercial (llamada/visita en minutos, no en
// horas) sin sobrecargar con corridas más frecuentes.
Schedule::command('agenda:enviar-recordatorios')->everyFiveMinutes();

// Sincronización Agenda -> Outlook (CRM): unidireccional, cada 5 min es
// suficiente margen para reflejar cambios recientes sin saturar Microsoft
// Graph con corridas más frecuentes.
Schedule::command('agenda:sincronizar-outlook')->everyFiveMinutes();
