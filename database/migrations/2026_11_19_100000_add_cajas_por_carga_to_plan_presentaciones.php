<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas que lleva un Full y un Sencillo de cada presentación (Planeación →
 * Configuración → Cajas por carga). Nulas = sin capturar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_presentaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('plan_presentaciones', 'cajas_full')) {
                $table->unsignedSmallInteger('cajas_full')->nullable()->after('nombre');
            }
            if (! Schema::hasColumn('plan_presentaciones', 'cajas_sencillo')) {
                $table->unsignedSmallInteger('cajas_sencillo')->nullable()->after('cajas_full');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plan_presentaciones', function (Blueprint $table) {
            foreach (['cajas_sencillo', 'cajas_full'] as $columna) {
                if (Schema::hasColumn('plan_presentaciones', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
