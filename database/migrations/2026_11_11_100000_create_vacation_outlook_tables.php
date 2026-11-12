<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conexión de Outlook de quien autoriza vacaciones (por usuario) y el mapeo
 * solicitud -> evento de Outlook para no duplicar ni dejar huérfanos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vacation_outlook_connections')) {
            Schema::create('vacation_outlook_connections', function (Blueprint $table) {
                $table->id();
                // unique: un usuario solo puede tener una conexión a la vez.
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('email_outlook')->nullable();
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at');
                $table->timestamp('ultimo_sync_at')->nullable();
                $table->text('ultimo_error')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vacation_outlook_events')) {
            Schema::create('vacation_outlook_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vacation_outlook_connection_id')->constrained('vacation_outlook_connections')->cascadeOnDelete();
                $table->foreignId('vacation_request_id')->constrained('vacation_requests')->cascadeOnDelete();
                $table->string('outlook_event_id');
                $table->timestamp('enviado_at');
                $table->timestamps();

                $table->unique(['vacation_outlook_connection_id', 'vacation_request_id'], 'vac_outlook_events_conn_req_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_outlook_events');
        Schema::dropIfExists('vacation_outlook_connections');
    }
};
