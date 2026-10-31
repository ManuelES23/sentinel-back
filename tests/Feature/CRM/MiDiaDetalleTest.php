<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** GET crm/mi-dia/oportunidades/{oportunidad}: detalle para el panel de Mi día. */
class MiDiaDetalleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private CrmVendedor $propio;
    private CrmVendedor $otro;
    private CarbonImmutable $ahora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);

        $this->ahora = CarbonImmutable::parse('2026-09-14 10:00:00');
        CarbonImmutable::setTestNow($this->ahora);
        \Illuminate\Support\Carbon::setTestNow($this->ahora);

        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver']);
        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio', 'activo' => true,
        ]);
        $this->otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function detalle(CrmOportunidad $op, array $query = [], ?int $empresaId = null)
    {
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->withHeaders($this->crmHeaders($empresaId))->getJson("/api/crm/mi-dia/oportunidades/{$op->id}{$qs}");
    }

    private function oportunidad(?CrmVendedor $vendedor = null, array $datos = []): CrmOportunidad
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente '.uniqid()]);

        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => ($vendedor ?? $this->propio)->id,
            'cliente_id' => $cliente->id,
            'nombre' => 'Oportunidad',
            'etapa' => 'propuesta',
        ], $datos));
    }

    private function evento(CrmOportunidad $op, CrmVendedor $vendedor, array $datos = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $vendedor->id,
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $op->id,
            'tipo' => 'llamada',
            'titulo' => 'Evento',
            'fecha_inicio' => $this->ahora->addHour(),
            'fecha_fin' => $this->ahora->addHours(2),
            'completado' => false,
        ], $datos));
    }

    private function actividad(string $clase, int $id, CarbonImmutable $fecha, array $datos = []): CrmActividad
    {
        return CrmActividad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'entidad_type' => $clase,
            'entidad_id' => $id,
            'vendedor_id' => $this->propio->id,
            'tipo' => 'llamada',
            'descripcion' => 'Contacto',
            'fecha_actividad' => $fecha,
            'fuente' => 'manual',
        ], $datos));
    }

    public function test_sin_oportunidades_ver_abre_la_propia(): void
    {
        // Solo tiene mi-dia.ver (ver setUp): no oportunidades.ver ni cotizaciones.ver.
        $op = $this->oportunidad($this->propio, ['nombre' => 'Mía', 'monto_esperado' => 800]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id, 'folio' => 'COT-1',
            'estado' => 'enviado', 'fecha_emision' => '2026-09-10', 'subtotal' => 800, 'total' => 928,
            'descuento_global_pct' => 0,
        ]);
        $pendiente = $this->evento($op, $this->propio);
        $this->evento($op, $this->propio, ['completado' => true]);
        $this->evento($op, $this->otro);

        $json = $this->detalle($op)->assertOk()->json('data');

        $this->assertSame('Mía', $json['oportunidad']['nombre']);
        $this->assertEquals(800.0, $json['oportunidad']['monto_esperado']);
        $this->assertSame(['tipo' => 'cotizacion', 'folio' => 'COT-1'], $json['oportunidad']['monto_origen']);
        $this->assertSame('cliente', $json['oportunidad']['entidad']['tipo']);
        $this->assertSame([], $json['cotizaciones'], 'Sin cotizaciones.ver no se listan.');
        $this->assertSame([$pendiente->id], array_column($json['eventos'], 'id'));
        $this->assertSame([], $json['seguimiento']);
    }

    public function test_con_cotizaciones_ver_lista_las_cotizaciones_la_mas_nueva_primero(): void
    {
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['ver']);
        $op = $this->oportunidad();
        $vieja = CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id, 'folio' => 'COT-V',
            'estado' => 'superado', 'fecha_emision' => '2026-09-01', 'subtotal' => 100, 'total' => 116,
            'descuento_global_pct' => 0,
        ]);
        $vieja->forceFill(['created_at' => $this->ahora->subDays(5)])->saveQuietly();
        $nueva = CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id, 'folio' => 'COT-N',
            'estado' => 'enviado', 'fecha_emision' => '2026-09-10', 'subtotal' => 200, 'total' => 232,
            'descuento_global_pct' => 0,
        ]);

        $cotizaciones = $this->detalle($op)->assertOk()->json('data.cotizaciones');

        $this->assertEquals(
            [
                ['id' => $nueva->id, 'folio' => 'COT-N', 'estado' => 'enviado', 'total' => 232.0],
                ['id' => $vieja->id, 'folio' => 'COT-V', 'estado' => 'superado', 'total' => 116.0],
            ],
            $cotizaciones,
        );
    }

    public function test_evento_mio_en_oportunidad_ajena_abre(): void
    {
        $op = $this->oportunidad($this->otro, ['nombre' => 'De otro']);
        $mio = $this->evento($op, $this->propio);
        $this->evento($op, $this->otro, ['titulo' => 'Del otro']);

        $json = $this->detalle($op)->assertOk()->json('data');

        $this->assertSame('De otro', $json['oportunidad']['nombre']);
        $this->assertSame([$mio->id], array_column($json['eventos'], 'id'));
    }

    public function test_evento_completado_mio_tambien_da_acceso(): void
    {
        $op = $this->oportunidad($this->otro);
        $this->evento($op, $this->propio, ['completado' => true]);

        $json = $this->detalle($op)->assertOk()->json('data');

        $this->assertSame([], $json['eventos']);
    }

    public function test_ajena_sin_evento_403(): void
    {
        $op = $this->oportunidad($this->otro);
        $this->evento($op, $this->otro);

        $this->detalle($op)->assertForbidden();
    }

    public function test_sin_mi_dia_ver_403(): void
    {
        \App\Models\UserSubmodulePermission::query()->delete();
        $op = $this->oportunidad();

        $this->detalle($op)->assertForbidden();
    }

    public function test_otra_empresa_404(): void
    {
        $otraEmpresa = $this->crearOtraEmpresa();
        $vendedorAjeno = CrmVendedor::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajeno', 'activo' => true]);
        $clienteAjeno = CrmCliente::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Cliente ajeno']);
        $opAjena = CrmOportunidad::create([
            'empresa_id' => $otraEmpresa->id, 'vendedor_id' => $vendedorAjeno->id,
            'cliente_id' => $clienteAjeno->id, 'nombre' => 'Ajena', 'etapa' => 'propuesta',
        ]);

        $this->detalle($opAjena)->assertNotFound();
    }

    public function test_vendedor_id_solo_se_respeta_con_mi_dia_equipo(): void
    {
        $op = $this->oportunidad($this->otro);

        $this->detalle($op, ['vendedor_id' => $this->otro->id])->assertForbidden();

        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['equipo']);
        $json = $this->detalle($op, ['vendedor_id' => $this->otro->id])->assertOk()->json('data');
        $this->assertSame($op->id, $json['oportunidad']['id']);
    }

    public function test_seguimiento_junta_oportunidad_y_cliente_rotulado(): void
    {
        $etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cobranza']);
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto P']);
        $op = $this->oportunidad($this->propio, ['prospecto_id' => $prospecto->id]);
        $otraDelCliente = $this->oportunidad($this->propio, ['cliente_id' => $op->cliente_id]);

        $deOp = $this->actividad(CrmOportunidad::class, $op->id, $this->ahora->subDays(1), ['descripcion' => 'De la oportunidad', 'resultado' => 'Quedó en pagar', 'etiqueta_id' => $etiqueta->id]);
        $deCliente = $this->actividad(CrmCliente::class, $op->cliente_id, $this->ahora->subDays(2), ['descripcion' => 'Del cliente']);
        $dePros = $this->actividad(CrmProspecto::class, $prospecto->id, $this->ahora->subDays(3), ['descripcion' => 'Del prospecto']);
        $this->actividad(CrmOportunidad::class, $otraDelCliente->id, $this->ahora->subHours(1), ['descripcion' => 'De otra oportunidad']);
        $otroCliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro cliente']);
        $this->actividad(CrmCliente::class, $otroCliente->id, $this->ahora->subHours(2), ['descripcion' => 'De otro cliente']);

        $seg = $this->detalle($op)->assertOk()->json('data.seguimiento');

        $this->assertSame([$deOp->id, $deCliente->id, $dePros->id], array_column($seg, 'id'));
        $this->assertSame(['oportunidad', 'cliente', 'prospecto'], array_column($seg, 'origen'));
        $this->assertSame([
            'id' => $deOp->id,
            'tipo' => 'llamada',
            'descripcion' => 'De la oportunidad',
            'resultado' => 'Quedó en pagar',
            'fecha' => $this->ahora->subDays(1)->toIso8601String(),
            'etiqueta' => ['id' => $etiqueta->id, 'nombre' => 'Cobranza'],
            'origen' => 'oportunidad',
        ], $seg[0]);
        $this->assertNull($seg[1]['etiqueta']);
    }

    public function test_perdida_trae_motivo_del_catalogo_y_texto_libre(): void
    {
        $categoria = \App\Models\CRM\CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Precio']);
        $motivo = \App\Models\CRM\CrmMotivoRechazo::create(['categoria_id' => $categoria->id, 'nombre' => 'Muy caro']);
        $op = $this->oportunidad($this->propio, ['etapa' => 'cerrado_perdido']);
        $op->forceFill(['motivo_rechazo_id' => $motivo->id, 'motivo_perdida' => 'Compró con la competencia'])->saveQuietly();

        $json = $this->detalle($op)->assertOk()->json('data.oportunidad');

        $this->assertSame('Compró con la competencia', $json['motivo_perdida']);
        $this->assertSame(
            ['id' => $motivo->id, 'nombre' => 'Muy caro', 'categoria' => ['id' => $categoria->id, 'nombre' => 'Precio']],
            $json['motivo_rechazo'],
        );
    }

    public function test_perdida_solo_con_texto_libre(): void
    {
        $op = $this->oportunidad($this->propio, ['etapa' => 'cerrado_perdido']);
        $op->forceFill(['motivo_perdida' => 'Ya no le interesó'])->saveQuietly();

        $json = $this->detalle($op)->assertOk()->json('data.oportunidad');

        $this->assertSame('Ya no le interesó', $json['motivo_perdida']);
        $this->assertNull($json['motivo_rechazo']);
    }

    public function test_abierta_trae_motivos_nulos(): void
    {
        $op = $this->oportunidad($this->propio);

        $json = $this->detalle($op)->assertOk()->json('data.oportunidad');

        $this->assertArrayHasKey('motivo_perdida', $json);
        $this->assertArrayHasKey('motivo_rechazo', $json);
        $this->assertNull($json['motivo_perdida']);
        $this->assertNull($json['motivo_rechazo']);
    }

    public function test_seguimiento_tope_30(): void
    {
        $op = $this->oportunidad();
        for ($i = 1; $i <= 35; $i++) {
            $this->actividad(CrmOportunidad::class, $op->id, $this->ahora->subHours($i), ['descripcion' => "Act $i"]);
        }

        $seg = $this->detalle($op)->assertOk()->json('data.seguimiento');

        $this->assertCount(30, $seg);
        $this->assertSame('Act 1', $seg[0]['descripcion']);
        $this->assertSame('Act 30', $seg[29]['descripcion']);
    }
}
