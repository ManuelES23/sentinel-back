<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add manifiesto + genera_manifiesto to embarques_empaque
        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->string('manifiesto', 200)->nullable()->after('folio_embarque');
            $table->boolean('genera_manifiesto')->default(false)->after('manifiesto');
        });

        // Add snapshot columns to embarque_empaque_detalles
        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            $table->string('numero_pallet', 50)->nullable()->after('produccion_id');
            $table->string('folio_produccion', 50)->nullable()->after('numero_pallet');
            $table->string('productor', 200)->nullable()->after('folio_produccion');
            $table->string('variedad', 150)->nullable()->after('productor');
            $table->string('lote', 150)->nullable()->after('variedad');
            $table->string('tipo_empaque', 100)->nullable()->after('lote');
            $table->string('etiqueta', 100)->nullable()->after('tipo_empaque');
            $table->string('calibre', 50)->nullable()->after('etiqueta');
            $table->date('fecha_produccion')->nullable()->after('calibre');
            $table->boolean('is_cola')->default(false)->after('peso_kg');
        });
    }

    public function down(): void
    {
        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->dropColumn(['manifiesto', 'genera_manifiesto']);
        });

        Schema::table('embarque_empaque_detalles', function (Blueprint $table) {
            $table->dropColumn([
                'numero_pallet', 'folio_produccion', 'productor',
                'variedad', 'lote', 'tipo_empaque', 'etiqueta',
                'calibre', 'fecha_produccion', 'is_cola',
            ]);
        });
    }
};
