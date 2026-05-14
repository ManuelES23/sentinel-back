<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->foreignId('variedad_id')
                ->nullable()
                ->after('etapa_id')
                ->constrained('variedades')
                ->nullOnDelete();
            $table->index('variedad_id');
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->dropForeign(['variedad_id']);
            $table->dropIndex(['variedad_id']);
            $table->dropColumn('variedad_id');
        });
    }
};
