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
        Schema::table('inventory_kardex', function (Blueprint $table) {
            $table->foreignId('productor_id')
                ->nullable()
                ->after('product_id')
                ->constrained('productores')
                ->nullOnDelete();
            $table->index('productor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_kardex', function (Blueprint $table) {
            $table->dropForeign(['productor_id']);
            $table->dropColumn('productor_id');
        });
    }
};
