<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_position_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sf_employee_id')->constrained('sf_employees')->cascadeOnDelete();
            $table->foreignId('sf_position_id')->constrained('sf_positions')->cascadeOnDelete();
            $table->date('assignment_date');
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_device', 100)->nullable();
            $table->string('qr_code_raw', 120)->nullable();
            $table->timestamps();

            $table->unique(['sf_employee_id', 'assignment_date']);
            $table->index(['assignment_date', 'sf_position_id']);
            $table->index('assigned_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_position_assignments');
    }
};
