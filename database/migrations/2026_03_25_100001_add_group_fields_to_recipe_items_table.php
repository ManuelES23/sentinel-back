<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->string('group_key', 50)->nullable()->after('sort_order');
            $table->boolean('is_default')->default(true)->after('group_key');
            $table->foreignId('calibre_id')->nullable()->after('is_default')
                  ->constrained('calibres')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropForeign(['calibre_id']);
            $table->dropColumn(['group_key', 'is_default', 'calibre_id']);
        });
    }
};
