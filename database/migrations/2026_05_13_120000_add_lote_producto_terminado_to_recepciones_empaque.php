<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->string('lote_producto_terminado', 100)
                ->nullable()
                ->after('lote_origen');
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->dropColumn('lote_producto_terminado');
        });
    }
};
