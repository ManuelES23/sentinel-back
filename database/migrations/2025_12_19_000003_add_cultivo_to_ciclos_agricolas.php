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
        Schema::table('ciclos_agricolas', function (Blueprint $table) {
            $table->foreignId('cultivo_id')->after('id')->constrained('cultivos')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ciclos_agricolas', function (Blueprint $table) {
            $table->dropForeign(['cultivo_id']);
            $table->dropColumn('cultivo_id');
        });
    }
};
