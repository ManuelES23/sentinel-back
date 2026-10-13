<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->date('depreciation_start')->nullable()->after('purchase_value')->comment('Primer día del mes desde el que se deprecia');
            $table->decimal('depreciation_rate', 5, 2)->nullable()->after('depreciation_start')->comment('Tasa anual de depreciación (%)');
            $table->decimal('salvage_percent', 5, 2)->nullable()->after('depreciation_rate')->comment('% de valor residual');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn(['depreciation_start', 'depreciation_rate', 'salvage_percent']);
        });
    }
};
