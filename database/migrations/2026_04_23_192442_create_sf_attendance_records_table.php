<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sf_employee_id')->constrained('sf_employees')->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->decimal('hours_worked', 5, 2)->nullable();
            $table->enum('status', [
                'present',
                'absent',
                'late',
                'early_leave',
                'half_day',
                'holiday',
                'sick_leave',
            ])->default('present');
            $table->integer('late_minutes')->default(0);
            $table->string('source_file', 190)->nullable();
            $table->string('source_device', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sf_employee_id', 'date']);
            $table->index(['sf_employee_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_attendance_records');
    }
};
