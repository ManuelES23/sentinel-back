<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días de antelación con los que se empaca para cada destino (Planeación →
 * Configuración → Destinos): 0 = se empaca el mismo día del embarque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_bodegas_destino', function (Blueprint $table) {
            if (! Schema::hasColumn('plan_bodegas_destino', 'dias_antelacion')) {
                $table->unsignedTinyInteger('dias_antelacion')->default(0)->after('nombre');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plan_bodegas_destino', function (Blueprint $table) {
            if (Schema::hasColumn('plan_bodegas_destino', 'dias_antelacion')) {
                $table->dropColumn('dias_antelacion');
            }
        });
    }
};
