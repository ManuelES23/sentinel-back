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
            // Eliminar la columna tipo_variedad (string)
            $table->dropColumn('tipo_variedad');
            
            // Agregar la columna tipo_variedad_id (FK nullable)
            $table->foreignId('tipo_variedad_id')->nullable()->after('nombre')->constrained('tipos_variedad')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('variedades', function (Blueprint $table) {
            // Eliminar la FK
            $table->dropForeign(['tipo_variedad_id']);
            $table->dropColumn('tipo_variedad_id');
            
            // Restaurar la columna original
            $table->string('tipo_variedad')->nullable()->after('nombre');
        });
    }
};
