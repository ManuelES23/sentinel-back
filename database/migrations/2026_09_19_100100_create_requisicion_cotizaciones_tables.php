<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicion_cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisicion_campo_id')->constrained('requisiciones_campo')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('folio_proveedor', 60)->nullable();
            $table->date('fecha');
            $table->date('vigencia')->nullable();
            $table->unsignedInteger('dias_entrega')->nullable();
            $table->string('condiciones_pago', 150)->nullable();
            $table->string('archivo_path')->nullable();
            $table->text('notas')->nullable();
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('iva', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->boolean('es_ganadora')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['requisicion_campo_id', 'es_ganadora']);
        });

        Schema::create('requisicion_cotizacion_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('requisicion_cotizaciones')->cascadeOnDelete();
            $table->foreignId('requisicion_detalle_id')->constrained('requisicion_campo_detalles')->cascadeOnDelete();
            $table->boolean('disponible')->default(true);
            $table->decimal('cantidad', 15, 4);
            $table->decimal('precio_unitario', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(16);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['cotizacion_id', 'requisicion_detalle_id'], 'cot_det_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicion_cotizacion_detalles');
        Schema::dropIfExists('requisicion_cotizaciones');
    }
};
