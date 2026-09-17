<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos_aplicacion', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('id')
                ->constrained('products')->nullOnDelete();
        });

        Schema::table('aplicaciones_detalle', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('producto_id')
                ->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('producto_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones_detalle', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::table('productos_aplicacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
