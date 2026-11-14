<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Interruptor por empresa del catálogo por líneas; nace apagado. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_configuraciones_comerciales', 'catalogo_por_lineas')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->boolean('catalogo_por_lineas')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_configuraciones_comerciales', 'catalogo_por_lineas')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->dropColumn('catalogo_por_lineas');
            });
        }
    }
};
