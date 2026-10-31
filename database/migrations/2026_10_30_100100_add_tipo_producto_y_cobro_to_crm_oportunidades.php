<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de producto (texto libre) y estado de cobro de la oportunidad (spec
 * 2026-10-30 §6). `estado_cobro` es nulo hasta que la oportunidad se gana
 * (ver CrmOportunidadObserver); sus valores válidos son
 * CrmOportunidad::ESTADOS_COBRO. Re-ejecutable sin error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_oportunidades', 'tipo_producto')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->string('tipo_producto', 100)->nullable();
            });
        }

        if (! Schema::hasColumn('crm_oportunidades', 'estado_cobro')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->string('estado_cobro', 20)->nullable();
                $table->timestamp('cobro_actualizado_at')->nullable();
                $table->foreignId('cobro_actualizado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['empresa_id', 'estado_cobro']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_oportunidades', 'estado_cobro')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->dropIndex(['empresa_id', 'estado_cobro']);
                $table->dropForeign(['cobro_actualizado_por']);
                $table->dropColumn(['estado_cobro', 'cobro_actualizado_at', 'cobro_actualizado_por']);
            });
        }

        if (Schema::hasColumn('crm_oportunidades', 'tipo_producto')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->dropColumn('tipo_producto');
            });
        }
    }
};
