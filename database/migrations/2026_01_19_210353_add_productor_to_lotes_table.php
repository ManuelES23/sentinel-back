<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Cambio de diseño:
     * - Los lotes pertenecen a un productor (productor_id)
     * - El cultivo que se siembra en el lote se define al asignarlo a una temporada
     *   (ya existe en la tabla pivot temporada_lote.cultivo_id)
     * - Se elimina cultivo_id de la tabla lotes
     */
    public function up(): void
    {
        // La FK sobre cultivo_id debe quitarse antes que el índice
        // compuesto que la respalda: MySQL rechaza dropIndex() con
        // "needed in a foreign key constraint" si el índice sigue
        // soportando la FK. SQLite (usado en tests) es más permisivo pero
        // igual reconstruye la tabla, así que el mismo orden funciona ahí.
        if (Schema::hasColumn('lotes', 'cultivo_id')) {
            Schema::table('lotes', function (Blueprint $table) {
                $table->dropForeign(['cultivo_id']);
            });

            Schema::table('lotes', function (Blueprint $table) {
                $table->dropIndex(['cultivo_id', 'numero_lote', 'is_active']);
            });
        }

        Schema::table('lotes', function (Blueprint $table) {
            // Agregar productor_id (obligatorio)
            $table->foreignId('productor_id')
                ->nullable() // temporalmente nullable para migrar datos existentes
                ->after('numero_lote')
                ->constrained('productores')
                ->onDelete('cascade');

            // Eliminar cultivo_id si existe (FK e índice ya se quitaron arriba)
            if (Schema::hasColumn('lotes', 'cultivo_id')) {
                $table->dropColumn('cultivo_id');
            }
        });
        
        // Actualizar índice
        Schema::table('lotes', function (Blueprint $table) {
            $table->index(['productor_id', 'numero_lote', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropIndex(['productor_id', 'numero_lote', 'is_active']);
            $table->dropForeign(['productor_id']);
            $table->dropColumn('productor_id');
            
            // Restaurar cultivo_id
            $table->foreignId('cultivo_id')
                ->nullable()
                ->after('numero_lote')
                ->constrained('cultivos')
                ->onDelete('set null');
        });
    }
};
