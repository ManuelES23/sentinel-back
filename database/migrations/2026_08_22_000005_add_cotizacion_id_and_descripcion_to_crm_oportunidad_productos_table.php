<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_oportunidad_productos', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->after('oportunidad_id')
                ->constrained('crm_cotizaciones')->onDelete('cascade');
            $table->string('descripcion')->nullable()->after('producto_id');
            $table->index('cotizacion_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_oportunidad_productos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_id');
            $table->dropColumn('descripcion');
        });
    }
};
