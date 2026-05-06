<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_rezaga_empaque', function (Blueprint $table) {
            $table->enum('tipo_salida', ['venta', 'regalia', 'desecho'])
                ->default('venta')
                ->after('fecha_venta');
            $table->string('autorizado_por', 150)->nullable()->after('tipo_salida');
            $table->string('solicitado_por', 150)->nullable()->after('autorizado_por');
            $table->string('chofer', 150)->nullable()->after('solicitado_por');
            $table->string('placa', 50)->nullable()->after('chofer');

            $table->index(['tipo_salida', 'fecha_venta'], 'vrez_tipo_fecha_idx');
        });
    }

    public function down(): void
    {
        Schema::table('venta_rezaga_empaque', function (Blueprint $table) {
            $table->dropIndex('vrez_tipo_fecha_idx');
            $table->dropColumn([
                'tipo_salida',
                'autorizado_por',
                'solicitado_por',
                'chofer',
                'placa',
            ]);
        });
    }
};
