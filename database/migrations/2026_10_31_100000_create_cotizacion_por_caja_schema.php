<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema de la cotización por caja (spec 2026-10-31): calibres propios por
 * producto, catálogo de presentaciones por empresa y commodity, variedad del
 * producto, calibre/presentación en los renglones y el interruptor por empresa.
 * El interruptor nace apagado; solo una migración posterior lo enciende.
 * Re-ejecutable sin duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_producto_calibres')) {
            Schema::create('crm_producto_calibres', function (Blueprint $table) {
                $table->id();
                $table->foreignId('producto_id')->constrained('crm_productos')->cascadeOnDelete();
                $table->string('nombre', 100);
                $table->smallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->unique(['producto_id', 'nombre'], 'crm_producto_calibres_producto_nombre_unique');
            });
        }

        if (! Schema::hasTable('crm_presentaciones')) {
            Schema::create('crm_presentaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('commodity', 100);
                $table->string('nombre', 150);
                $table->smallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                $table->unique(['empresa_id', 'commodity', 'nombre'], 'crm_presentaciones_empresa_commodity_nombre_unique');
            });
        }

        if (! Schema::hasColumn('crm_productos', 'variedad_id')) {
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->foreignId('variedad_id')->nullable()->after('commodity')
                    ->constrained('variedades')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('crm_oportunidad_productos', 'calibre')) {
            Schema::table('crm_oportunidad_productos', function (Blueprint $table) {
                $table->string('calibre', 100)->nullable();
            });
        }
        if (! Schema::hasColumn('crm_oportunidad_productos', 'presentacion')) {
            Schema::table('crm_oportunidad_productos', function (Blueprint $table) {
                $table->string('presentacion', 150)->nullable();
            });
        }

        if (! Schema::hasColumn('crm_configuraciones_comerciales', 'cotizacion_por_caja')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->boolean('cotizacion_por_caja')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_configuraciones_comerciales', 'cotizacion_por_caja')) {
            Schema::table('crm_configuraciones_comerciales', function (Blueprint $table) {
                $table->dropColumn('cotizacion_por_caja');
            });
        }

        foreach (['presentacion', 'calibre'] as $columna) {
            if (Schema::hasColumn('crm_oportunidad_productos', $columna)) {
                Schema::table('crm_oportunidad_productos', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }

        if (Schema::hasColumn('crm_productos', 'variedad_id')) {
            Schema::table('crm_productos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('variedad_id');
            });
        }

        Schema::dropIfExists('crm_presentaciones');
        Schema::dropIfExists('crm_producto_calibres');
    }
};
