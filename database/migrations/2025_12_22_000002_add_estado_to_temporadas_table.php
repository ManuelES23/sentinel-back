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
        Schema::table('temporadas', function (Blueprint $table) {
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta')->after('fecha_fin');
            $table->date('fecha_cierre_real')->nullable()->after('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('temporadas', function (Blueprint $table) {
            $table->dropColumn(['estado', 'fecha_cierre_real']);
        });
    }
};
