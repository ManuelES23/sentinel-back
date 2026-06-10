<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rezaga_empaque', function (Blueprint $table) {
            if (!Schema::hasColumn('rezaga_empaque', 'modo_registro')) {
                $table->enum('modo_registro', ['general', 'tarima'])
                    ->default('general')
                    ->after('cantidad_kg');
            }

            if (!Schema::hasColumn('rezaga_empaque', 'tipo_carga_id')) {
                $table->foreignId('tipo_carga_id')
                    ->nullable()
                    ->after('modo_registro')
                    ->constrained('tipos_carga')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('rezaga_empaque', 'total_cajas')) {
                $table->integer('total_cajas')->nullable()->after('tipo_carga_id');
            }

            if (!Schema::hasColumn('rezaga_empaque', 'peso_bascula_kg')) {
                $table->decimal('peso_bascula_kg', 12, 2)->nullable()->after('total_cajas');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rezaga_empaque', function (Blueprint $table) {
            if (Schema::hasColumn('rezaga_empaque', 'tipo_carga_id')) {
                $table->dropConstrainedForeignId('tipo_carga_id');
            }
            if (Schema::hasColumn('rezaga_empaque', 'peso_bascula_kg')) {
                $table->dropColumn('peso_bascula_kg');
            }
            if (Schema::hasColumn('rezaga_empaque', 'total_cajas')) {
                $table->dropColumn('total_cajas');
            }
            if (Schema::hasColumn('rezaga_empaque', 'modo_registro')) {
                $table->dropColumn('modo_registro');
            }
        });
    }
};
