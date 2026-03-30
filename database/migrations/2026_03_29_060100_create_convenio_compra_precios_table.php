<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_compra_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_compra_id')->constrained('convenios_compra')->cascadeOnDelete();
            $table->foreignId('tipo_carga_id')->nullable()->constrained('tipos_carga')->nullOnDelete();
            $table->decimal('precio_unitario', 12, 4)->nullable();
            $table->decimal('precio_caja_empacada', 12, 4)->nullable();
            $table->decimal('porcentaje_productor', 5, 2)->nullable();
            $table->date('vigencia_inicio');
            $table->date('vigencia_fin')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convenio_compra_precios');
    }
};
