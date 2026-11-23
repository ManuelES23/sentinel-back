<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domingo opcional por semana de Planeación: `trabaja_domingo` (true por
 * omisión, así las semanas existentes y las nuevas quedan con domingo). Sin
 * domingo, lo que se empacaría el domingo se empaca el sábado y las hojas no
 * aceptan valores en el día 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('programas_semanales', 'trabaja_domingo')) {
            return;
        }

        Schema::table('programas_semanales', function (Blueprint $table) {
            $table->boolean('trabaja_domingo')->default(true)->after('notas');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('programas_semanales', 'trabaja_domingo')) {
            return;
        }

        Schema::table('programas_semanales', function (Blueprint $table) {
            $table->dropColumn('trabaja_domingo');
        });
    }
};
