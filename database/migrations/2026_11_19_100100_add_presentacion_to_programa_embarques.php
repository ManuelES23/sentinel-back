<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada renglón de embarque lleva su presentación (Bulk, Tray…): la celda pasa a ser
 * programa · cultivo · bodega · presentación · tipo · día. Nula = embarque capturado
 * antes de este cambio (se puede bajar o borrar, no subir).
 *
 * La llave única nueva se crea ANTES de quitar la vieja: ambas empiezan por
 * `programa_id`, y MySQL necesita un índice que sirva a esa llave foránea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('programa_embarques', 'presentacion_id')) {
            Schema::table('programa_embarques', function (Blueprint $table) {
                $table->foreignId('presentacion_id')->nullable()->after('bodega_id')
                    ->constrained('plan_presentaciones')->restrictOnDelete();
                $table->unique(
                    ['programa_id', 'cultivo_id', 'bodega_id', 'presentacion_id', 'tipo', 'dia'],
                    'programa_embarques_celda_pres_unique',
                );
            });
        }

        if ($this->tieneIndice('programa_embarques_celda_unique')) {
            Schema::table('programa_embarques', function (Blueprint $table) {
                $table->dropUnique('programa_embarques_celda_unique');
            });
        }
    }

    public function down(): void
    {
        // Las celdas con la misma bodega, tipo y día en dos presentaciones no caben en la llave vieja.
        if (! $this->tieneIndice('programa_embarques_celda_unique')) {
            Schema::table('programa_embarques', function (Blueprint $table) {
                $table->unique(['programa_id', 'cultivo_id', 'bodega_id', 'tipo', 'dia'], 'programa_embarques_celda_unique');
            });
        }

        if (Schema::hasColumn('programa_embarques', 'presentacion_id')) {
            Schema::table('programa_embarques', function (Blueprint $table) {
                $table->dropUnique('programa_embarques_celda_pres_unique');
                $table->dropConstrainedForeignId('presentacion_id');
            });
        }
    }

    private function tieneIndice(string $nombre): bool
    {
        foreach (Schema::getIndexes('programa_embarques') as $indice) {
            if ($indice['name'] === $nombre) {
                return true;
            }
        }

        return false;
    }
};
