<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mixtearColas() ahora deriva la clasificación del pallet mixto a partir de las
 * colas origen: si mezclan orgánico + convencional, el resultado no encaja en
 * ninguno de los dos valores existentes. Se agrega 'mixto' al enum para poder
 * representarlo (columna sin FK/índice — es seguro soltarla y recrearla en SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE produccion_empaque MODIFY clasificacion ENUM('convencional', 'organico', 'mixto') NOT NULL DEFAULT 'convencional'");

            return;
        }

        // SQLite (tests): recrear la columna con el enum ampliado.
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn('clasificacion');
        });

        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->enum('clasificacion', ['convencional', 'organico', 'mixto'])
                ->default('convencional')
                ->after('categoria');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Los registros ya marcados 'mixto' se degradan a 'convencional' para
            // poder revertir el enum sin perder la fila.
            DB::statement("UPDATE produccion_empaque SET clasificacion = 'convencional' WHERE clasificacion = 'mixto'");
            DB::statement("ALTER TABLE produccion_empaque MODIFY clasificacion ENUM('convencional', 'organico') NOT NULL DEFAULT 'convencional'");
        }
        // No se revierte en SQLite: la BD de test se reconstruye desde cero en cada suite.
    }
};
