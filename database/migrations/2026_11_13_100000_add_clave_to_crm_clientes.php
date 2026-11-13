<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave del cliente en el sistema de origen de la empresa (columna «Clave» del
 * Excel de clientes). Sirve para reconocer al cliente al volver a importar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('crm_clientes', 'clave')) {
            return;
        }

        Schema::table('crm_clientes', function (Blueprint $table) {
            $table->string('clave', 30)->nullable()->after('empresa_id');
            $table->index(['empresa_id', 'clave']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('crm_clientes', 'clave')) {
            return;
        }

        Schema::table('crm_clientes', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'clave']);
            $table->dropColumn('clave');
        });
    }
};
