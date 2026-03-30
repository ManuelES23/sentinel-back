<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->string('nombre', 50);
            $table->string('valor', 30);
            $table->text('descripcion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cultivo_id', 'variedad_id', 'valor'], 'calibres_cultivo_variedad_valor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibres');
    }
};
