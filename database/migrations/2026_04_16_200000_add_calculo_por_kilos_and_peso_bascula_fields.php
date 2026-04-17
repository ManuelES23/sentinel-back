<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campo en convenios para indicar cálculo por kilos
        Schema::table('convenios_compra', function (Blueprint $table) {
            $table->boolean('calculo_por_kilos')->default(false)->after('modalidad');
        });

        // Campos de báscula en salidas de campo
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->decimal('peso_bascula', 12, 2)->nullable()->after('peso_neto_kg');
            $table->string('folio_ticket_bascula', 100)->nullable()->after('peso_bascula');
        });

        // Folio de ticket báscula en recepciones (ya tiene peso_bascula)
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->string('folio_ticket_bascula', 100)->nullable()->after('peso_bascula');
        });
    }

    public function down(): void
    {
        Schema::table('convenios_compra', function (Blueprint $table) {
            $table->dropColumn('calculo_por_kilos');
        });

        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->dropColumn(['peso_bascula', 'folio_ticket_bascula']);
        });

        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->dropColumn('folio_ticket_bascula');
        });
    }
};
