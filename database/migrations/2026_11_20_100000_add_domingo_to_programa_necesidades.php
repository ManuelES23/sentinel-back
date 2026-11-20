<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La semana de Planeación pasa de lunes–sábado a lunes–domingo: las necesidades
 * de venta ganan la columna del día 6 (domingo). Cortes y embarques guardan el día
 * como número, así que no necesitan columnas nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('programa_necesidades', 'cajas_6')) {
            Schema::table('programa_necesidades', function (Blueprint $table) {
                $table->unsignedInteger('cajas_6')->default(0)->after('cajas_5');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('programa_necesidades', 'cajas_6')) {
            Schema::table('programa_necesidades', function (Blueprint $table) {
                $table->dropColumn('cajas_6');
            });
        }
    }
};
