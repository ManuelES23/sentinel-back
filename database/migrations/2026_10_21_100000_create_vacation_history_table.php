<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de vacaciones capturado por RH (lo que llevaban en Excel antes de Sentinel).
 * No pasa por la cadena de aprobación ni toca la asistencia; sus días cuentan como
 * tomados en el saldo (VacationCalculatorService::totalesDeSolicitudes). Las fechas
 * son opcionales: hay registros que solo dicen «6 días de 2025».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('vacation_year');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('days');
            $table->string('notes', 500)->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('import_file', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'start_date']);
            $table->index(['employee_id', 'vacation_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_history');
    }
};
