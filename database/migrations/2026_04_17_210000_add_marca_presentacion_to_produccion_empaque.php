<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->string('marca', 150)->nullable()->after('tipo_empaque');
            $table->string('presentacion', 150)->nullable()->after('marca');
        });

        Schema::table('produccion_empaque_detalles', function (Blueprint $table) {
            $table->string('marca', 150)->nullable()->after('tipo_empaque');
            $table->string('presentacion', 150)->nullable()->after('marca');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn(['marca', 'presentacion']);
        });

        Schema::table('produccion_empaque_detalles', function (Blueprint $table) {
            $table->dropColumn(['marca', 'presentacion']);
        });
    }
};
