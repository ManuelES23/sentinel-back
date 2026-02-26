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
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            // Eliminar foreign key si existe
            $table->dropForeign(['productor_id']);
            // Eliminar columnas
            $table->dropColumn(['productor_id', 'superficie_total']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->foreignId('productor_id')->nullable()->after('id')->constrained('productores')->nullOnDelete();
            $table->decimal('superficie_total', 10, 2)->nullable()->after('ubicacion');
        });
    }
};
