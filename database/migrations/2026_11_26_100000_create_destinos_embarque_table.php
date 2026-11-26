<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de destinos de los embarques de Empaque (Splendid Farms). Cada destino
 * puede ligarse, opcionalmente, con una bodega de Planeación (`plan_bodegas_destino`)
 * para que sus embarques cuenten en «Avance de embarques». El embarque guarda el
 * id del destino y, como hoy, su nombre en `destino` (para el PDF, la búsqueda y
 * el Excel). Sin carga inicial: los embarques viejos quedan sin destino.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('destinos_embarque')) {
            Schema::create('destinos_embarque', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises');
                $table->string('nombre', 150);
                $table->foreignId('plan_bodega_destino_id')->nullable()
                    ->constrained('plan_bodegas_destino')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['enterprise_id', 'is_active']);
            });
        }

        if (! Schema::hasColumn('embarques_empaque', 'destino_embarque_id')) {
            Schema::table('embarques_empaque', function (Blueprint $table) {
                $table->foreignId('destino_embarque_id')->nullable()->after('destino')
                    ->constrained('destinos_embarque')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('embarques_empaque', 'destino_embarque_id')) {
            Schema::table('embarques_empaque', function (Blueprint $table) {
                $table->dropConstrainedForeignId('destino_embarque_id');
            });
        }

        Schema::dropIfExists('destinos_embarque');
    }
};
