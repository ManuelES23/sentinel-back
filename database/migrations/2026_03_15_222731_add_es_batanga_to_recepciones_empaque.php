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
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->boolean('es_batanga')->default(false)->after('chofer');
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_empaque', function (Blueprint $table) {
            $table->dropColumn('es_batanga');
        });
    }
};
