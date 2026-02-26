<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->date('date');
            
            // Registros de tiempo
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->timestamp('break_start')->nullable();
            $table->timestamp('break_end')->nullable();
            
            // Horas calculadas
            $table->decimal('hours_worked', 5, 2)->nullable();
            $table->decimal('overtime_hours', 5, 2)->default(0);
            
            // Estado del día
            $table->enum('status', [
                'present',      // Presente
                'absent',       // Falta
                'late',         // Retardo
                'early_leave',  // Salida temprana
                'half_day',     // Medio día
                'holiday',      // Día festivo
                'vacation',     // Vacaciones
                'sick_leave',   // Incapacidad
                'personal_leave', // Permiso personal
                'work_from_home', // Home office
            ])->default('present');
            
            // Minutos de retardo/anticipación
            $table->integer('late_minutes')->default(0);
            $table->integer('early_leave_minutes')->default(0);
            
            // Método de registro
            $table->enum('check_in_method', ['qr', 'pin', 'manual', 'auto'])->nullable();
            $table->enum('check_out_method', ['qr', 'pin', 'manual', 'auto'])->nullable();
            
            // Información del dispositivo/terminal
            $table->string('check_in_device', 100)->nullable();
            $table->string('check_out_device', 100)->nullable();
            
            // Notas y justificaciones
            $table->text('notes')->nullable();
            $table->text('justification')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['employee_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
