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
        Schema::table('lotes', function (Blueprint $table) {
            // Agregar zona_cultivo_id como opcional (nullable)
            $table->foreignId('zona_cultivo_id')
                ->nullable()
                ->after('productor_id')
                ->constrained('zonas_cultivo')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropForeign(['zona_cultivo_id']);
            $table->dropColumn('zona_cultivo_id');
        });
    }
};
