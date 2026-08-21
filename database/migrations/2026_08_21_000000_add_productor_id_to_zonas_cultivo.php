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
            $table->foreignId('productor_id')->after('id')->constrained('productores')->nullOnDelete();
            $table->index(['productor_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zonas_cultivo', function (Blueprint $table) {
            $table->dropForeign(['productor_id']);
            $table->dropIndex(['productor_id', 'is_active']);
            $table->dropColumn(['productor_id']);
        });
    }
};
