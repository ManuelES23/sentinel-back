<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Hace que los tipos de incidencia sean globales (no por empresa)
     */
    public function up(): void
    {
        Schema::table('incident_types', function (Blueprint $table) {
            // Eliminar la restricción foreign key
            $table->dropForeign(['enterprise_id']);
            
            // Eliminar el índice único compuesto
            $table->dropUnique(['enterprise_id', 'code']);
            
            // Hacer enterprise_id nullable
            $table->foreignId('enterprise_id')->nullable()->change();
            
            // Crear índice único solo en code (global)
            $table->unique('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_types', function (Blueprint $table) {
            // Eliminar índice único global
            $table->dropUnique(['code']);
            
            // Restaurar foreign key (sin cascade porque puede haber nulls)
            $table->foreignId('enterprise_id')->nullable(false)->change();
            $table->foreign('enterprise_id')->references('id')->on('enterprises')->onDelete('cascade');
            
            // Restaurar índice único compuesto
            $table->unique(['enterprise_id', 'code']);
        });
    }
};
