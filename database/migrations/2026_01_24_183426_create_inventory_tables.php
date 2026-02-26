<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Categorías de productos (jerarquía)
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('icon', 100)->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Unidades de medida
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('abbreviation', 20);
            $table->string('type', 50)->default('unit'); // unit, weight, volume, length, area, time, other
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->foreignId('base_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->tinyInteger('precision')->default(2); // decimales a mostrar
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Productos/Artículos
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('sku', 100)->unique()->nullable();
            $table->string('barcode', 100)->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            
            // Tipo de producto
            $table->enum('product_type', ['product', 'service', 'raw_material', 'finished_good', 'consumable'])->default('product');
            
            // Control de inventario
            $table->boolean('track_inventory')->default(true);
            $table->boolean('track_lots')->default(false);
            $table->boolean('track_serials')->default(false);
            $table->boolean('track_expiry')->default(false);
            
            // Stock
            $table->decimal('min_stock', 15, 4)->default(0);
            $table->decimal('max_stock', 15, 4)->nullable();
            $table->decimal('reorder_point', 15, 4)->nullable();
            $table->decimal('reorder_quantity', 15, 4)->nullable();
            
            // Costos y precios
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->string('cost_method', 20)->default('average'); // average, fifo, lifo, specific
            
            // Imagen y otros
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Tipos de movimiento
        Schema::create('movement_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('direction', ['in', 'out', 'transfer', 'adjustment']);
            $table->enum('effect', ['increase', 'decrease', 'neutral']); // efecto en inventario
            $table->boolean('requires_source_entity')->default(false);
            $table->boolean('requires_destination_entity')->default(false);
            $table->boolean('is_system')->default(false); // tipos del sistema no se pueden eliminar
            $table->string('color', 50)->nullable();
            $table->string('icon', 50)->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Movimientos de inventario (cabecera)
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 50)->unique();
            $table->foreignId('movement_type_id')->constrained('movement_types');
            $table->date('movement_date');
            
            // Origen (polimórfico - puede ser entidad, almacén, etc.)
            $table->unsignedBigInteger('source_entity_id')->nullable();
            $table->string('source_entity_type', 100)->nullable();
            $table->unsignedBigInteger('source_area_id')->nullable();
            
            // Destino (polimórfico)
            $table->unsignedBigInteger('destination_entity_id')->nullable();
            $table->string('destination_entity_type', 100)->nullable();
            $table->unsignedBigInteger('destination_area_id')->nullable();
            
            // Referencia externa (orden de compra, venta, etc.)
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 100)->nullable();
            
            // Descripción y notas
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            
            // Estado y aprobación
            $table->enum('status', ['draft', 'pending', 'approved', 'completed', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            
            // Totales
            $table->decimal('total_quantity', 15, 4)->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['movement_date', 'movement_type_id']);
            $table->index(['status', 'movement_date']);
        });

        // Detalle de movimientos
        Schema::create('inventory_movement_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            
            // Cantidad y unidad
            $table->decimal('quantity', 15, 4);
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->decimal('base_quantity', 15, 4); // cantidad en unidad base
            
            // Lote/Serie (opcional)
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            
            // Costos
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            
            // Ubicación específica
            $table->foreignId('source_area_id')->nullable();
            $table->foreignId('destination_area_id')->nullable();
            
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Índices
            $table->index(['product_id', 'lot_number']);
        });

        // Stock actual por ubicación
        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            
            // Cantidad disponible
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('reserved_quantity', 15, 4)->default(0);
            $table->decimal('available_quantity', 15, 4)->storedAs('quantity - reserved_quantity');
            
            // Lote/Serie
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            
            // Costo
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            
            // Última actualización
            $table->timestamp('last_movement_at')->nullable();
            $table->foreignId('last_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            
            $table->timestamps();
            
            // Índice único para evitar duplicados
            $table->unique(['product_id', 'entity_id', 'area_id', 'lot_number'], 'inventory_stock_unique');
            $table->index(['entity_id', 'area_id']);
        });

        // Kardex (historial de movimientos por producto)
        Schema::create('inventory_kardex', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->foreignId('movement_id')->constrained('inventory_movements');
            
            $table->date('movement_date');
            $table->string('document_number', 50);
            $table->enum('transaction_type', ['increase', 'decrease']);
            $table->text('description')->nullable();
            
            // Cantidades
            $table->decimal('quantity', 15, 4);
            $table->decimal('balance_quantity', 15, 4);
            
            // Costos
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            $table->decimal('balance_value', 15, 4)->default(0);
            
            // Lote/Serie
            $table->string('lot_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            
            $table->timestamps();
            
            // Índices
            $table->index(['product_id', 'entity_id', 'movement_date']);
            $table->index(['movement_date', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_kardex');
        Schema::dropIfExists('inventory_stock');
        Schema::dropIfExists('inventory_movement_details');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('movement_types');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units_of_measure');
        Schema::dropIfExists('product_categories');
    }
};