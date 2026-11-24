<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domingo por proceso de Planeación: `domingo_corte`, `domingo_empaque` y
 * `domingo_embarques` (true por omisión). Cada semana existente los toma de su
 * `trabaja_domingo` (los tres iguales). `trabaja_domingo` se conserva y pasa a
 * guardar «alguno de los tres trabaja el domingo».
 *
 * Repetible: solo agrega (y llena) las columnas que faltan, así una segunda
 * corrida no pisa lo que ya se cambió por proceso.
 */
return new class extends Migration
{
    private const COLUMNAS = ['domingo_corte', 'domingo_empaque', 'domingo_embarques'];

    public function up(): void
    {
        $faltan = array_values(array_filter(
            self::COLUMNAS,
            fn (string $columna) => ! Schema::hasColumn('programas_semanales', $columna)
        ));
        if ($faltan === []) {
            return;
        }

        Schema::table('programas_semanales', function (Blueprint $table) use ($faltan) {
            foreach ($faltan as $columna) {
                $table->boolean($columna)->default(true);
            }
        });

        if (Schema::hasColumn('programas_semanales', 'trabaja_domingo')) {
            DB::table('programas_semanales')->update(
                array_fill_keys($faltan, DB::raw('trabaja_domingo'))
            );
        }
    }

    public function down(): void
    {
        $existen = array_values(array_filter(
            self::COLUMNAS,
            fn (string $columna) => Schema::hasColumn('programas_semanales', $columna)
        ));
        if ($existen === []) {
            return;
        }

        Schema::table('programas_semanales', function (Blueprint $table) use ($existen) {
            $table->dropColumn($existen);
        });
    }
};
