<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->decimal('precio_asignado', 12, 2)->nullable()->after('folio_ticket_bascula');
            $table->string('foto_ticket_bascula_path')->nullable()->after('precio_asignado');
            $table->foreignId('precio_asignado_por')
                ->nullable()
                ->after('foto_ticket_bascula_path')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('precio_asignado_en')->nullable()->after('precio_asignado_por');
        });
    }

    public function down(): void
    {
        Schema::table('salidas_campo_cosecha', function (Blueprint $table) {
            $table->dropForeign(['precio_asignado_por']);
            $table->dropColumn([
                'precio_asignado',
                'foto_ticket_bascula_path',
                'precio_asignado_por',
                'precio_asignado_en',
            ]);
        });
    }
};
