<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_fenologicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->integer('orden')->default(0);
            $table->text('descripcion')->nullable();
            $table->string('color', 7)->nullable(); // hex color
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cultivo_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas_fenologicas');
    }
};
