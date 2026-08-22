<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_configuraciones_comerciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained('enterprises')->onDelete('cascade');
            $table->boolean('descuento_global_habilitado')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_configuraciones_comerciales');
    }
};
