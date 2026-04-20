<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rezaga_empaque MODIFY COLUMN tipo_rezaga ENUM('produccion','cuarto_frio','descarte','merma','segunda','basura','lavado','hidrotermico') DEFAULT 'produccion'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE rezaga_empaque MODIFY COLUMN tipo_rezaga ENUM('produccion','cuarto_frio','descarte','merma','segunda','basura') DEFAULT 'produccion'");
    }
};
