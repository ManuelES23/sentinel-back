<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema del dinero de Abastecimiento (plan 2): liquidaciones con su detalle y
 * su cadena de aprobación, y abonos con sus aplicaciones. Re-ejecutable sin
 * duplicar (guarda con hasTable). `abs_recepciones.liquidacion_id` ya existe
 * desde el plan 1 y se queda sin llave foránea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abs_liquidaciones')) {
            Schema::create('abs_liquidaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('folio', 20);
                $table->foreignId('convenio_id')->constrained('abs_convenios');
                $table->foreignId('productor_id')->constrained('abs_productores');
                $table->enum('moneda', ['MXN', 'USD']);
                $table->date('periodo_inicio');
                $table->date('periodo_fin');
                $table->decimal('cantidad', 14, 2)->default(0);
                $table->decimal('kilos', 14, 2)->default(0);
                $table->decimal('bruto', 14, 2)->default(0);
                $table->decimal('rezaga_real_pct', 7, 4)->default(0);
                $table->decimal('rezaga_aceptada_pct', 5, 2)->default(0);
                $table->decimal('descuento', 14, 2)->default(0);
                $table->decimal('monto_calculado', 14, 2)->default(0);
                $table->decimal('monto_ajustado', 14, 2)->nullable();
                $table->text('motivo_ajuste')->nullable();
                $table->decimal('monto_final', 14, 2)->default(0);
                $table->enum('status', ['borrador', 'en_revision', 'aprobada', 'pagada', 'cancelada'])->default('borrador');
                $table->enum('origen', ['normal', 'carga_inicial'])->default('normal');
                $table->unsignedInteger('paso_actual')->nullable();
                $table->unsignedInteger('ronda')->default(0);
                $table->timestamp('enviada_at')->nullable();
                $table->foreignId('enviada_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('aprobada_at')->nullable();
                $table->foreignId('aprobada_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('rechazo_motivo')->nullable();
                $table->timestamp('rechazada_at')->nullable();
                $table->foreignId('rechazada_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelada_at')->nullable();
                $table->foreignId('cancelada_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancelada_motivo')->nullable();
                $table->text('notas')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                // Ids de todos los usuarios que crearon, editaron o enviaron la liquidación: ninguno la decide.
                $table->json('intervinieron')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['enterprise_id', 'folio'], 'abs_liquidaciones_empresa_folio_unique');
                $table->index('status', 'abs_liquidaciones_status_index');
                $table->index('convenio_id', 'abs_liquidaciones_convenio_index');
                $table->index('productor_id', 'abs_liquidaciones_productor_index');
            });
        }

        // Una tabla ya creada antes de agregar la columna (solo en una BD local) la recibe aquí.
        if (! Schema::hasColumn('abs_liquidaciones', 'intervinieron')) {
            Schema::table('abs_liquidaciones', function (Blueprint $table) {
                $table->json('intervinieron')->nullable()->after('created_by');
            });
        }

        if (! Schema::hasTable('abs_liquidacion_detalles')) {
            Schema::create('abs_liquidacion_detalles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('liquidacion_id')->constrained('abs_liquidaciones')->cascadeOnDelete();
                $table->foreignId('recepcion_id')->constrained('abs_recepciones');
                $table->decimal('cantidad', 14, 2);
                $table->decimal('kilos', 14, 2)->nullable();
                $table->decimal('rezaga_kg', 14, 2)->nullable();
                $table->decimal('precio', 12, 4);
                $table->decimal('porcentaje_productor', 5, 2)->default(100);
                $table->decimal('subtotal', 14, 2);
                $table->decimal('descuento', 14, 2)->default(0);
                $table->decimal('a_pagar', 14, 2);
                $table->timestamps();
                $table->index('liquidacion_id', 'abs_liquidacion_detalles_liquidacion_index');
                $table->index('recepcion_id', 'abs_liquidacion_detalles_recepcion_index');
            });
        }

        if (! Schema::hasTable('abs_liquidacion_aprobaciones')) {
            Schema::create('abs_liquidacion_aprobaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('liquidacion_id')->constrained('abs_liquidaciones')->cascadeOnDelete();
                $table->unsignedInteger('ronda');
                $table->unsignedInteger('step_order');
                $table->string('description', 255)->nullable();
                $table->json('rules')->nullable();
                $table->enum('decision', ['waiting', 'approved', 'rejected', 'skipped'])->default('waiting');
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('comment')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['liquidacion_id', 'ronda'], 'abs_liquidacion_aprobaciones_ronda_index');
            });
        }

        if (! Schema::hasTable('abs_abonos')) {
            Schema::create('abs_abonos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
                $table->string('folio', 20);
                $table->foreignId('productor_id')->constrained('abs_productores');
                $table->foreignId('convenio_id')->nullable()->constrained('abs_convenios');
                $table->date('fecha');
                $table->decimal('monto', 14, 2);
                $table->enum('moneda', ['MXN', 'USD']);
                $table->string('metodo_pago', 20)->nullable();
                $table->string('referencia', 120)->nullable();
                $table->text('notas')->nullable();
                $table->enum('status', ['aplicado', 'cancelado'])->default('aplicado');
                $table->text('cancelado_motivo')->nullable();
                $table->foreignId('cancelado_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelado_at')->nullable();
                $table->json('aplicaciones_canceladas')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['enterprise_id', 'folio'], 'abs_abonos_empresa_folio_unique');
                $table->index(['productor_id', 'status'], 'abs_abonos_productor_status_index');
            });
        }

        if (! Schema::hasTable('abs_abono_aplicaciones')) {
            Schema::create('abs_abono_aplicaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('abono_id')->constrained('abs_abonos')->cascadeOnDelete();
                $table->foreignId('liquidacion_id')->constrained('abs_liquidaciones');
                $table->decimal('monto', 14, 2);
                $table->timestamps();
                $table->index('abono_id', 'abs_abono_aplicaciones_abono_index');
                $table->index('liquidacion_id', 'abs_abono_aplicaciones_liquidacion_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('abs_abono_aplicaciones');
        Schema::dropIfExists('abs_abonos');
        Schema::dropIfExists('abs_liquidacion_aprobaciones');
        Schema::dropIfExists('abs_liquidacion_detalles');
        Schema::dropIfExists('abs_liquidaciones');
    }
};
