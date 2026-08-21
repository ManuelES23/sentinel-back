<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            // Marca manual de compra directa, independiente del convenio —
            // para cultivos/temporadas donde todavía no se manejan convenios
            // de compra (ej. Elote) pero igual necesitan asignar precio y
            // ticket de báscula.
            $table->boolean('es_compra_directa')->default(false)->after('convenio_compra_id');
        });
    }

    public function down(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->dropColumn('es_compra_directa');
        });
    }
};
