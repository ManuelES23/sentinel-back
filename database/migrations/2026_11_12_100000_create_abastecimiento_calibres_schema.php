<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calibres de Abastecimiento (spec 2026-11-12): catálogo de calibres por variedad,
 * la marca `usa_calibres` del convenio, el volumen pactado por calibre y la columna
 * `calibre_id` (nula = convenio clásico) en precios, metas y detalles de liquidación.
 *
 * Los únicos de precios y metas pasan a contar el calibre. Como en un índice único
 * los nulos nunca chocan entre sí, se agrega la columna virtual `calibre_clave`
 * (`ifnull(calibre_id, 0)`) para que dos filas clásicas (calibre nulo) iguales
 * sigan chocando. Re-ejecutable sin duplicar (guarda con hasTable/hasColumn/hasIndex).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abs_variedad_calibres')) {
            Schema::create('abs_variedad_calibres', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variedad_id')->constrained('abs_variedades')->cascadeOnDelete();
                $table->string('nombre', 60);
                $table->unsignedInteger('orden')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['variedad_id', 'nombre'], 'abs_variedad_calibres_variedad_nombre_unique');
            });
        }

        if (! Schema::hasColumn('abs_convenios', 'usa_calibres')) {
            Schema::table('abs_convenios', function (Blueprint $table) {
                $table->boolean('usa_calibres')->default(false)->after('modo_volumen');
            });
        }

        if (! Schema::hasTable('abs_convenio_calibres')) {
            Schema::create('abs_convenio_calibres', function (Blueprint $table) {
                $table->id();
                $table->foreignId('convenio_id')->constrained('abs_convenios')->cascadeOnDelete();
                $table->foreignId('calibre_id')->constrained('abs_variedad_calibres');
                $table->decimal('volumen_total', 14, 2)->nullable();
                $table->timestamps();
                $table->unique(['convenio_id', 'calibre_id'], 'abs_convenio_calibres_unique');
            });
        }

        foreach (['abs_convenio_precios', 'abs_convenio_metas', 'abs_liquidacion_detalles'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'calibre_id')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->unsignedBigInteger('calibre_id')->nullable();
                    $table->foreign('calibre_id')->references('id')->on('abs_variedad_calibres');
                });
            }
        }

        foreach (['abs_convenio_precios', 'abs_convenio_metas'] as $tabla) {
            if (! Schema::hasColumn($tabla, 'calibre_clave')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->unsignedBigInteger('calibre_clave')->virtualAs('ifnull(calibre_id, 0)');
                });
            }
        }

        // Se crea el único nuevo antes de quitar el viejo: en MySQL la llave foránea de
        // convenio_id necesita siempre un índice que empiece por esa columna.
        if (! Schema::hasIndex('abs_convenio_precios', 'abs_convenio_precios_calibre_vigencia_unique')) {
            Schema::table('abs_convenio_precios', function (Blueprint $table) {
                $table->unique(['convenio_id', 'tipo_carga_id', 'calibre_clave', 'vigencia_inicio'], 'abs_convenio_precios_calibre_vigencia_unique');
            });
        }
        if (Schema::hasIndex('abs_convenio_precios', 'abs_convenio_precios_vigencia_unique')) {
            Schema::table('abs_convenio_precios', function (Blueprint $table) {
                $table->dropUnique('abs_convenio_precios_vigencia_unique');
            });
        }

        if (! Schema::hasIndex('abs_convenio_metas', 'abs_convenio_metas_calibre_semana_unique')) {
            Schema::table('abs_convenio_metas', function (Blueprint $table) {
                $table->unique(['convenio_id', 'calibre_clave', 'semana'], 'abs_convenio_metas_calibre_semana_unique');
            });
        }
        if (Schema::hasIndex('abs_convenio_metas', 'abs_convenio_metas_semana_unique')) {
            Schema::table('abs_convenio_metas', function (Blueprint $table) {
                $table->dropUnique('abs_convenio_metas_semana_unique');
            });
        }
    }

    public function down(): void
    {
        // Únicos viejos primero (misma razón que en up()), luego se quitan los nuevos.
        if (! Schema::hasIndex('abs_convenio_metas', 'abs_convenio_metas_semana_unique')) {
            Schema::table('abs_convenio_metas', function (Blueprint $table) {
                $table->unique(['convenio_id', 'semana'], 'abs_convenio_metas_semana_unique');
            });
        }
        if (Schema::hasIndex('abs_convenio_metas', 'abs_convenio_metas_calibre_semana_unique')) {
            Schema::table('abs_convenio_metas', function (Blueprint $table) {
                $table->dropUnique('abs_convenio_metas_calibre_semana_unique');
            });
        }

        if (! Schema::hasIndex('abs_convenio_precios', 'abs_convenio_precios_vigencia_unique')) {
            Schema::table('abs_convenio_precios', function (Blueprint $table) {
                $table->unique(['convenio_id', 'tipo_carga_id', 'vigencia_inicio'], 'abs_convenio_precios_vigencia_unique');
            });
        }
        if (Schema::hasIndex('abs_convenio_precios', 'abs_convenio_precios_calibre_vigencia_unique')) {
            Schema::table('abs_convenio_precios', function (Blueprint $table) {
                $table->dropUnique('abs_convenio_precios_calibre_vigencia_unique');
            });
        }

        foreach (['abs_convenio_precios', 'abs_convenio_metas'] as $tabla) {
            if (Schema::hasColumn($tabla, 'calibre_clave')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropColumn('calibre_clave');
                });
            }
        }

        // SQLite no puede quitar una columna con llave foránea ni la llave sola; solo se
        // usa en pruebas (que parten de cero), así que ahí la columna nula se deja.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            foreach (['abs_liquidacion_detalles', 'abs_convenio_metas', 'abs_convenio_precios'] as $tabla) {
                if (Schema::hasColumn($tabla, 'calibre_id')) {
                    Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                        $table->dropForeign($tabla.'_calibre_id_foreign');
                        $table->dropColumn('calibre_id');
                    });
                }
            }
        }

        Schema::dropIfExists('abs_convenio_calibres');

        if (Schema::hasColumn('abs_convenios', 'usa_calibres')) {
            Schema::table('abs_convenios', function (Blueprint $table) {
                $table->dropColumn('usa_calibres');
            });
        }

        Schema::dropIfExists('abs_variedad_calibres');
    }
};
