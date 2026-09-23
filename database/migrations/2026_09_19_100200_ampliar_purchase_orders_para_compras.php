<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained('enterprises')->nullOnDelete();
            $table->foreignId('almacen_destino_id')->nullable()->after('enterprise_id')->constrained('entities')->nullOnDelete();
            $table->foreignId('requisicion_campo_id')->nullable()->after('almacen_destino_id')->constrained('requisiciones_campo')->nullOnDelete();
            $table->foreignId('cotizacion_id')->nullable()->after('requisicion_campo_id')->constrained('requisicion_cotizaciones')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });

        // El vínculo con la requisición vivía solo en metadata.
        DB::table('purchase_orders')->whereNotNull('metadata')->orderBy('id')->each(function ($oc) {
            $meta = json_decode($oc->metadata, true) ?: [];
            $reqId = $meta['requisicion_campo_id'] ?? null;
            if ($reqId && DB::table('requisiciones_campo')->where('id', $reqId)->exists()) {
                DB::table('purchase_orders')->where('id', $oc->id)->update(['requisicion_campo_id' => $reqId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
            $table->dropConstrainedForeignId('almacen_destino_id');
            $table->dropConstrainedForeignId('requisicion_campo_id');
            $table->dropConstrainedForeignId('cotizacion_id');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['sent_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
