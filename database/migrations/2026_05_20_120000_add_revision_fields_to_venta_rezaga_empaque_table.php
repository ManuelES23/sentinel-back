<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_rezaga_empaque', function (Blueprint $table) {
            $table->string('ticket_transferencia_path')->nullable()->after('observaciones');
            $table->boolean('revision_kilos_ok')->nullable()->after('ticket_transferencia_path');
            $table->boolean('revision_importe_ok')->nullable()->after('revision_kilos_ok');
            $table->text('revision_observaciones')->nullable()->after('revision_importe_ok');
            $table->foreignId('revision_revisado_por')
                ->nullable()
                ->after('revision_observaciones')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('revision_revisado_en')->nullable()->after('revision_revisado_por');

            $table->index(['revision_kilos_ok', 'revision_importe_ok'], 'vrez_revision_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::table('venta_rezaga_empaque', function (Blueprint $table) {
            $table->dropIndex('vrez_revision_estado_idx');
            $table->dropConstrainedForeignId('revision_revisado_por');
            $table->dropColumn([
                'ticket_transferencia_path',
                'revision_kilos_ok',
                'revision_importe_ok',
                'revision_observaciones',
                'revision_revisado_en',
            ]);
        });
    }
};
