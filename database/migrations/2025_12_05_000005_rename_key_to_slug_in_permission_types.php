<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renombrar columna 'key' a 'slug' en submodule_permission_types
     */
    public function up(): void
    {
        if (Schema::hasColumn('submodule_permission_types', 'key') && !Schema::hasColumn('submodule_permission_types', 'slug')) {
            Schema::table('submodule_permission_types', function (Blueprint $table) {
                $table->renameColumn('key', 'slug');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('submodule_permission_types', 'slug') && !Schema::hasColumn('submodule_permission_types', 'key')) {
            Schema::table('submodule_permission_types', function (Blueprint $table) {
                $table->renameColumn('slug', 'key');
            });
        }
    }
};
