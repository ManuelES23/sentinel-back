<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depreciation_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained('enterprises');
            $table->date('period')->comment('Primer día del mes cerrado');
            $table->unsignedInteger('assets_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0)->comment('Activos sin depreciar que quedaron fuera');
            $table->decimal('month_total', 15, 2)->default(0);
            $table->decimal('accumulated_total', 15, 2)->default(0);
            $table->decimal('book_total', 15, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['enterprise_id', 'period'], 'dep_closes_empresa_periodo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_closes');
    }
};
