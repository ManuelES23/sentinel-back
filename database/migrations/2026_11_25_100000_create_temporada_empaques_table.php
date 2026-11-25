<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empaques (entidades de tipo EMPAQUE) que operan en cada temporada.
 * Una temporada sin renglones aquí se comporta como siempre: el front
 * muestra todos los empaques que tengan asignado el cultivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('temporada_empaques')) {
            return;
        }

        Schema::create('temporada_empaques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['temporada_id', 'entity_id'], 'temporada_empaques_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporada_empaques');
    }
};
