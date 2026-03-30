<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Porcentaje de rezaga en convenios (descuento por producto rechazado)
        Schema::table('convenios_compra', function (Blueprint $table) {
            $table->decimal('porcentaje_rezaga', 5, 2)->default(0)->after('notas')
                ->comment('Porcentaje de rezaga aceptada para descontar del total');
        });

        // Campos de rezaga en liquidaciones
        Schema::table('liquidaciones_consignacion', function (Blueprint $table) {
            $table->decimal('porcentaje_rezaga_aplicado', 5, 2)->default(0)->after('monto_bruto');
            $table->decimal('descuento_rezaga', 14, 2)->default(0)->after('porcentaje_rezaga_aplicado');
        });
    }

    public function down(): void
    {
        Schema::table('convenios_compra', function (Blueprint $table) {
            $table->dropColumn('porcentaje_rezaga');
        });
        Schema::table('liquidaciones_consignacion', function (Blueprint $table) {
            $table->dropColumn(['porcentaje_rezaga_aplicado', 'descuento_rezaga']);
        });
    }
};
