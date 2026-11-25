<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo vendido por renglón, ajustes posteriores a la venta y versiones de la
 * cotización (spec 2026-11-25 §5, solo Splendid by Porvenir). Re-ejecutable
 * sin duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_cotizacion_versiones')) {
            Schema::create('crm_cotizacion_versiones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cotizacion_id')->constrained('crm_cotizaciones')->cascadeOnDelete();
                $table->unsignedInteger('numero');
                $table->json('renglones');
                $table->decimal('descuento_global_pct', 5, 2)->default(0);
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('motivo', 500)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->unique(['cotizacion_id', 'numero'], 'crm_cotizacion_versiones_cotizacion_numero_unique');
            });
        }

        if (! Schema::hasTable('crm_oportunidad_ventas')) {
            Schema::create('crm_oportunidad_ventas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('enterprises')->cascadeOnDelete();
                $table->foreignId('oportunidad_id')->constrained('crm_oportunidades')->cascadeOnDelete();
                $table->foreignId('cotizacion_id')->constrained('crm_cotizaciones')->cascadeOnDelete();
                $table->boolean('vigente')->default(true);
                $table->decimal('descuento_global_pct', 5, 2)->default(0);
                $table->foreignId('registrada_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['oportunidad_id', 'vigente'], 'crm_oportunidad_ventas_oportunidad_vigente_index');
            });
        }

        if (! Schema::hasTable('crm_oportunidad_venta_renglones')) {
            Schema::create('crm_oportunidad_venta_renglones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('venta_id')->constrained('crm_oportunidad_ventas')->cascadeOnDelete();
                $table->foreignId('cotizacion_linea_id')->nullable()
                    ->constrained('crm_oportunidad_productos')->nullOnDelete();
                $table->foreignId('producto_id')->nullable()->constrained('crm_productos')->nullOnDelete();
                $table->string('descripcion', 255);
                $table->string('calibre', 100)->nullable();
                $table->string('presentacion', 150)->nullable();
                $table->decimal('cantidad_cotizada', 14, 4)->default(0);
                $table->decimal('precio_cotizado', 12, 2)->default(0);
                $table->boolean('vendido')->default(false);
                $table->unsignedInteger('cantidad_vendida')->nullable();
                $table->decimal('precio_vendido', 12, 2)->nullable();
                $table->foreignId('motivo_rechazo_id')->nullable()
                    ->constrained('crm_motivos_rechazo')->nullOnDelete();
                $table->text('nota')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_oportunidad_ajustes')) {
            Schema::create('crm_oportunidad_ajustes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('enterprises')->cascadeOnDelete();
                $table->foreignId('oportunidad_id')->constrained('crm_oportunidades')->cascadeOnDelete();
                $table->foreignId('venta_renglon_id')->constrained('crm_oportunidad_venta_renglones')->cascadeOnDelete();
                $table->string('tipo', 20); // rechazo | precio
                $table->unsignedInteger('cajas')->nullable();
                $table->decimal('precio_nuevo', 12, 2)->nullable();
                $table->decimal('importe', 12, 2); // con signo: negativo si baja
                $table->foreignId('motivo_rechazo_id')->nullable()
                    ->constrained('crm_motivos_rechazo')->nullOnDelete();
                $table->text('nota')->nullable();
                $table->date('fecha');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('anulado_at')->nullable();
                $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->text('motivo_anulacion')->nullable();
                $table->timestamps();
                $table->index(['oportunidad_id', 'anulado_at'], 'crm_oportunidad_ajustes_oportunidad_anulado_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_oportunidad_ajustes');
        Schema::dropIfExists('crm_oportunidad_venta_renglones');
        Schema::dropIfExists('crm_oportunidad_ventas');
        Schema::dropIfExists('crm_cotizacion_versiones');
    }
};
