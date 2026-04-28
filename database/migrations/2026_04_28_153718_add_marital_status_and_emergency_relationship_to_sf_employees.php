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
        Schema::table('sf_employees', function (Blueprint $table) {
            $table->string('marital_status', 30)->nullable()->after('gender');
            $table->string('emergency_relationship', 80)->nullable()->after('emergency_contact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sf_employees', function (Blueprint $table) {
            $table->dropColumn(['marital_status', 'emergency_relationship']);
        });
    }
};
