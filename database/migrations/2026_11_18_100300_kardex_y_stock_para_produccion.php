<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las filas de kardex de producción de Empaque no tienen movimiento de inventario.
        Schema::table('inventory_kardex', function (Blueprint $table) {
            $table->unsignedBigInteger('movement_id')->nullable()->change();
        });

        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->timestamp('negativo_desde')->nullable()->after('last_movement_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->dropColumn('negativo_desde');
        });
        // movement_id se queda nullable: volver a NOT NULL fallaría con filas de producción.
    }
};
