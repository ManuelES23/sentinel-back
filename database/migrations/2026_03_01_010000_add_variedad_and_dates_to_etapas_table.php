<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etapas', function (Blueprint $table) {
            $table->foreignId('variedad_id')
                ->nullable()
                ->after('superficie')
                ->constrained('variedades')
                ->nullOnDelete();

            $table->foreignId('tipo_variedad_id')
                ->nullable()
                ->after('variedad_id')
                ->constrained('tipos_variedad')
                ->nullOnDelete();

            $table->date('fecha_siembra_estimada')
                ->nullable()
                ->after('tipo_variedad_id');

            $table->date('fecha_cosecha_estimada')
                ->nullable()
                ->after('fecha_siembra_estimada');

            $table->date('fecha_siembra_real')
                ->nullable()
                ->after('fecha_cosecha_estimada');

            $table->date('fecha_cosecha_proyectada')
                ->nullable()
                ->after('fecha_siembra_real');
        });
    }

    public function down(): void
    {
        Schema::table('etapas', function (Blueprint $table) {
            $table->dropForeign(['variedad_id']);
            $table->dropForeign(['tipo_variedad_id']);
            $table->dropColumn([
                'variedad_id',
                'tipo_variedad_id',
                'fecha_siembra_estimada',
                'fecha_cosecha_estimada',
                'fecha_siembra_real',
                'fecha_cosecha_proyectada',
            ]);
        });
    }
};
