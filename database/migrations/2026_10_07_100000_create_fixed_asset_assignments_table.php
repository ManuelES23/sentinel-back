<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->restrictOnDelete();
            // Sin cascada: los activos usan borrado lógico y el historial se conserva.
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();

            // Responsable. Sin llave foránea: apunta a tres tablas (employees, sf_employees, users);
            // la integridad la garantiza AsignadorActivos / ResponsablesActivo.
            $table->enum('assignee_type', ['employee', 'sf_employee', 'user']);
            $table->unsignedBigInteger('assignee_id');
            $table->string('assignee_name');
            $table->string('assignee_position')->nullable();
            $table->string('assignee_department')->nullable();

            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();

            // Entrega
            $table->date('assigned_at');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // Copia del nombre de quien entrega: los usuarios se borran y la carta debe reimprimirse igual.
            $table->string('assigned_by_name')->nullable();
            $table->enum('condition_out', ['bueno', 'regular', 'malo'])->default('bueno');
            $table->text('accessories')->nullable();
            $table->text('notes')->nullable();
            $table->json('asset_snapshot');

            // Devolución (returned_at nulo = asignación activa)
            $table->date('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('condition_in', ['bueno', 'regular', 'malo'])->nullable();
            $table->enum('return_reason', ['renuncia', 'cambio_puesto', 'reemplazo', 'dano', 'otro'])->nullable();
            $table->text('return_notes')->nullable();

            // Carta firmada escaneada (disco privado)
            $table->string('signed_document_path')->nullable();
            $table->timestamp('signed_uploaded_at')->nullable();
            $table->foreignId('signed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['fixed_asset_id', 'returned_at']);
            $table->index(['enterprise_id', 'returned_at']);
            $table->index(['assignee_type', 'assignee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_assignments');
    }
};
