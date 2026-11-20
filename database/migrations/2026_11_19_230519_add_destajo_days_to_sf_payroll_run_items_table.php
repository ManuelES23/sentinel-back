<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nómina SF por puesto del día: días a destajo (grupo con salario 0, se pagan
 * por fuera) y días sin tarifa (sin puesto y sin salario en el alta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sf_payroll_run_items', function (Blueprint $table) {
            $table->decimal('destajo_days', 8, 2)->default(0)->after('effective_days');
            $table->decimal('days_without_rate', 8, 2)->default(0)->after('destajo_days');
        });
    }

    public function down(): void
    {
        Schema::table('sf_payroll_run_items', function (Blueprint $table) {
            $table->dropColumn(['destajo_days', 'days_without_rate']);
        });
    }
};
