<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_processes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();        // 'vacation_requests', 'purchase_orders', etc.
            $table->string('name', 100);                  // Nombre legible
            $table->text('description')->nullable();
            $table->string('module', 100);                // 'grupoesplendido/rh', 'splendidfarms/inventario'
            $table->string('entity_type', 150)->nullable(); // 'App\Models\VacationRequest'
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approval_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_process_id')
                ->constrained('approval_processes')
                ->cascadeOnDelete();
            $table->foreignId('enterprise_id')
                ->nullable()
                ->constrained('enterprises')
                ->nullOnDelete();
            $table->unsignedTinyInteger('step_order')->default(1);

            // Tipo de aprobador
            $table->enum('approver_type', ['hierarchy_level', 'position']);

            // Para hierarchy_level: nivel mínimo requerido (1=CEO puede todo, 7=operativo)
            $table->unsignedTinyInteger('min_hierarchy_level')->nullable();

            // Para position: puesto específico
            $table->foreignId('position_id')
                ->nullable()
                ->constrained('positions')
                ->nullOnDelete();

            // Alcance de aprobación
            $table->enum('approval_scope', ['own_department', 'child_departments', 'enterprise'])
                ->default('own_department');

            $table->boolean('can_approve')->default(true);
            $table->boolean('can_reject')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['approval_process_id', 'step_order']);
            $table->index(['approval_process_id', 'enterprise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flow_steps');
        Schema::dropIfExists('approval_processes');
    }
};
