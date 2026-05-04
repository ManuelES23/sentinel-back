<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produccion_empaque', function (Blueprint $table) {
            if (!Schema::hasColumn('produccion_empaque', 'peso_bascula_kg')) {
                $table->decimal('peso_bascula_kg', 12, 2)->nullable()->after('peso_neto_kg');
            }
        });

        DB::statement("ALTER TABLE produccion_empaque ALTER en_cuarto_frio SET DEFAULT 0");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE produccion_empaque ALTER en_cuarto_frio SET DEFAULT 1");

        Schema::table('produccion_empaque', function (Blueprint $table) {
            if (Schema::hasColumn('produccion_empaque', 'peso_bascula_kg')) {
                $table->dropColumn('peso_bascula_kg');
            }
        });
    }
};
