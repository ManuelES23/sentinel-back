<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->json('lavado_snapshot')->nullable()->after('rezaga_hidrotermico_cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('proceso_empaque', function (Blueprint $table) {
            $table->dropColumn('lavado_snapshot');
        });
    }
};
