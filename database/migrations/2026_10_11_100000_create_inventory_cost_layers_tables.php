<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('lot_number')->nullable();
            $table->dateTime('fecha_entrada');
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('cantidad_inicial', 15, 4);
            $table->decimal('cantidad_restante', 15, 4);
            $table->string('origen', 20);
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('movement_detail_id')->nullable()->constrained('inventory_movement_details')->nullOnDelete();
            $table->foreignId('capa_origen_id')->nullable()->constrained('inventory_cost_layers')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'entity_id', 'lot_number', 'cantidad_restante'], 'icl_cubeta_restante');
            $table->index(['fecha_entrada', 'id'], 'icl_orden_peps');
        });

        Schema::create('inventory_cost_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained('inventory_cost_layers')->cascadeOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->foreignId('movement_detail_id')->nullable()->constrained('inventory_movement_details')->nullOnDelete();
            $table->string('referencia')->nullable()->index();
            $table->decimal('cantidad', 15, 4);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('cantidad_devuelta', 15, 4)->default(0);
            $table->timestamps();

            $table->index('movement_detail_id', 'iclc_detalle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layer_consumptions');
        Schema::dropIfExists('inventory_cost_layers');
    }
};
