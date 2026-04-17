<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            $table->string('marca', 150)->nullable()->after('lote');
            $table->string('lote_producto_terminado', 100)->nullable()->after('marca');
            $table->string('presentacion', 100)->nullable()->after('lote_producto_terminado');
        });
    }

    public function down(): void
    {
        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            $table->dropColumn(['marca', 'lote_producto_terminado', 'presentacion']);
        });
    }
};
