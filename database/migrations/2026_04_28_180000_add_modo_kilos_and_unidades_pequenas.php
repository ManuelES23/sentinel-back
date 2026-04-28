<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activa el "modo kilos" en proceso_empaque y permite registrar las
     * cajas pequeñas usadas dentro del hidrotérmico en rezaga_empaque.
     *
     * Cuando entity.usa_hidrotermico = true y la recepción trae peso_bascula,
     * los procesos posteriores (lavado/hidrotérmico/enfriado/rezaga) operan
     * en kilogramos. La cantidad de cajas chicas queda como dato informativo.
     */
    public function up(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->boolean('modo_kilos')
                ->default(false)
                ->after('peso_disponible_kg')
                ->comment('Si true, peso_disponible_kg es la fuente de verdad y cantidad_disponible es referencia');
            $table->index('modo_kilos');
        });

        Schema::table('rezaga_empaque', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_unidades_pequenas')
                ->nullable()
                ->after('cantidad_kg')
                ->comment('Cajas pequeñas usadas para meter al hidrotérmico (informativo)');
        });
    }

    public function down(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->dropIndex(['modo_kilos']);
            $table->dropColumn('modo_kilos');
        });

        Schema::table('rezaga_empaque', function (Blueprint $table) {
            $table->dropColumn('cantidad_unidades_pequenas');
        });
    }
};
