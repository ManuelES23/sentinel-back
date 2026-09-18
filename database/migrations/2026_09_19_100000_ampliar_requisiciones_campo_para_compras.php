<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 compras: la requisición de campo gana empresa y almacén destino y
 * sus estados pasan a texto (se quita la aprobación: va directo a Compras).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisiciones_campo', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->after('id')->constrained('enterprises')->nullOnDelete();
            $table->foreignId('almacen_id')->nullable()->after('enterprise_id')->constrained('entities')->nullOnDelete();
            $table->timestamp('enviada_at')->nullable()->after('fecha_solicitud');
            $table->foreignId('rechazada_por_user_id')->nullable()->after('notas_rechazo')->constrained('users')->nullOnDelete();
            $table->timestamp('rechazada_at')->nullable()->after('rechazada_por_user_id');
        });

        Schema::table('requisiciones_campo', function (Blueprint $table) {
            $table->string('status', 20)->default('borrador')->change();
        });

        DB::table('requisiciones_campo')->whereIn('status', ['pendiente', 'aprobada'])->update(['status' => 'enviada']);
    }

    public function down(): void
    {
        DB::table('requisiciones_campo')->whereIn('status', ['enviada', 'en_cotizacion', 'cotizada'])->update(['status' => 'pendiente']);

        Schema::table('requisiciones_campo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enterprise_id');
            $table->dropConstrainedForeignId('almacen_id');
            $table->dropConstrainedForeignId('rechazada_por_user_id');
            $table->dropColumn(['enviada_at', 'rechazada_at']);
        });
    }
};
