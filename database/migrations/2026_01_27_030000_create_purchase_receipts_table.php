<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tablas de Recepciones de Mercancía - En inventory/compras
     * Las recepciones generan movimientos de inventario automáticamente
     */
    public function up(): void
    {
        // Recepciones de Mercancía (cabecera)
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            
            // Identificación
            $table->string('receipt_number', 50)->unique();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            
            // Fechas
            $table->date('receipt_date');
            $table->string('supplier_document', 100)->nullable();  // # factura del proveedor
            $table->date('supplier_document_date')->nullable();
            
            // Estado
            $table->enum('status', [
                'draft',        // Borrador
                'pending',      // Pendiente de validación
                'completed',    // Completada (inventario actualizado)
                'cancelled'     // Cancelada
            ])->default('draft');
            
            // Relaciones con otros módulos
            $table->foreignId('inventory_movement_id')->nullable()
                ->constrained('inventory_movements')->nullOnDelete();
            
            // Ubicación de recepción
            $table->unsignedBigInteger('warehouse_id')->nullable();  // Almacén destino
            $table->string('warehouse_type', 100)->nullable();
            
            // Totales
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            
            // Notas
            $table->text('notes')->nullable();
            $table->text('quality_notes')->nullable();  // Notas de calidad
            
            // Auditoría
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['receipt_date', 'status']);
            $table->index(['supplier_id', 'status']);
        });

        // Detalle de Recepciones
        Schema::create('purchase_receipt_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_detail_id')->nullable()
                ->constrained('purchase_order_details')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products');
            
            // Cantidades
            $table->decimal('quantity_ordered', 15, 4)->default(0);   // Lo que decía la OC
            $table->decimal('quantity_received', 15, 4);              // Lo que realmente llegó
            $table->decimal('quantity_accepted', 15, 4)->default(0);  // Lo aceptado (puede ser menos por calidad)
            $table->decimal('quantity_rejected', 15, 4)->default(0);  // Rechazado
            
            // Unidad de medida
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            
            // Precios (copiados de la OC o ingresados)
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('tax_rate', 5, 2)->default(16);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            
            // Trazabilidad
            $table->string('lot_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('serial_number', 100)->nullable();
            
            // Calidad
            $table->enum('quality_status', ['pending', 'approved', 'rejected', 'partial'])->default('pending');
            $table->text('quality_notes')->nullable();
            
            // Notas
            $table->text('notes')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_details');
        Schema::dropIfExists('purchase_receipts');
    }
};
