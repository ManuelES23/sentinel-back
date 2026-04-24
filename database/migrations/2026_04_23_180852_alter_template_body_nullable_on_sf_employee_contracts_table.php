<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE sf_employee_contracts MODIFY template_body LONGTEXT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("UPDATE sf_employee_contracts SET template_body = '' WHERE template_body IS NULL");
        DB::statement('ALTER TABLE sf_employee_contracts MODIFY template_body LONGTEXT NOT NULL');
    }
};
