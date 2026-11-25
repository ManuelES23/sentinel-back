<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calidad de empaque: cantidad «aprovechable para cuarto frío» dentro de lo que
 * cumple, y el porcentaje que cumple en fresco (sin contar cuarto frío).
 * Aditiva y repetible; las evaluaciones anteriores quedan con el mismo
 * porcentaje en fresco que ya tenían.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('calidad_empaque', 'cuarto_frio_total')) {
            Schema::table('calidad_empaque', function (Blueprint $table) {
                $table->decimal('cuarto_frio_total', 12, 2)->default(0)->after('no_cumple_total');
            });
        }

        if (! Schema::hasColumn('calidad_empaque', 'porcentaje_cumple_fresco')) {
            Schema::table('calidad_empaque', function (Blueprint $table) {
                $table->decimal('porcentaje_cumple_fresco', 5, 2)->nullable()->after('porcentaje_cumple');
            });
        }

        // Relleno idempotente (incluye las eliminadas lógicamente).
        DB::table('calidad_empaque')
            ->whereNull('porcentaje_cumple_fresco')
            ->whereNotNull('porcentaje_cumple')
            ->update(['porcentaje_cumple_fresco' => DB::raw('porcentaje_cumple')]);
    }

    public function down(): void
    {
        foreach (['porcentaje_cumple_fresco', 'cuarto_frio_total'] as $columna) {
            if (Schema::hasColumn('calidad_empaque', $columna)) {
                Schema::table('calidad_empaque', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
};
