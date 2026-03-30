<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calidad_cosecha', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('salida_campo_cosecha_id')->nullable()->constrained('salidas_campo_cosecha')->nullOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->date('fecha_inspeccion');
            $table->string('tipo_inspeccion', 50);
            $table->json('parametros')->nullable();
            $table->string('resultado', 30);
            $table->decimal('porcentaje_calidad', 5, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('inspector', 150)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'fecha_inspeccion']);
            $table->index('resultado');
            $table->index('salida_campo_cosecha_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calidad_cosecha');
    }
};
