<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 - Costeo Agrícola
 *
 * Registro unificado de costos asignados a la jerarquía:
 * Temporada → Lote → Etapa
 *
 * Fuentes: requisición de campo, orden de compra, movimiento de inventario, manual
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costeos_agricolas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('etapas')->nullOnDelete();

            // Fuente polimórfica
            $table->enum('tipo_fuente', [
                'requisicion',
                'orden_compra',
                'movimiento_inventario',
                'manual',
            ]);
            $table->unsignedBigInteger('fuente_id')->nullable();

            // Producto
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('descripcion', 300);
            $table->string('categoria', 100)->nullable(); // fertilizante, agroquímico, mano de obra, etc.

            // Cantidades y costos
            $table->decimal('cantidad', 10, 4)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('costo_unitario', 12, 4)->nullable();
            $table->decimal('costo_total', 14, 2);

            $table->date('fecha');
            $table->foreignId('user_id')->constrained('users');
            $table->text('notas')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'fecha']);
            $table->index(['lote_id', 'etapa_id']);
            $table->index(['tipo_fuente', 'fuente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costeos_agricolas');
    }
};
