<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_cosecha', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_cierre')->nullable();
            $table->integer('total_cajas')->nullable();
            $table->decimal('total_peso_kg', 12, 2)->nullable();
            $table->decimal('rendimiento_hectarea', 10, 2)->nullable();
            $table->decimal('superficie_cosechada', 10, 4)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('status', 30)->default('abierto');
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'status']);
            $table->index(['lote_id', 'etapa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_cosecha');
    }
};
