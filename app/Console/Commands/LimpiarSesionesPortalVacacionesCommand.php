<?php

namespace App\Console\Commands;

use App\Services\RH\SesionPortalVacaciones;
use Illuminate\Console\Command;

class LimpiarSesionesPortalVacacionesCommand extends Command
{
    protected $signature = 'vacaciones:limpiar-sesiones-portal';

    protected $description = 'Borra las sesiones del portal de vacaciones vencidas o revocadas hace más de 7 días';

    public function handle(SesionPortalVacaciones $sesiones): int
    {
        $borradas = $sesiones->limpiar();
        $this->info("Sesiones del portal de vacaciones borradas: {$borradas}.");

        return self::SUCCESS;
    }
}
