<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ajuste_peso_rezaga', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('rezaga_empaque_id')->constrained('rezaga_empaque')->cascadeOnDelete();
            $table->string('folio_ajuste', 30)->unique();
            $table->date('fecha_ajuste');
            $table->decimal('kg_antes', 12, 2)->comment('Kg de la rezaga antes del ajuste');
            $table->decimal('kg_despues', 12, 2)->comment('Kg de la rezaga después del ajuste');
            $table->decimal('kg_perdido', 12, 2)->storedAs('kg_antes - kg_despues')->comment('Pérdida calculada');
            $table->enum('motivo', ['deshidratacion', 'putrefaccion', 'merma_natural', 'otro'])
                  ->default('merma_natural');
            $table->string('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'entity_id']);
            $table->index('rezaga_empaque_id');
            $table->index('fecha_ajuste');
            $table->index('folio_ajuste');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajuste_peso_rezaga');
    }
};
