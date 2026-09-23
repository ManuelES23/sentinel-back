<?php

namespace Tests\Feature\Compras;

use App\Models\RequisicionCotizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EsquemaComprasTest extends TestCase
{
    use RefreshDatabase;

    public function test_columnas_nuevas_existen(): void
    {
        foreach (['enterprise_id', 'almacen_id', 'enviada_at', 'rechazada_por_user_id', 'rechazada_at'] as $c) {
            $this->assertTrue(Schema::hasColumn('requisiciones_campo', $c), "requisiciones_campo.$c");
        }
        foreach (['enterprise_id', 'almacen_destino_id', 'requisicion_campo_id', 'cotizacion_id', 'sent_by', 'sent_at', 'rejected_by', 'rejected_at', 'rejection_reason'] as $c) {
            $this->assertTrue(Schema::hasColumn('purchase_orders', $c), "purchase_orders.$c");
        }
        foreach (['enterprise_id', 'almacen_id', 'capturada_por', 'enviada_at', 'confirmada_por', 'confirmada_at', 'motivo_rechazo'] as $c) {
            $this->assertTrue(Schema::hasColumn('purchase_receipts', $c), "purchase_receipts.$c");
        }
        $this->assertTrue(Schema::hasTable('requisicion_cotizaciones'));
        $this->assertTrue(Schema::hasTable('requisicion_cotizacion_detalles'));
        $this->assertTrue(class_exists(RequisicionCotizacion::class));
    }

    public function test_proceso_de_aprobacion_de_oc_existe(): void
    {
        $this->assertTrue(DB::table('approval_processes')->where('code', 'purchase_orders')->exists());
    }
}
