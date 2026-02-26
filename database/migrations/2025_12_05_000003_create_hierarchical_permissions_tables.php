<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sistema de permisos jerárquico completo:
     * Usuario → Empresas → Aplicaciones → Módulos → Submódulos → Permisos
     */
    public function up(): void
    {
        // Tabla de acceso de usuarios a empresas
        if (!Schema::hasTable('user_enterprise_access')) {
            Schema::create('user_enterprise_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                
                $table->unique(['user_id', 'enterprise_id']);
            });
        }

        // Tabla de acceso de usuarios a aplicaciones (dentro de empresas asignadas)
        if (!Schema::hasTable('user_application_access')) {
            Schema::create('user_application_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('application_id')->constrained()->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                
                $table->unique(['user_id', 'application_id']);
            });
        }

        // Tabla de acceso de usuarios a módulos (dentro de aplicaciones asignadas)
        if (!Schema::hasTable('user_module_access')) {
            Schema::create('user_module_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('module_id')->constrained()->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                
                $table->unique(['user_id', 'module_id']);
            });
        }

        // Tabla de acceso de usuarios a submódulos con permisos granulares
        if (!Schema::hasTable('user_submodule_access')) {
            Schema::create('user_submodule_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('submodule_id')->constrained()->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                
                $table->unique(['user_id', 'submodule_id']);
            });
        }

        // Tabla de tipos de permisos personalizables por submódulo
        if (!Schema::hasTable('submodule_permission_types')) {
            Schema::create('submodule_permission_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submodule_id')->constrained()->onDelete('cascade');
                $table->string('key', 50); // ej: 'view', 'create', 'edit', 'delete', 'export', 'approve', etc.
                $table->string('name', 100); // ej: 'Ver', 'Crear', 'Editar', 'Eliminar', 'Exportar', 'Aprobar'
                $table->string('description')->nullable();
                $table->string('icon', 50)->nullable(); // icono para la UI
                $table->string('color', 20)->default('blue'); // color para la UI
                $table->integer('order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->unique(['submodule_id', 'key']);
            });
        }

        // Tabla de permisos asignados a usuarios por submódulo
        if (!Schema::hasTable('user_submodule_permissions')) {
            Schema::create('user_submodule_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('submodule_id')->constrained()->onDelete('cascade');
                $table->foreignId('permission_type_id')->constrained('submodule_permission_types')->onDelete('cascade');
                $table->boolean('granted')->default(true);
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                
                $table->unique(['user_id', 'submodule_id', 'permission_type_id'], 'user_submodule_permission_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_submodule_permissions');
        Schema::dropIfExists('submodule_permission_types');
        Schema::dropIfExists('user_submodule_access');
        Schema::dropIfExists('user_module_access');
        Schema::dropIfExists('user_application_access');
        Schema::dropIfExists('user_enterprise_access');
    }
};
