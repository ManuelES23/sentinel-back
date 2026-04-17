<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recepciones: peso báscula, clave WE, lote de origen
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->decimal('peso_bascula', 12, 2)->nullable()->after('peso_recibido_kg');
            $table->string('clave_we', 100)->nullable()->after('peso_bascula');
            $table->string('lote_origen', 100)->nullable()->after('clave_we');
        });

        // Producción: lote de producto terminado
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->string('lote_producto_terminado', 100)->nullable()->after('numero_pallet');
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->dropColumn(['peso_bascula', 'clave_we', 'lote_origen']);
        });
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn('lote_producto_terminado');
        });
    }
};
