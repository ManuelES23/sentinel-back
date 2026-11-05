<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso de visto bueno de RH (regla rh_review): se congela en la cadena de cada solicitud.
 * RH lo da sin cerrar la solicitud; la aprobación sigue en los pasos posteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vacation_request_approvals', 'is_rh_review')) {
            return;
        }

        Schema::table('vacation_request_approvals', function (Blueprint $table) {
            $table->boolean('is_rh_review')->default(false)->after('is_rh_final');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vacation_request_approvals', 'is_rh_review')) {
            return;
        }

        Schema::table('vacation_request_approvals', function (Blueprint $table) {
            $table->dropColumn('is_rh_review');
        });
    }
};
