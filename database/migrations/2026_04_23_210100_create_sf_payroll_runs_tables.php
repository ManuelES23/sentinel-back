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
        Schema::create('sf_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained('enterprises')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('source_file')->nullable();
            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('total_gross_pay', 14, 2)->default(0);
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_id', 'start_date', 'end_date']);
            $table->index('generated_by_user_id');
        });

        Schema::create('sf_payroll_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sf_payroll_run_id')->constrained('sf_payroll_runs')->cascadeOnDelete();
            $table->foreignId('sf_employee_id')->nullable()->constrained('sf_employees')->nullOnDelete();
            $table->string('code', 60)->nullable();
            $table->string('checker_key', 100)->nullable();
            $table->string('full_name');
            $table->string('payment_frequency', 30)->nullable();
            $table->decimal('salary', 14, 2)->default(0);
            $table->decimal('daily_rate', 14, 4)->default(0);
            $table->decimal('effective_days', 8, 2)->default(0);
            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['sf_payroll_run_id', 'sf_employee_id']);
            $table->index('checker_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sf_payroll_run_items');
        Schema::dropIfExists('sf_payroll_runs');
    }
};
