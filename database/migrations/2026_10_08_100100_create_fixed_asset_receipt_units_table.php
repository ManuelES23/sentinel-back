<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_receipt_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
            $table->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_receipt_detail_id')->constrained('purchase_receipt_details')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('unit_number');
            $table->string('lot_number')->nullable();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->string('serial_number', 150)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('fixed_asset_id')->nullable()->unique()->constrained('fixed_assets')->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registered_at')->nullable();
            $table->text('discarded_reason')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->unique(['purchase_receipt_detail_id', 'unit_number'], 'fa_receipt_units_detail_unit_unique');
            $table->index(['enterprise_id', 'status'], 'fa_receipt_units_enterprise_status_idx');
            $table->index(['product_id', 'status'], 'fa_receipt_units_product_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_receipt_units');
    }
};
