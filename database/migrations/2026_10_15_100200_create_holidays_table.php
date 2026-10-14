<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Días festivos por fecha concreta. enterprise_id nulo = aplica a todas las empresas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name', 100);
            $table->foreignId('enterprise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_official')->default(false);
            $table->timestamps();

            $table->unique(['date', 'enterprise_id']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
