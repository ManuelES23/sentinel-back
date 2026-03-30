<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('variedad_id')
                ->nullable()
                ->after('cultivo_id')
                ->constrained('variedades')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropForeign(['variedad_id']);
            $table->dropColumn('variedad_id');
        });
    }
};
