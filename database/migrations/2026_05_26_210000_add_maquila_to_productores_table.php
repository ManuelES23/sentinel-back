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
        if (!Schema::hasColumn('productores', 'maquila')) {
            Schema::table('productores', function (Blueprint $table) {
                $table->boolean('maquila')->default(false)->after('notas');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('productores', 'maquila')) {
            Schema::table('productores', function (Blueprint $table) {
                $table->dropColumn('maquila');
            });
        }
    }
};