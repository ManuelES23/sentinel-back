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
        Schema::create('zonas_cultivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('productor_id')->constrained('productores')->onDelete('cascade');
            $table->string('nombre');
            $table->string('ubicacion')->nullable();
            $table->decimal('superficie_total', 10, 2)->nullable()->comment('Superficie en hectáreas');
            $table->text('descripcion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['productor_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zonas_cultivo');
    }
};
