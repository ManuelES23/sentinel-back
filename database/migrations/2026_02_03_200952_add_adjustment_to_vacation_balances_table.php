<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vacation_balances', function (Blueprint $table) {
            $table->integer('adjustment_days')->default(0)->after('carried_over')
                  ->comment('Ajuste manual de días (+/-)');
            $table->string('adjustment_reason')->nullable()->after('adjustment_days')
                  ->comment('Motivo del ajuste');
            $table->foreignId('adjusted_by')->nullable()->after('adjustment_reason')
                  ->constrained('users')->onDelete('set null')
                  ->comment('Usuario que realizó el ajuste');
            $table->timestamp('adjusted_at')->nullable()->after('adjusted_by')
                  ->comment('Fecha del último ajuste');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vacation_balances', function (Blueprint $table) {
            $table->dropForeign(['adjusted_by']);
            $table->dropColumn(['adjustment_days', 'adjustment_reason', 'adjusted_by', 'adjusted_at']);
        });
    }
};
