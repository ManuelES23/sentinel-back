<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_close_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depreciation_close_id')->constrained('depreciation_closes')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
            $table->string('code', 50);
            $table->string('name');
            $table->string('category_name')->nullable();
            $table->string('subcategory_name')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 15, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('salvage_percent', 5, 2);
            $table->date('start_month');
            $table->decimal('month_amount', 15, 2);
            $table->decimal('accumulated', 15, 2);
            $table->decimal('book_value', 15, 2);

            $table->unique(['depreciation_close_id', 'fixed_asset_id'], 'dep_close_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_close_lines');
    }
};
