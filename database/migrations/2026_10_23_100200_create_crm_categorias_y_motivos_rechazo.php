<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de categorías y motivos de rechazo por empresa y la referencia al
 * motivo en la oportunidad (spec 2026-10-23 §4.5). `motivo_perdida` (texto)
 * se conserva. Re-ejecutable sin error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_categorias_rechazo')) {
            Schema::create('crm_categorias_rechazo', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
                $table->string('nombre', 100);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['empresa_id', 'nombre']);
                $table->index(['empresa_id', 'activo']);
            });
        }

        if (! Schema::hasTable('crm_motivos_rechazo')) {
            Schema::create('crm_motivos_rechazo', function (Blueprint $table) {
                $table->id();
                $table->foreignId('categoria_id')->constrained('crm_categorias_rechazo')->onDelete('cascade');
                $table->string('nombre', 100);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activo')->default(true);
                $table->timestamps();

                $table->unique(['categoria_id', 'nombre']);
            });
        }

        if (! Schema::hasColumn('crm_oportunidades', 'motivo_rechazo_id')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->foreignId('motivo_rechazo_id')
                    ->nullable()
                    ->after('motivo_perdida')
                    ->constrained('crm_motivos_rechazo')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_oportunidades', 'motivo_rechazo_id')) {
            Schema::table('crm_oportunidades', function (Blueprint $table) {
                $table->dropForeign(['motivo_rechazo_id']);
                $table->dropColumn('motivo_rechazo_id');
            });
        }

        Schema::dropIfExists('crm_motivos_rechazo');
        Schema::dropIfExists('crm_categorias_rechazo');
    }
};
