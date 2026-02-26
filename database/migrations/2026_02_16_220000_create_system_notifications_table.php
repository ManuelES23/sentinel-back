<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de notificaciones del sistema
     * 
     * Tipos de audiencia:
     * - personal: Solo para un usuario específico (user_id)
     * - enterprise: Para todos los usuarios de una empresa
     * - role: Para usuarios con un rol específico
     * - global: Para todos los usuarios del sistema
     * 
     * Categorías:
     * - system: Notificaciones del sistema (mantenimiento, actualizaciones)
     * - vacation: Relacionadas con vacaciones
     * - attendance: Relacionadas con asistencia
     * - rh: Recursos humanos general
     * - alert: Alertas importantes
     * - info: Información general
     */
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Audiencia
            $table->enum('audience_type', ['personal', 'enterprise', 'role', 'global'])->default('personal');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // Para personal
            $table->foreignId('enterprise_id')->nullable()->constrained()->onDelete('cascade'); // Para enterprise
            $table->string('role')->nullable(); // Para role (admin, user, etc.)
            
            // Contenido
            $table->string('title');
            $table->text('message');
            $table->string('category')->default('info'); // system, vacation, attendance, rh, alert, info
            $table->string('icon')->nullable(); // Nombre del icono de lucide
            $table->string('icon_color')->nullable(); // Color del icono (blue, green, red, etc.)
            
            // Acción opcional
            $table->string('action_url')->nullable(); // URL para navegar
            $table->string('action_label')->nullable(); // Texto del botón
            
            // Datos adicionales
            $table->json('data')->nullable(); // Datos extra para la notificación
            
            // Prioridad y estado
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable(); // Expiración opcional
            
            // Quién la creó
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            
            // Índices
            $table->index(['audience_type', 'is_active']);
            $table->index(['user_id', 'is_active']);
            $table->index(['enterprise_id', 'is_active']);
            $table->index(['category', 'is_active']);
            $table->index('expires_at');
        });

        // Tabla pivot para trackear qué usuario ha leído cada notificación
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('read_at');
            $table->timestamp('dismissed_at')->nullable(); // Si el usuario descartó la notificación
            
            $table->foreign('notification_id')
                ->references('id')
                ->on('system_notifications')
                ->onDelete('cascade');
            
            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('system_notifications');
    }
};
