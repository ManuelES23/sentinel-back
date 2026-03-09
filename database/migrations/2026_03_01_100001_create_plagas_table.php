<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plagas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('nombre_cientifico', 200)->nullable();
            $table->enum('tipo', ['insecto', 'hongo', 'bacteria', 'maleza', 'virus', 'nematodo', 'otro'])->default('otro');
            $table->text('descripcion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plagas');
    }
};
