<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3: la aplicación descuenta stock de un almacén. Todo nullable: las
 * aplicaciones históricas quedan sin almacén ni movimiento y no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aplicaciones', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('temporada_id')->constrained('enterprises')->nullOnDelete();
            $table->foreignId('almacen_id')->nullable()->after('enterprise_id')->constrained('entities')->nullOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->after('almacen_id')->constrained('inventory_movements')->nullOnDelete();
        });

        Schema::table('aplicaciones_detalle', function (Blueprint $table) {
            $table->foreignId('unidad_dosis_id')->nullable()->after('unidad_medida')->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('conversion_factor', 12, 6)->nullable()->after('unidad_dosis_id');
            $table->decimal('base_quantity', 15, 4)->nullable()->after('conversion_factor');
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones_detalle', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unidad_dosis_id');
            $table->dropColumn(['conversion_factor', 'base_quantity']);
        });

        Schema::table('aplicaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_movement_id');
            $table->dropConstrainedForeignId('almacen_id');
            $table->dropConstrainedForeignId('enterprise_id');
        });
    }
};
