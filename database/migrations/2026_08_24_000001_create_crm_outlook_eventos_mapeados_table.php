<?php
// database/migrations/2026_08_24_000001_create_crm_outlook_eventos_mapeados_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_outlook_eventos_mapeados', function (Blueprint $table) {
            $table->id();
            // nullable + nullOnDelete (NO cascade): cuando se borra el evento
            // de Agenda, el mapeo debe SOBREVIVIR con crm_agenda_id = null
            // para que SincronizarOutlookCommand::borrarEliminados() pueda
            // detectarlo y borrar el evento espejo en Outlook antes de
            // borrar la fila de mapeo él mismo. Ver Global Constraints.
            $table->foreignId('crm_agenda_id')->nullable()->constrained('crm_agenda')->nullOnDelete();
            $table->foreignId('crm_outlook_conexion_id')->constrained('crm_outlook_conexiones')->onDelete('cascade');
            $table->string('outlook_event_id');
            $table->datetime('ultima_actualizacion_enviada_at');
            $table->timestamps();

            // unique permite múltiples NULL en MySQL (no se consideran
            // iguales entre sí), así que no choca con eventos ya borrados.
            $table->unique('crm_agenda_id');
            $table->index('crm_outlook_conexion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_outlook_eventos_mapeados');
    }
};
