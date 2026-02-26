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
        // Catálogo de tipos de incidencia
        Schema::create('incident_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained('enterprises')->onDelete('cascade');
            $table->string('name', 100);
            $table->string('code', 20)->comment('Código corto: PERM, FALT, ENF, etc.');
            $table->text('description')->nullable();
            $table->enum('category', [
                'permission',      // Permiso
                'absence',         // Falta
                'illness',         // Enfermedad
                'personal_leave',  // Permiso personal
                'bereavement',     // Duelo
                'maternity',       // Maternidad
                'paternity',       // Paternidad
                'medical',         // Cita médica
                'other'            // Otro
            ])->default('other');
            $table->boolean('requires_approval')->default(true);
            $table->boolean('affects_attendance')->default(true)->comment('Afecta el registro de asistencia');
            $table->boolean('is_paid')->default(false)->comment('Es con goce de sueldo');
            $table->integer('max_days_per_year')->nullable()->comment('Máximo días permitidos al año');
            $table->string('color', 7)->default('#6B7280')->comment('Color para visualización');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['enterprise_id', 'code']);
        });

        // Incidencias de empleados
        Schema::create('employee_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('enterprise_id')->constrained('enterprises')->onDelete('cascade');
            $table->foreignId('incident_type_id')->constrained('incident_types')->onDelete('restrict');
            
            // Fechas
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 4, 1)->comment('Días o fracciones de día');
            
            // Horario específico (para permisos por horas)
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            
            // Estado
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            
            // Detalles
            $table->text('reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('document_path')->nullable()->comment('Comprobante adjunto');
            
            // Aprobación
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            
            // Auditoría
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['employee_id', 'status']);
            $table->index(['enterprise_id', 'incident_type_id']);
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_incidents');
        Schema::dropIfExists('incident_types');
    }
};
