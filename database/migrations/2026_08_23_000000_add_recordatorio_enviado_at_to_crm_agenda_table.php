<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_agenda', function (Blueprint $table) {
            $table->datetime('recordatorio_enviado_at')->nullable()->after('recordatorio_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_agenda', function (Blueprint $table) {
            $table->dropColumn('recordatorio_enviado_at');
        });
    }
};
