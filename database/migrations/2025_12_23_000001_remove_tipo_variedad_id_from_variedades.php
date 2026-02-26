<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('variedades', function (Blueprint $table) {
            // Eliminar foreign key y columna tipo_variedad_id
            $table->dropForeign(['tipo_variedad_id']);
            $table->dropColumn('tipo_variedad_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variedades', function (Blueprint $table) {
            // Restaurar columna y foreign key
            $table->foreignId('tipo_variedad_id')->nullable()->constrained('tipos_variedad')->onDelete('set null');
        });
    }
};
