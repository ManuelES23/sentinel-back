<?php

namespace Database\Seeders;

use App\Support\CRM\EtiquetasActividadPredeterminadas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra las 6 etiquetas de actividad en cada empresa que tiene CRM y todavía
 * no tiene ninguna. Idempotente:
 *   php artisan db:seed --class=CrmEtiquetasActividadSeeder
 *
 * Va aparte de CrmPermisosSeeder a propósito: ese seeder lo corren casi todas
 * las pruebas del CRM (otorgarPermisosCrm) y no deben nacer con etiquetas.
 */
class CrmEtiquetasActividadSeeder extends Seeder
{
    public function run(): void
    {
        $empresaIds = DB::table('applications')->where('slug', 'crm')->pluck('enterprise_id')->unique();

        foreach ($empresaIds as $empresaId) {
            EtiquetasActividadPredeterminadas::sembrar((int) $empresaId);
        }

        if ($this->command) {
            $this->command->info("CRM: etiquetas de actividad revisadas en {$empresaIds->count()} empresa(s).");
        }
    }
}
