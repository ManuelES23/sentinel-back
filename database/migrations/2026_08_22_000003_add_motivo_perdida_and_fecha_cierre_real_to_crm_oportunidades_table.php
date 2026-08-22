<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_oportunidades', function (Blueprint $table) {
            $table->text('motivo_perdida')->nullable()->after('notas');
            $table->timestamp('fecha_cierre_real')->nullable()->after('motivo_perdida');
        });
    }

    public function down(): void
    {
        Schema::table('crm_oportunidades', function (Blueprint $table) {
            $table->dropColumn(['motivo_perdida', 'fecha_cierre_real']);
        });
    }
};
