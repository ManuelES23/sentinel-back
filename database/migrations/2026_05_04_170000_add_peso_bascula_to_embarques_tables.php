<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embarques_empaque', function (Blueprint $table) {
            if (!Schema::hasColumn('embarques_empaque', 'peso_bascula_total_kg')) {
                $table->decimal('peso_bascula_total_kg', 12, 2)
                    ->default(0)
                    ->after('peso_total_kg');
            }
        });

        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('embarque_empaque_detalles', 'peso_bascula_kg')) {
                $table->decimal('peso_bascula_kg', 12, 2)
                    ->nullable()
                    ->after('peso_kg');
            }
        });
    }

    public function down(): void
    {
        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            if (Schema::hasColumn('embarque_empaque_detalles', 'peso_bascula_kg')) {
                $table->dropColumn('peso_bascula_kg');
            }
        });

        Schema::table('embarques_empaque', function (Blueprint $table) {
            if (Schema::hasColumn('embarques_empaque', 'peso_bascula_total_kg')) {
                $table->dropColumn('peso_bascula_total_kg');
            }
        });
    }
};
