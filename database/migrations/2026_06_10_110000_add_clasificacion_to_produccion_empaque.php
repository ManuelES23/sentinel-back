<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('produccion_empaque', 'clasificacion')) {
            Schema::table('produccion_empaque', function (Blueprint $table) {
                $table->enum('clasificacion', ['convencional', 'organico'])
                    ->default('convencional')
                    ->after('categoria');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('produccion_empaque', 'clasificacion')) {
            Schema::table('produccion_empaque', function (Blueprint $table) {
                $table->dropColumn('clasificacion');
            });
        }
    }
};
