<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema base de Abastecimiento (Splendid by Porvenir, spec 2026-11-04):
 * catálogos (cultivos, variedades, tipos de carga, productores/empaques),
 * temporadas, convenios con sus precios y metas semanales, recepciones y
 * el contador de folios. Re-ejecutable sin duplicar (guarda con hasTable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abs_cultivos')) {
            Schema::create('abs_cultivos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('nombre', 100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['enterprise_id', 'nombre'], 'abs_cultivos_empresa_nombre_unique');
            });
        }

        if (! Schema::hasTable('abs_variedades')) {
            Schema::create('abs_variedades', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cultivo_id')->constrained('abs_cultivos')->cascadeOnDelete();
                $table->string('nombre', 100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['cultivo_id', 'nombre'], 'abs_variedades_cultivo_nombre_unique');
            });
        }

        if (! Schema::hasTable('abs_tipos_carga')) {
            Schema::create('abs_tipos_carga', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('nombre', 100);
                $table->decimal('peso_kg', 10, 3)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['enterprise_id', 'nombre'], 'abs_tipos_carga_empresa_nombre_unique');
            });
        }

        if (! Schema::hasTable('abs_productores')) {
            Schema::create('abs_productores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->enum('tipo', ['productor', 'empaque'])->default('productor');
                $table->string('nombre', 150);
                $table->string('rfc', 13)->nullable();
                $table->string('contacto', 120)->nullable();
                $table->string('telefono', 30)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('ubicacion', 150)->nullable();
                $table->text('notas')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['enterprise_id', 'tipo'], 'abs_productores_empresa_tipo_index');
            });
        }

        if (! Schema::hasTable('abs_productor_cultivo')) {
            Schema::create('abs_productor_cultivo', function (Blueprint $table) {
                $table->id();
                $table->foreignId('productor_id')->constrained('abs_productores')->cascadeOnDelete();
                $table->foreignId('cultivo_id')->constrained('abs_cultivos')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['productor_id', 'cultivo_id'], 'abs_productor_cultivo_unique');
            });
        }

        if (! Schema::hasTable('abs_temporadas')) {
            Schema::create('abs_temporadas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->foreignId('cultivo_id')->constrained('abs_cultivos');
                $table->string('nombre', 120);
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->enum('estado', ['planeada', 'activa', 'cerrada'])->default('planeada');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['enterprise_id', 'estado'], 'abs_temporadas_empresa_estado_index');
            });
        }

        if (! Schema::hasTable('abs_convenios')) {
            Schema::create('abs_convenios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('folio', 20);
                $table->foreignId('temporada_id')->constrained('abs_temporadas');
                $table->foreignId('productor_id')->constrained('abs_productores');
                $table->foreignId('cultivo_id')->constrained('abs_cultivos');
                $table->foreignId('variedad_id')->constrained('abs_variedades');
                $table->enum('modalidad', ['compra_directa', 'consignacion']);
                $table->enum('unidad', ['caja', 'kg']);
                $table->enum('moneda', ['MXN', 'USD']);
                $table->decimal('porcentaje_rezaga', 5, 2)->default(0);
                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->enum('modo_volumen', ['total', 'semanal']);
                $table->decimal('volumen_total', 14, 2)->nullable();
                $table->enum('status', ['borrador', 'activo', 'suspendido', 'finalizado', 'cancelado'])->default('borrador');
                $table->text('notas')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['enterprise_id', 'folio'], 'abs_convenios_empresa_folio_unique');
                $table->index('status', 'abs_convenios_status_index');
                $table->index('temporada_id', 'abs_convenios_temporada_index');
                $table->index('productor_id', 'abs_convenios_productor_index');
            });
        }

        if (! Schema::hasTable('abs_convenio_precios')) {
            Schema::create('abs_convenio_precios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('convenio_id')->constrained('abs_convenios')->cascadeOnDelete();
                $table->foreignId('tipo_carga_id')->constrained('abs_tipos_carga');
                $table->decimal('precio', 12, 4);
                $table->decimal('porcentaje_productor', 5, 2)->nullable();
                $table->date('vigencia_inicio');
                $table->boolean('es_principal')->default(false);
                $table->string('notas', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['convenio_id', 'tipo_carga_id', 'vigencia_inicio'], 'abs_convenio_precios_vigencia_unique');
            });
        }

        if (! Schema::hasTable('abs_convenio_metas')) {
            Schema::create('abs_convenio_metas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('convenio_id')->constrained('abs_convenios')->cascadeOnDelete();
                $table->date('semana'); // lunes de la semana ISO
                $table->decimal('cantidad', 14, 2);
                $table->timestamps();
                $table->unique(['convenio_id', 'semana'], 'abs_convenio_metas_semana_unique');
            });
        }

        if (! Schema::hasTable('abs_recepciones')) {
            Schema::create('abs_recepciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('folio', 20);
                $table->foreignId('convenio_id')->constrained('abs_convenios');
                $table->date('fecha');
                $table->foreignId('tipo_carga_id')->constrained('abs_tipos_carga');
                $table->decimal('cantidad', 14, 2);
                $table->decimal('kilos', 14, 2)->nullable();
                $table->decimal('rezaga_kg', 14, 2)->nullable();
                $table->string('ticket_proveedor', 60)->nullable();
                $table->text('notas')->nullable();
                // Liquidación: sin llave foránea en este plan (la tabla llega en el plan 2).
                $table->unsignedBigInteger('liquidacion_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['enterprise_id', 'folio'], 'abs_recepciones_empresa_folio_unique');
                $table->index(['convenio_id', 'fecha'], 'abs_recepciones_convenio_fecha_index');
                $table->index('liquidacion_id', 'abs_recepciones_liquidacion_index');
            });
        }

        if (! Schema::hasTable('abs_folios')) {
            Schema::create('abs_folios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('prefijo', 5);
                $table->unsignedInteger('ultimo')->default(0);
                $table->timestamps();
                $table->unique(['enterprise_id', 'prefijo'], 'abs_folios_empresa_prefijo_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_folios');
        Schema::dropIfExists('abs_recepciones');
        Schema::dropIfExists('abs_convenio_metas');
        Schema::dropIfExists('abs_convenio_precios');
        Schema::dropIfExists('abs_convenios');
        Schema::dropIfExists('abs_temporadas');
        Schema::dropIfExists('abs_productor_cultivo');
        Schema::dropIfExists('abs_productores');
        Schema::dropIfExists('abs_tipos_carga');
        Schema::dropIfExists('abs_variedades');
        Schema::dropIfExists('abs_cultivos');
    }
};
