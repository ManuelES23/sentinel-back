<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de etapas de las oportunidades del CRM (spec 2026-10-08 §4.1).
 * Lo llena CrmOportunidadObserver; las filas "inferido" las crea el comando
 * crm:reconstruir-historial-etapas para los datos anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_oportunidad_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('oportunidad_id')->constrained('crm_oportunidades')->cascadeOnDelete();
            $table->string('etapa_desde', 20)->nullable();
            $table->string('etapa_hasta', 20);
            $table->timestamp('cambiado_en')->nullable();
            // Sin FK: los usuarios se borran de verdad y el historial se conserva.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('inferido')->default(false);
            $table->timestamps();

            $table->index(['empresa_id', 'oportunidad_id']);
            $table->index(['empresa_id', 'etapa_hasta', 'cambiado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_oportunidad_etapas');
    }
};
