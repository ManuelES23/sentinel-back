<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plagas', function (Blueprint $table) {
            $table->foreignId('cultivo_id')
                ->nullable()
                ->after('id')
                ->constrained('cultivos')
                ->nullOnDelete();

            $table->index(['cultivo_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('plagas', function (Blueprint $table) {
            $table->dropIndex(['cultivo_id', 'tipo']);
            $table->dropConstrainedForeignId('cultivo_id');
        });
    }
};
