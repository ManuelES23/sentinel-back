<?php

use App\Support\Empaque\MarcadoConsumoRecetas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->string('consumo', 20)->default('por_caja')->after('solo_interno');
        });

        MarcadoConsumoRecetas::aplicar();
    }

    /** El marcado de datos no se deshace; solo la columna. */
    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropColumn('consumo');
        });
    }
};
