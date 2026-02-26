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
        Schema::create('enterprises', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // splendidfarms, splendidbyporvenir
            $table->string('name'); // Splendid Farms, Splendid by Porvenir
            $table->text('description');
            $table->string('color', 7)->default('#3B82F6'); // Color hex
            $table->json('config')->nullable(); // Configuraciones específicas
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enterprises');
    }
};
