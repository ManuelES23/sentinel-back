<?php
// database/migrations/2026_08_24_000000_create_crm_outlook_conexiones_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_outlook_conexiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            // unique: un vendedor solo puede tener una conexión activa a la vez.
            $table->foreignId('crm_vendedor_id')->unique()->constrained('crm_vendedores')->onDelete('cascade');
            $table->string('email_outlook');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->datetime('token_expires_at');
            $table->datetime('ultimo_sync_at')->nullable();
            $table->text('ultimo_error')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_outlook_conexiones');
    }
};
