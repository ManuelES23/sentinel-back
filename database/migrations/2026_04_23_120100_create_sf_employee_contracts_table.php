<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sf_employee_id')->constrained('sf_employees')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->unsignedInteger('version')->default(1);

            // Tipo y vigencia
            $table->enum('contract_type', ['permanent', 'temporary'])->default('permanent');
            $table->date('start_date');
            $table->date('end_date')->nullable();

            // Snapshot de datos del empleado al momento de emitir
            $table->string('snapshot_full_name', 255);
            $table->string('snapshot_curp', 18)->nullable();
            $table->string('snapshot_rfc', 13)->nullable();
            $table->string('snapshot_nss', 11)->nullable();
            $table->string('snapshot_position', 100)->nullable();
            $table->string('snapshot_department', 100)->nullable();
            $table->string('snapshot_work_location', 150)->nullable();
            $table->decimal('snapshot_salary', 12, 2)->nullable();
            $table->decimal('snapshot_daily_rate', 12, 4)->nullable();
            $table->decimal('snapshot_weekly_hours', 5, 2)->nullable();
            $table->enum('snapshot_payment_frequency', ['weekly', 'biweekly', 'monthly'])->default('weekly');

            // Plantilla y resultado renderizado
            $table->longText('template_body'); // markdown/HTML con placeholders {{nombre}}
            $table->longText('rendered_body')->nullable();

            // Archivos generados
            $table->string('pdf_path')->nullable();
            $table->string('docx_path')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Estado
            $table->enum('status', ['draft', 'active', 'terminated', 'archived'])->default('draft');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sf_employee_id', 'status']);
            $table->index('contract_type');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_employee_contracts');
    }
};
