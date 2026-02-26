<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Campos para documento de autorización
            $table->string('requested_by', 255)->nullable()->after('internal_notes');
            $table->string('department_head', 255)->nullable()->after('requested_by');
            $table->string('authorized_by_name', 255)->nullable()->after('department_head');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['requested_by', 'department_head', 'authorized_by_name']);
        });
    }
};
