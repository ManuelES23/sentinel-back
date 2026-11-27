<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bins por hectárea de una variedad en una temporada: dato informativo junto al rango de cajas. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('plan_rendimientos', 'bins_por_hectarea')) {
            return;
        }

        Schema::table('plan_rendimientos', function (Blueprint $table) {
            $table->decimal('bins_por_hectarea', 8, 2)->nullable()->after('maximo');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('plan_rendimientos', 'bins_por_hectarea')) {
            return;
        }

        Schema::table('plan_rendimientos', function (Blueprint $table) {
            $table->dropColumn('bins_por_hectarea');
        });
    }
};
