<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convenios_compra', function (Blueprint $table) {
            $table->unique(
                ['temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad', 'fecha_inicio'],
                'convenios_compra_unique_by_start_date'
            );

            $table->dropUnique('convenios_compra_unique');
        });
    }

    public function down(): void
    {
        Schema::table('convenios_compra', function (Blueprint $table) {
            $table->dropUnique('convenios_compra_unique_by_start_date');

            $table->unique(
                ['temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad'],
                'convenios_compra_unique'
            );
        });
    }
};
