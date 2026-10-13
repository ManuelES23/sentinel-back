<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plantillas de mensaje de WhatsApp por empresa (CRM · WhatsApp Fase 0, spec §4.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_plantillas_whatsapp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->string('nombre', 100);
            $table->text('cuerpo');
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_plantillas_whatsapp');
    }
};
