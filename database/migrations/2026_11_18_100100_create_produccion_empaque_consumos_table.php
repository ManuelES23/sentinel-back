<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produccion_empaque_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id')->constrained('produccion_empaque');
            $table->unsignedBigInteger('detalle_id')->nullable();
            $table->foreignId('entity_id')->constrained('entities');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('recipe_id')->nullable();
            $table->unsignedBigInteger('recipe_item_id')->nullable();
            $table->string('group_key', 50)->nullable();
            $table->string('tipo', 10);
            $table->integer('cajas')->nullable();
            $table->decimal('cantidad', 14, 4);
            $table->decimal('costo_total', 16, 4);
            $table->decimal('cantidad_devuelta', 14, 4)->default(0);
            $table->timestamps();

            $table->index('detalle_id');
            $table->index(['produccion_id', 'detalle_id']);
            $table->index(['entity_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produccion_empaque_consumos');
    }
};
