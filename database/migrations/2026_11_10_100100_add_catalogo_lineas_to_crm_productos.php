<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Clave, marca, línea y sublínea del producto; unidad del renglón. Re-ejecutable. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_productos', 'clave')) {
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->string('clave', 60)->nullable();
                $table->string('clave_normalizada', 60)->nullable();
                $table->foreignId('marca_id')->nullable()->constrained('crm_marcas')->nullOnDelete();
                $table->foreignId('linea_id')->nullable()->constrained('crm_lineas')->nullOnDelete();
                $table->foreignId('sublinea_id')->nullable()->constrained('crm_sublineas')->nullOnDelete();
                // Las claves nulas no chocan entre sí (MySQL y SQLite).
                $table->unique(['empresa_id', 'clave_normalizada'], 'crm_productos_empresa_clave_unique');
            });
        }

        if (! Schema::hasColumn('crm_oportunidad_productos', 'unidad')) {
            Schema::table('crm_oportunidad_productos', function (Blueprint $table) {
                $table->string('unidad', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_oportunidad_productos', 'unidad')) {
            Schema::table('crm_oportunidad_productos', function (Blueprint $table) {
                $table->dropColumn('unidad');
            });
        }

        if (Schema::hasColumn('crm_productos', 'clave')) {
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->dropForeign(['marca_id']);
                $table->dropForeign(['linea_id']);
                $table->dropForeign(['sublinea_id']);
                $table->dropUnique('crm_productos_empresa_clave_unique');
                $table->dropColumn(['clave', 'clave_normalizada', 'marca_id', 'linea_id', 'sublinea_id']);
            });
        }
    }
};
