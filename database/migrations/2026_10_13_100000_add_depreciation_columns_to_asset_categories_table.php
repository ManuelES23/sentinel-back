<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->decimal('depreciation_rate', 5, 2)->nullable()->after('icon')->comment('Tasa anual de depreciación (%)');
            $table->decimal('salvage_percent', 5, 2)->nullable()->after('depreciation_rate')->comment('% de valor residual');
        });
    }

    public function down(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn(['depreciation_rate', 'salvage_percent']);
        });
    }
};
