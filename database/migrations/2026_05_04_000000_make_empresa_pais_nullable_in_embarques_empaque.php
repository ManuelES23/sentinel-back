<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->string('empresa_pais', 100)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('embarques_empaque', function (Blueprint $table) {
            $table->string('empresa_pais', 100)->default('MEXICO')->change();
        });
    }
};
