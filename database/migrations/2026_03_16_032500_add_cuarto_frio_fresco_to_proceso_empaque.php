<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_cuarto_frio')->default(0)->after('cantidad_disponible');
            $table->unsignedInteger('cantidad_fresco')->default(0)->after('cantidad_cuarto_frio');
        });
    }

    public function down(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->dropColumn(['cantidad_cuarto_frio', 'cantidad_fresco']);
        });
    }
};
