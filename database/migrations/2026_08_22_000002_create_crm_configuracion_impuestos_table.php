<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_configuracion_impuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('enterprises')->onDelete('cascade');
            $table->string('nombre');
            $table->decimal('tasa', 5, 2);
            $table->boolean('activo')->default(true);
            $table->integer('orden')->default(1);
            $table->timestamps();

            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_configuracion_impuestos');
    }
};
