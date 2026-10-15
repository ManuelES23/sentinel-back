<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bitácora de avisos al empleado por solicitud de vacaciones (spec §7.3). sent_by nulo = sistema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacation_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacation_request_id')->constrained('vacation_requests')->cascadeOnDelete();
            $table->enum('channel', ['email', 'whatsapp']);
            $table->string('event', 30);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['vacation_request_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_notifications');
    }
};
