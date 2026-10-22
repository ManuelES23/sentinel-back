<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días de la solicitud que se adelantan del siguiente periodo (el saldo queda en
 * negativo y se cubre al cumplir el aniversario). 0 = todo salió del saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacation_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('advance_days')->default(0)->after('days_requested');
        });
    }

    public function down(): void
    {
        Schema::table('vacation_requests', function (Blueprint $table) {
            $table->dropColumn('advance_days');
        });
    }
};
