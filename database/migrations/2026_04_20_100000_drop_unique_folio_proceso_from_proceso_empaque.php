<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->dropUnique(['folio_proceso']);
            $table->index('folio_proceso');
        });
    }

    public function down(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->dropIndex(['folio_proceso']);
            $table->unique('folio_proceso');
        });
    }
};
