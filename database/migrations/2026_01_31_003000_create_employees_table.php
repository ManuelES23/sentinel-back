<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->string('employee_number', 20)->unique();
            
            // Datos personales
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('second_last_name', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('curp', 18)->nullable()->unique();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable(); // Número de Seguro Social
            
            // Contacto
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('emergency_contact', 100)->nullable();
            $table->string('emergency_phone', 20)->nullable();
            
            // Dirección
            $table->string('address_street', 255)->nullable();
            $table->string('address_number', 20)->nullable();
            $table->string('address_interior', 20)->nullable();
            $table->string('address_colony', 100)->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('address_state', 100)->nullable();
            $table->string('address_zip', 10)->nullable();
            
            // Información laboral
            $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('position_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('reports_to')->nullable()->constrained('employees')->onDelete('set null');
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->enum('contract_type', ['permanent', 'temporary', 'contractor', 'intern'])->default('permanent');
            $table->enum('work_shift', ['morning', 'afternoon', 'night', 'mixed', 'flexible'])->default('morning');
            $table->decimal('salary', 12, 2)->nullable();
            $table->enum('payment_frequency', ['weekly', 'biweekly', 'monthly'])->default('biweekly');
            
            // Checador QR
            $table->string('qr_code', 64)->unique(); // Código único para el QR
            $table->string('pin', 6)->nullable(); // PIN alternativo
            
            // Estado
            $table->enum('status', ['active', 'inactive', 'on_leave', 'terminated'])->default('active');
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();
            
            // Vinculación con usuario del sistema (opcional)
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_id', 'status']);
            $table->index(['enterprise_id', 'department_id']);
            $table->index('qr_code');
        });

        // Agregar FK de manager en departments
        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('employees')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });
        
        Schema::dropIfExists('employees');
    }
};
