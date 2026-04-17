<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar campos de producto por entrada en detalles
        Schema::table('produccion_empaque_detalles', function (Blueprint $table) {
            $table->foreignId('recipe_id')->nullable()->after('proceso_id')->constrained('recipes')->nullOnDelete();
            $table->string('tipo_empaque', 100)->nullable()->after('recipe_id');
            $table->string('etiqueta', 100)->nullable()->after('tipo_empaque');
            $table->string('calibre', 50)->nullable()->after('etiqueta');
            $table->string('categoria', 50)->nullable()->after('calibre');
        });

        // Agregar flag mixto al pallet padre
        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->boolean('is_mixto')->default(false)->after('is_cola');
        });
    }

    public function down(): void
    {
        Schema::table('produccion_empaque_detalles', function (Blueprint $table) {
            $table->dropForeign(['recipe_id']);
            $table->dropColumn(['recipe_id', 'tipo_empaque', 'etiqueta', 'calibre', 'categoria']);
        });

        Schema::table('produccion_empaque', function (Blueprint $table) {
            $table->dropColumn('is_mixto');
        });
    }
};
