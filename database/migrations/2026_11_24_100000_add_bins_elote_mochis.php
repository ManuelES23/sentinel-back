<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elote Mochis en bins: todas las columnas nuevas de la función, en una sola
 * migración (solo aditiva y repetible). Una temporada sin el interruptor
 * `opera_en_bins` no toca ninguna de ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('temporadas', 'opera_en_bins')) {
            Schema::table('temporadas', function (Blueprint $table) {
                $table->boolean('opera_en_bins')->default(false);
            });
        }

        if (! Schema::hasColumn('tipos_carga', 'clase_rezaga')) {
            Schema::table('tipos_carga', function (Blueprint $table) {
                // null = bin de campo; hoja / fresco / cuarto_frio = bin de rezaga.
                $table->string('clase_rezaga', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('rezaga_empaque', 'cantidad_bins')) {
            Schema::table('rezaga_empaque', function (Blueprint $table) {
                $table->unsignedInteger('cantidad_bins')->nullable();
            });
        }

        // La «salida de rezaga» vive en venta_rezaga_empaque (modelo SalidaRezagaEmpaque).
        if (! Schema::hasColumn('venta_rezaga_empaque', 'total_bins')) {
            Schema::table('venta_rezaga_empaque', function (Blueprint $table) {
                $table->unsignedInteger('total_bins')->nullable();
            });
        }
        if (! Schema::hasColumn('venta_rezaga_empaque', 'precio_bin')) {
            Schema::table('venta_rezaga_empaque', function (Blueprint $table) {
                $table->decimal('precio_bin', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('venta_rezaga_empaque_detalles', 'bins')) {
            Schema::table('venta_rezaga_empaque_detalles', function (Blueprint $table) {
                $table->unsignedInteger('bins')->nullable();
            });
        }
        if (! Schema::hasColumn('venta_rezaga_empaque_detalles', 'precio_bin')) {
            Schema::table('venta_rezaga_empaque_detalles', function (Blueprint $table) {
                $table->decimal('precio_bin', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        $columnas = [
            'venta_rezaga_empaque_detalles' => ['precio_bin', 'bins'],
            'venta_rezaga_empaque' => ['precio_bin', 'total_bins'],
            'rezaga_empaque' => ['cantidad_bins'],
            'tipos_carga' => ['clase_rezaga'],
            'temporadas' => ['opera_en_bins'],
        ];

        foreach ($columnas as $tabla => $lista) {
            foreach ($lista as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    Schema::table($tabla, function (Blueprint $table) use ($columna) {
                        $table->dropColumn($columna);
                    });
                }
            }
        }
    }
};
