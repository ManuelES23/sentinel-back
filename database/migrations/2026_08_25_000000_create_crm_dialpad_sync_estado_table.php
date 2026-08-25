<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_dialpad_sync_estado', function (Blueprint $table) {
            $table->id();
            // Cursor propio (call_id del más reciente visto), no el cursor
            // opaco de paginación de Dialpad -- ver SincronizarDialpadCommand.
            $table->string('ultimo_call_id_sincronizado')->nullable();
            $table->datetime('ultimo_sync_at')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_dialpad_sync_estado');
    }
};
