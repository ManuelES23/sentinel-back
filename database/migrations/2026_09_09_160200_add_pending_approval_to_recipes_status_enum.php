<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE recipes MODIFY COLUMN status ENUM('draft','pending_approval','active','inactive','archived') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE recipes MODIFY COLUMN status ENUM('draft','active','inactive','archived') NOT NULL DEFAULT 'draft'");
    }
};
