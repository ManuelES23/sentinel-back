<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // Contexto de la jerarquía del sistema
            $table->string('enterprise')->nullable()->after('model_id');
            $table->string('application')->nullable()->after('enterprise');
            $table->string('module')->nullable()->after('application');
            $table->string('submodule')->nullable()->after('module');
            
            // Índice para búsquedas por contexto
            $table->index(['enterprise', 'application']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['enterprise', 'application']);
            $table->dropColumn(['enterprise', 'application', 'module', 'submodule']);
        });
    }
};
