<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            // Hacer etapa_id nullable (productores externos pueden no tener etapas)
            $table->unsignedBigInteger('etapa_id')->nullable()->change();

            // Agregar variedad_id para cuando no hay etapa
            $table->foreignId('variedad_id')
                ->nullable()
                ->after('etapa_id')
                ->constrained('variedades')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->dropForeign(['variedad_id']);
            $table->dropColumn('variedad_id');
            $table->unsignedBigInteger('etapa_id')->nullable(false)->change();
        });
    }
};
