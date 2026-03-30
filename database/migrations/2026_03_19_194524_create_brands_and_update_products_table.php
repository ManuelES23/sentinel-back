<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Crear tabla de marcas
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Actualizar productos: quitar brand (string) y plu_number, agregar brand_id FK
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['brand', 'plu_number']);
            $table->foreignId('brand_id')->nullable()->after('name')
                ->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
            $table->string('brand', 150)->nullable()->after('name');
            $table->string('plu_number', 50)->nullable()->after('barcode')->index();
        });

        Schema::dropIfExists('brands');
    }
};
