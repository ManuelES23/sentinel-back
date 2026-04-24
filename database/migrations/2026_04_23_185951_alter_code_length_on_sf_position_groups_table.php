<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sf_position_groups MODIFY code VARCHAR(30) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sf_position_groups MODIFY code CHAR(1) NOT NULL');
    }
};
