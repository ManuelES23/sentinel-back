<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('purchase_value')->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_receipt_id')->nullable()->after('supplier_id')->constrained('purchase_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_receipt_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
