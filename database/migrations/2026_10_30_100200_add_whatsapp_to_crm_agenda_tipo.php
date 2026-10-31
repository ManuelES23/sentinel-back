<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La agenda acepta el tipo `whatsapp` (spec 2026-10-30 §6), igual que las
 * actividades. Laravel resuelve el cambio de enum en MySQL (MODIFY) y en
 * SQLite (reconstruye la tabla con el CHECK nuevo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_agenda', function (Blueprint $table) {
            $table->enum('tipo', ['llamada', 'visita', 'reunion', 'tarea', 'correo', 'whatsapp'])->change();
        });
    }

    public function down(): void
    {
        // Los eventos `whatsapp` ya guardados no caben en el enum anterior:
        // se reclasifican como `tarea` antes de volver a la lista original.
        DB::table('crm_agenda')->where('tipo', 'whatsapp')->update(['tipo' => 'tarea']);

        Schema::table('crm_agenda', function (Blueprint $table) {
            $table->enum('tipo', ['llamada', 'visita', 'reunion', 'tarea', 'correo'])->change();
        });
    }
};
