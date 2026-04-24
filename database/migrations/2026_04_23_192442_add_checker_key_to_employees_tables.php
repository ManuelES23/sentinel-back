<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('checker_key', 50)->nullable()->after('pin');
            $table->unique('checker_key');
            $table->index('checker_key');
        });

        Schema::table('sf_employees', function (Blueprint $table) {
            $table->string('checker_key', 50)->nullable()->after('nss');
            $table->unique('checker_key');
            $table->index('checker_key');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['checker_key']);
            $table->dropIndex(['checker_key']);
            $table->dropColumn('checker_key');
        });

        Schema::table('sf_employees', function (Blueprint $table) {
            $table->dropUnique(['checker_key']);
            $table->dropIndex(['checker_key']);
            $table->dropColumn('checker_key');
        });
    }
};
