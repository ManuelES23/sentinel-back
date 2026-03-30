<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenios_compra', function (Blueprint $table) {
            $table->id();
            $table->string('folio_convenio', 30)->unique();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('productor_id')->constrained('productores')->cascadeOnDelete();
            $table->foreignId('cultivo_id')->constrained('cultivos')->cascadeOnDelete();
            $table->foreignId('variedad_id')->nullable()->constrained('variedades')->nullOnDelete();
            $table->enum('modalidad', ['compra_directa', 'consignacion']);
            $table->enum('status', ['borrador', 'activo', 'suspendido', 'finalizado'])->default('borrador');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad'],
                'convenios_compra_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convenios_compra');
    }
};
