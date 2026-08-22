<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_cotizacion_impuestos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('crm_cotizaciones')->onDelete('cascade');
            $table->string('nombre');
            $table->decimal('tasa', 5, 2);
            $table->decimal('monto', 12, 2);

            $table->index('cotizacion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_cotizacion_impuestos');
    }
};
