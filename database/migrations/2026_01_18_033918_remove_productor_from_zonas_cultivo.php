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
        // La FK sobre productor_id debe quitarse antes que el índice
        // compuesto que la respalda: MySQL rechaza dropIndex() con
        // "needed in a foreign key constraint" si el índice sigue
        // soportando la FK. SQLite (usado en tests) es más permisivo pero
        // igual reconstruye la tabla, así que el mismo orden funciona ahí.
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            // Eliminar foreign key si existe
            $table->dropForeign(['productor_id']);
        });

        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->dropIndex(['productor_id', 'is_active']);
        });

        Schema::table('zonas_cultivo', function (Blueprint $table) {
            // Eliminar columnas
            $table->dropColumn(['productor_id', 'superficie_total']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->foreignId('productor_id')->nullable()->after('id')->constrained('productores')->nullOnDelete();
            $table->decimal('superficie_total', 10, 2)->nullable()->after('ubicacion');
        });

        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->index(['productor_id', 'is_active']);
        });
    }
};
