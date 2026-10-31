<?php

use App\Support\CRM\EmpresasCrm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La oportunidad pasa a llevar un solo «tipo de producto» (spec 2026-10-30
 * §6): se apaga `productos_en_oportunidad` en todas las empresas. Las líneas
 * de producto ya guardadas no se tocan. `down()` restaura lo que había antes
 * de la migración: solo Splendid by Porvenir (por slug) lo tenía en true.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('crm_configuraciones_comerciales')->update([
            'productos_en_oportunidad' => false,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $sbpId = EmpresasCrm::idPorSlug(EmpresasCrm::SLUG_SBP);
        if ($sbpId === null) {
            return;
        }

        DB::table('crm_configuraciones_comerciales')->where('empresa_id', $sbpId)->update([
            'productos_en_oportunidad' => true,
            'updated_at' => now(),
        ]);
    }
};
