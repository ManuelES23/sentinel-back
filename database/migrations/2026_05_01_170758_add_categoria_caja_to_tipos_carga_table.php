<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tipos_carga', function (Blueprint $table) {
            // Identifier de tipo de caja: campo, empaque, hidrotermico
            $table->enum('categoria_caja', ['campo', 'empaque', 'hidrotermico'])->default('campo')->after('nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tipos_carga', function (Blueprint $table) {
            $table->dropColumn('categoria_caja');
        });
    }
};
