<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_zona_cultivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->onDelete('cascade');
            $table->foreignId('zona_cultivo_id')->constrained('zonas_cultivo')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['entity_id', 'zona_cultivo_id']);
            $table->index('zona_cultivo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_zona_cultivo');
    }
};
