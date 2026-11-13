<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renglones de recepción por calibre (spec 2026-11-12): una recepción de un convenio por
 * calibre se captura en renglones (uno por calibre) y los totales de la cabecera
 * (`abs_recepciones.cantidad/kilos/rezaga_kg`) son la suma de sus renglones.
 * Sin borrado lógico: la cabecera lo lleva y la bitácora también vive allá.
 * Re-ejecutable sin duplicar (guarda con hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('abs_recepcion_calibres')) {
            return;
        }

        Schema::create('abs_recepcion_calibres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_id')->constrained('abs_recepciones')->cascadeOnDelete();
            $table->foreignId('calibre_id')->constrained('abs_variedad_calibres');
            $table->decimal('cantidad', 14, 2);
            $table->decimal('kilos', 14, 2)->nullable();
            $table->decimal('rezaga_kg', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['recepcion_id', 'calibre_id'], 'abs_recepcion_calibres_unique');
            $table->index('calibre_id', 'abs_recepcion_calibres_calibre_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_recepcion_calibres');
    }
};
