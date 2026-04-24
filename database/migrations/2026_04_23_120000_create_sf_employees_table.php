<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->unique();

            // Tipo de empleado / contrato base
            $table->enum('employee_type', ['permanent', 'temporary'])->default('permanent');

            // Datos personales
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('second_last_name', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('curp', 18)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('nss', 11)->nullable();

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

            // Información laboral (texto libre, no FK al RH corporativo)
            $table->string('department', 100)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('work_location', 150)->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();

            // Esquema de pago (semanal por defecto)
            $table->enum('payment_frequency', ['weekly', 'biweekly', 'monthly'])->default('weekly');
            $table->decimal('salary', 12, 2)->nullable();
            $table->decimal('daily_rate', 12, 4)->nullable();
            $table->decimal('weekly_hours', 5, 2)->nullable();
            $table->json('weekly_schedule')->nullable(); // {monday:{start,end}, ...}

            // Estado
            $table->enum('status', ['active', 'inactive', 'on_leave', 'terminated'])->default('active');
            $table->string('photo')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_id', 'status']);
            $table->index(['enterprise_id', 'employee_type']);
            $table->index('code');
            $table->index('curp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_employees');
    }
};
