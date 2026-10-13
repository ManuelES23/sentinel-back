<?php

namespace Database\Seeders;

use App\Support\CRM\PlantillasWhatsappPredeterminadas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra las 4 plantillas de WhatsApp en cada empresa que tiene CRM y
 * todavía no tiene ninguna. Idempotente:
 *   php artisan db:seed --class=CrmPlantillasWhatsappSeeder
 *
 * Va aparte de CrmPermisosSeeder a propósito: ese seeder lo corren casi todas
 * las pruebas del CRM (otorgarPermisosCrm) y no deben nacer con plantillas.
 */
class CrmPlantillasWhatsappSeeder extends Seeder
{
    public function run(): void
    {
        $empresaIds = DB::table('applications')->where('slug', 'crm')->pluck('enterprise_id')->unique();
        $sembradas = 0;

        foreach ($empresaIds as $empresaId) {
            $sembradas += PlantillasWhatsappPredeterminadas::sembrar((int) $empresaId);
        }

        if ($this->command) {
            $this->command->info("CRM: {$sembradas} plantilla(s) de WhatsApp sembradas.");
        }
    }
}
