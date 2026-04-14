<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_embarques_empaque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas');
            $table->foreignId('entity_id')->constrained('entities');
            $table->string('folio_pre_embarque', 20)->unique();
            $table->integer('espacios_caja')->default(22);
            $table->enum('status', ['abierto', 'completado', 'cancelado'])->default('abierto');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'entity_id']);
            $table->index('status');
        });

        Schema::create('pre_embarque_empaque_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_embarque_id')->constrained('pre_embarques_empaque')->cascadeOnDelete();
            $table->foreignId('produccion_id')->constrained('produccion_empaque');
            $table->integer('posicion_carga');
            $table->timestamps();

            $table->unique(['pre_embarque_id', 'produccion_id'], 'pre_emb_det_embarque_produccion_unique');
            $table->unique(['pre_embarque_id', 'posicion_carga'], 'pre_emb_det_embarque_posicion_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_embarque_empaque_detalles');
        Schema::dropIfExists('pre_embarques_empaque');
    }
};
