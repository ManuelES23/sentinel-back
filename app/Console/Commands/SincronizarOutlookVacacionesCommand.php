<?php

namespace App\Console\Commands;

use App\Models\VacationOutlookConnection;
use App\Services\RH\OutlookVacaciones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Respaldo programado de la sincronización inmediata: agenda en Outlook las vacaciones que aprobó
 * cada usuario vinculado y borra las canceladas. Un fallo en una conexión no detiene a las demás.
 */
class SincronizarOutlookVacacionesCommand extends Command
{
    protected $signature = 'vacaciones:sincronizar-outlook';

    protected $description = 'Agenda en el Outlook de quien autoriza las vacaciones que aprobó (unidireccional) y borra las canceladas';

    public function handle(OutlookVacaciones $outlook): int
    {
        $conexiones = VacationOutlookConnection::all();
        $procesadas = 0;

        foreach ($conexiones as $conexion) {
            try {
                $outlook->sincronizar($conexion);
                $procesadas++;
            } catch (\Throwable $e) {
                Log::error("Error al sincronizar Outlook (vacaciones) de la conexión #{$conexion->id} (".get_class($e).").");
                $conexion->update(['ultimo_error' => 'No se pudo sincronizar con Outlook; se reintentará en la siguiente corrida.']);
            }
        }

        $this->info("Conexiones de Outlook (vacaciones) sincronizadas: {$procesadas}/{$conexiones->count()}");

        return self::SUCCESS;
    }
}
