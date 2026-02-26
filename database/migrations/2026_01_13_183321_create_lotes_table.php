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
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zona_cultivo_id')->constrained('zonas_cultivo')->onDelete('cascade');
            $table->string('nombre');
            $table->string('codigo')->nullable()->comment('Código identificador del lote');
            $table->decimal('superficie', 10, 2)->nullable()->comment('Superficie en hectáreas');
            $table->string('tipo_suelo')->nullable();
            $table->string('sistema_riego')->nullable();
            $table->text('descripcion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['zona_cultivo_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};
