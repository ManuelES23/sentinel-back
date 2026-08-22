<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('oportunidad_id')->constrained('crm_oportunidades')->onDelete('cascade');
            $table->string('folio')->unique();
            $table->enum('estado', ['borrador', 'enviado', 'aprobado', 'rechazado', 'superado'])->default('borrador');
            $table->date('fecha_emision');
            $table->integer('vigencia_dias')->nullable();
            $table->decimal('descuento_global_pct', 5, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('oportunidad_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_cotizaciones');
    }
};
