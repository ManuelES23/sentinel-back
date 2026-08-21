<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // La FK sobre zona_cultivo_id debe quitarse antes que el índice
        // compuesto que la respalda: MySQL rechaza dropIndex() con
        // "needed in a foreign key constraint" si el índice sigue
        // soportando la FK. SQLite (usado en tests) es más permisivo pero
        // igual reconstruye la tabla, así que el mismo orden funciona ahí.
        Schema::table('lotes', function (Blueprint $table) {
            // Quitar la constraint de zona_cultivo_id
            $table->dropForeign(['zona_cultivo_id']);
        });

        Schema::table('lotes', function (Blueprint $table) {
            $table->dropIndex(['zona_cultivo_id', 'is_active']);
        });

        Schema::table('lotes', function (Blueprint $table) {
            // Agregar nuevo campo numero_lote (ID incremental por cultivo)
            $table->unsignedInteger('numero_lote')->default(0)->after('id')
                ->comment('Número secuencial del lote (autoincremental)');

            // Agregar campo cultivo_id como referencia (nullable al principio)
            $table->foreignId('cultivo_id')->nullable()->after('numero_lote')
                ->constrained('cultivos')->onDelete('set null');

            // Quitar la columna zona_cultivo_id (FK e índice ya se quitaron arriba)
            $table->dropColumn('zona_cultivo_id');

            // Actualizar índice
            $table->index(['cultivo_id', 'numero_lote', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            // Restaurar zona_cultivo_id
            $table->foreignId('zona_cultivo_id')->nullable()->after('id')
                ->constrained('zonas_cultivo')->onDelete('cascade');
            
            // Quitar nuevos campos
            $table->dropIndex(['cultivo_id', 'numero_lote', 'is_active']);
            $table->dropForeign(['cultivo_id']);
            $table->dropColumn(['cultivo_id', 'numero_lote']);
        });
    }
};
