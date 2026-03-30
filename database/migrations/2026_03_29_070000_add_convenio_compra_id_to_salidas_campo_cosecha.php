<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->foreignId('convenio_compra_id')
                ->nullable()
                ->after('variedad_id')
                ->constrained('convenios_compra')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->dropForeign(['convenio_compra_id']);
            $table->dropColumn('convenio_compra_id');
        });
    }
};
