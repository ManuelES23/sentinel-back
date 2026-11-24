<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un embarque de una temporada que opera en bins (Elote en bins) no tiene peso de
 * báscula: se guarda NULL (no 0) para que los tableros caigan al peso estimado
 * (COALESCE(peso_bascula_total_kg, peso_total_kg)). Solo relaja la columna: el
 * default sigue siendo 0, así que cualquier alta que no la mande queda como hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->decimal('peso_bascula_total_kg', 12, 2)->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        DB::table('embarques_empaque')->whereNull('peso_bascula_total_kg')->update(['peso_bascula_total_kg' => 0]);

        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->decimal('peso_bascula_total_kg', 12, 2)->default(0)->nullable(false)->change();
        });
    }
};
