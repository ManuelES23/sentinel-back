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
        Schema::create('vacation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('enterprise_id')->constrained('enterprises')->onDelete('cascade');
            
            // Fechas de la solicitud
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days_requested')->comment('Días solicitados');
            
            // Estado de la solicitud
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            
            // Motivo y comentarios
            $table->text('reason')->nullable()->comment('Motivo de la solicitud');
            $table->text('rejection_reason')->nullable()->comment('Motivo del rechazo');
            
            // Aprobación
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            
            // Período vacacional (año al que corresponden)
            $table->year('vacation_year')->comment('Año del período vacacional');
            
            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['employee_id', 'status']);
            $table->index(['enterprise_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        // Tabla para llevar el saldo de vacaciones por empleado/año
        Schema::create('vacation_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->year('year');
            $table->integer('entitled_days')->default(0)->comment('Días que le corresponden');
            $table->integer('used_days')->default(0)->comment('Días usados');
            $table->integer('pending_days')->default(0)->comment('Días en solicitudes pendientes');
            $table->integer('carried_over')->default(0)->comment('Días transferidos del año anterior');
            $table->timestamps();
            
            $table->unique(['employee_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacation_balances');
        Schema::dropIfExists('vacation_requests');
    }
};
