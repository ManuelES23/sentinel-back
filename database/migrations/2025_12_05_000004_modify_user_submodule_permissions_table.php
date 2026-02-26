<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Modificar la tabla user_submodule_permissions para soportar permisos dinámicos
     */
    public function up(): void
    {
        // Primero, eliminar la tabla existente y sus foreign keys
        Schema::dropIfExists('user_submodule_permissions_old');
        Schema::dropIfExists('user_submodule_permissions');
        
        // Crear la nueva estructura
        Schema::create('user_submodule_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('submodule_id');
            $table->unsignedBigInteger('permission_type_id');
            $table->boolean('is_granted')->default(false);
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('submodule_id')->references('id')->on('submodules')->onDelete('cascade');
            $table->foreign('permission_type_id')->references('id')->on('submodule_permission_types')->onDelete('cascade');
            
            $table->unique(['user_id', 'submodule_id', 'permission_type_id'], 'user_submod_perm_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_submodule_permissions');
        
        // Recrear estructura original
        Schema::create('user_submodule_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('submodule_id')->constrained()->onDelete('cascade');
            $table->boolean('can_view')->default(true);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->unique(['user_id', 'submodule_id']);
        });
    }
};
