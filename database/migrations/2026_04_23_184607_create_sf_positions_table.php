<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->foreignId('sf_position_group_id')->constrained('sf_position_groups')->restrictOnDelete();
            $table->string('department', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_id', 'is_active']);
            $table->index(['enterprise_id', 'sf_position_group_id']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_positions');
    }
};
