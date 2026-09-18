<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained('enterprises')->nullOnDelete();
            $table->foreignId('almacen_id')->nullable()->after('enterprise_id')->constrained('entities')->nullOnDelete();
            $table->foreignId('capturada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviada_at')->nullable();
            $table->foreignId('confirmada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmada_at')->nullable();
            $table->text('motivo_rechazo')->nullable();
        });

        // warehouse_id era un entero suelto: se copia solo si apunta a una entidad real.
        DB::table('purchase_receipts')->whereNotNull('warehouse_id')->orderBy('id')->each(function ($r) {
            if (DB::table('entities')->where('id', $r->warehouse_id)->exists()) {
                DB::table('purchase_receipts')->where('id', $r->id)->update(['almacen_id' => $r->warehouse_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
            $table->dropConstrainedForeignId('almacen_id');
            $table->dropConstrainedForeignId('capturada_por');
            $table->dropConstrainedForeignId('confirmada_por');
            $table->dropColumn(['enviada_at', 'confirmada_at', 'motivo_rechazo']);
        });
    }
};
