<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rezaga_empaque', function (Blueprint $table) {
            // Change tipo_rezaga enum to new values
            $table->string('subtipo_rezaga', 30)->nullable()->after('tipo_rezaga');
        });

        // Change tipo_rezaga enum values
        DB::statement("ALTER TABLE rezaga_empaque MODIFY COLUMN tipo_rezaga ENUM('produccion','cuarto_frio','descarte','merma','segunda','basura') DEFAULT 'produccion'");

        // Add index
        Schema::table('rezaga_empaque', function (Blueprint $table) {
            $table->index('subtipo_rezaga');
        });
    }

    public function down(): void
    {
        Schema::table('rezaga_empaque', function (Blueprint $table) {
            $table->dropIndex(['subtipo_rezaga']);
            $table->dropColumn('subtipo_rezaga');
        });

        DB::statement("ALTER TABLE rezaga_empaque MODIFY COLUMN tipo_rezaga ENUM('descarte','merma','segunda','basura') DEFAULT 'descarte'");
    }
};
