<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas_cosecha', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->onDelete('cascade');
            $table->foreignId('cierre_cosecha_id')->nullable()->constrained('cierres_cosecha')->nullOnDelete();
            $table->date('fecha_venta');
            $table->string('cliente', 200);
            $table->string('producto', 150);
            $table->decimal('cantidad', 12, 2);
            $table->string('unidad_medida', 50);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('total', 14, 2);
            $table->string('moneda', 10)->default('MXN');
            $table->string('factura', 100)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('status', 30)->default('pendiente');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['temporada_id', 'fecha_venta']);
            $table->index('status');
            $table->index('cliente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas_cosecha');
    }
};
