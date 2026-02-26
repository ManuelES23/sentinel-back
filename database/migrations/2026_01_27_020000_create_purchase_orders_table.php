<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tablas de Órdenes de Compra - En inventory/compras
     */
    public function up(): void
    {
        // Órdenes de Compra (cabecera)
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            
            // Identificación
            $table->string('order_number', 50)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            
            // Fechas
            $table->date('order_date');
            $table->date('expected_date')->nullable();       // Fecha esperada de entrega
            $table->date('expiry_date')->nullable();         // Fecha de vencimiento de la OC
            
            // Estado
            $table->enum('status', [
                'draft',        // Borrador
                'pending',      // Pendiente de aprobación
                'approved',     // Aprobada
                'sent',         // Enviada al proveedor
                'confirmed',    // Confirmada por proveedor
                'partial',      // Recibida parcialmente
                'completed',    // Recibida completamente
                'cancelled'     // Cancelada
            ])->default('draft');
            
            // Moneda y tipo de cambio
            $table->string('currency_code', 3)->default('MXN');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            
            // Totales
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            
            // Condiciones
            $table->integer('payment_terms')->nullable();    // Días de crédito
            $table->text('payment_conditions')->nullable();  // Condiciones especiales
            
            // Entrega
            $table->text('shipping_address')->nullable();
            $table->string('shipping_method', 100)->nullable();
            $table->text('delivery_instructions')->nullable();
            
            // Notas
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            
            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['order_date', 'status']);
            $table->index(['supplier_id', 'status']);
            $table->index('status');
        });

        // Detalle de Órdenes de Compra
        Schema::create('purchase_order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            
            // Cantidades
            $table->decimal('quantity_ordered', 15, 4);
            $table->decimal('quantity_received', 15, 4)->default(0);
            $table->decimal('quantity_pending', 15, 4)->storedAs('quantity_ordered - quantity_received');
            
            // Unidad de medida
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            
            // Precios
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(16);   // IVA por defecto 16%
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);
            
            // Fechas específicas por línea
            $table->date('expected_date')->nullable();
            
            // Notas
            $table->text('notes')->nullable();
            
            // Control
            $table->integer('line_number')->default(0);
            
            $table->timestamps();
            
            // Índice para evitar duplicados
            $table->unique(['purchase_order_id', 'product_id', 'line_number'], 'po_product_line_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_details');
        Schema::dropIfExists('purchase_orders');
    }
};
