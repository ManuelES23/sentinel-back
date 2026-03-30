<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salidas_campo_cosecha', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->date('fecha');
            $table->string('producto', 150);
            $table->decimal('cantidad', 12, 2);
            $table->string('unidad_medida', 50);
            $table->integer('cajas')->nullable();
            $table->decimal('peso_neto_kg', 12, 2)->nullable();
            $table->string('destino', 255)->nullable();
            $table->string('vehiculo', 150)->nullable();
            $table->string('chofer', 150)->nullable();
            $table->string('guia_remision', 100)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('status', 30)->default('registrada');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'fecha']);
            $table->index(['lote_id', 'etapa_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_campo_cosecha');
    }
};
