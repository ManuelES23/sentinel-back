<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia (foto) del plan que se acordó en la junta del programa semanal: los
 * cortes y las cargas de embarque tal como estaban al guardarla. Cada guardado
 * es una fila nueva, así que hay historial; la vigente es la de mayor `id` del
 * programa. Sin `updated_at`: una foto no se edita.
 *
 * Borrar el programa borra sus fotos; borrar al usuario que acordó deja
 * `acordado_por` en nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('programa_planes_acordados')) {
            return;
        }

        Schema::create('programa_planes_acordados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programa_id')->constrained('programas_semanales')->cascadeOnDelete();
            $table->foreignId('acordado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->json('cortes');
            $table->json('embarques');
            $table->timestamp('created_at')->nullable();

            $table->index(['programa_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programa_planes_acordados');
    }
};
