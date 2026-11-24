<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas que lleva un Full y un Sencillo hacia cada destino (Planeación →
 * Configuración → Destinos). Nulo = el destino no usa ese tipo de carga.
 * Reemplazan a las de `plan_presentaciones`, que quedan sin uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_bodegas_destino', function (Blueprint $table) {
            if (! Schema::hasColumn('plan_bodegas_destino', 'cajas_full')) {
                $table->unsignedInteger('cajas_full')->nullable()->after('dias_antelacion');
            }
            if (! Schema::hasColumn('plan_bodegas_destino', 'cajas_sencillo')) {
                $table->unsignedInteger('cajas_sencillo')->nullable()->after('cajas_full');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plan_bodegas_destino', function (Blueprint $table) {
            foreach (['cajas_sencillo', 'cajas_full'] as $columna) {
                if (Schema::hasColumn('plan_bodegas_destino', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
