<?php

use App\Support\CRM\EmpresasCrm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moneda y «productos en la oportunidad» por empresa. Splendid by Porvenir
 * (localizada por slug, nunca por id) queda en USD y con productos; el resto
 * de las empresas conserva MXN y sin productos. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_configuraciones_comerciales', 'moneda')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->string('moneda', 3)->default('MXN')->after('descuento_global_habilitado');
            });
        }
        if (! Schema::hasColumn('crm_configuraciones_comerciales', 'productos_en_oportunidad')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->boolean('productos_en_oportunidad')->default(false)->after('moneda');
            });
        }

        $sbpId = EmpresasCrm::idPorSlug(EmpresasCrm::SLUG_SBP);
        if ($sbpId === null) {
            return;
        }

        $existe = DB::table('crm_configuraciones_comerciales')->where('empresa_id', $sbpId)->exists();
        if ($existe) {
            // Respeta lo que ya tenga (p. ej. descuento global): solo moneda y productos.
            DB::table('crm_configuraciones_comerciales')->where('empresa_id', $sbpId)->update([
                'moneda' => 'USD',
                'productos_en_oportunidad' => true,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('crm_configuraciones_comerciales')->insert([
                'empresa_id' => $sbpId,
                'descuento_global_habilitado' => true,
                'moneda' => 'USD',
                'productos_en_oportunidad' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['productos_en_oportunidad', 'moneda'] as $columna) {
            if (Schema::hasColumn('crm_configuraciones_comerciales', $columna)) {
                Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
};
