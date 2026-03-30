<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidacion_consignacion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('liquidaciones_consignacion')->cascadeOnDelete();
            $table->foreignId('salida_campo_id')->nullable()->constrained('salidas_campo_cosecha')->nullOnDelete();
            $table->foreignId('tipo_carga_id')->nullable()->constrained('tipos_carga')->nullOnDelete();

            $table->string('concepto', 255);
            $table->decimal('cantidad', 12, 2)->default(0);
            $table->decimal('peso_kg', 12, 2)->default(0);
            $table->decimal('precio_unitario', 12, 4)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->text('notas')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidacion_consignacion_detalles');
    }
};
