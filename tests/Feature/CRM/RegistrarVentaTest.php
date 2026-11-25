<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Events\CRM\CotizacionUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Models\CRM\CrmOportunidadVenta;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmVendedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Registrar lo vendido por renglón de la cotización, solo SBP (spec 2026-11-25 §2, §6, §7). */
class RegistrarVentaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const SIN_RESULTADO = 'Indica el resultado de cada renglón de la cotización.';

    private CrmOportunidad $oportunidad;

    private CrmProducto $producto;

    private CrmMotivoRechazo $motivoPrecio;

    private CrmMotivoRechazo $motivoMadurez;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente Uno', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
        $this->oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => 'Oportunidad de prueba', 'etapa' => 'negociacion',
        ]);
        $this->producto = CrmProducto::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Mango Ataulfo', 'commodity' => 'Mango', 'precio' => 30,
        ]);

        $precio = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Precio']);
        $calidad = CrmCategoriaRechazo::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Calidad']);
        $this->motivoPrecio = CrmMotivoRechazo::create(['categoria_id' => $precio->id, 'nombre' => 'Muy caro']);
        $this->motivoMadurez = CrmMotivoRechazo::create(['categoria_id' => $calidad->id, 'nombre' => 'Madurez']);

        $this->config(['cotizacion_por_caja' => true, 'descuento_global_habilitado' => true]);
    }

    private function config(array $valores): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update($valores);
    }

    /** Renglones de la propuesta: calibre 8 (62,000), 9 (114,000) y 10 (40,500). */
    private function lineas(): array
    {
        return [
            ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 8', 'presentacion' => 'Caja 4 kg', 'cantidad' => 2000, 'precio_unitario' => 31.00],
            ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 9', 'presentacion' => 'Caja 4 kg', 'cantidad' => 4000, 'precio_unitario' => 28.50],
            ['producto_id' => $this->producto->id, 'calibre' => 'Calibre 10', 'presentacion' => 'Caja 4 kg', 'cantidad' => 1500, 'precio_unitario' => 27.00],
        ];
    }

    private function enviada(float $descuento = 0, ?CrmOportunidad $oportunidad = null): CrmCotizacion
    {
        $oportunidad ??= $this->oportunidad;
        $res = $this->withHeaders($this->crmHeaders())
            ->postJson("/api/crm/oportunidades/{$oportunidad->id}/cotizaciones", [
                'fecha_emision' => now()->toDateString(), 'descuento_global_pct' => $descuento,
                'lineas' => $this->lineas(),
            ])->assertCreated();
        $id = $res->json('data.id');
        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$id}/enviar")->assertOk();

        return CrmCotizacion::findOrFail($id);
    }

    /** IDs de los renglones de la cotización por calibre: [8 => id, 9 => id, 10 => id]. */
    private function ids(CrmCotizacion $c): array
    {
        return $c->lineas()->orderBy('id')->pluck('id')->combine([8, 9, 10])->flip()->all();
    }

    /** Solo el calibre 9 se vende (5,000 × 28.50); 8 y 10 no. */
    private function soloElNueve(CrmCotizacion $c, array $extra9 = []): array
    {
        $id = $this->ids($c);

        return ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            array_merge(['linea_id' => $id[9], 'vendido' => true, 'cantidad' => 5000, 'precio' => 28.50], $extra9),
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoMadurez->id, 'nota' => 'Fruta chica'],
        ]];
    }

    private function registrar(CrmCotizacion $c, array $payload)
    {
        return $this->withHeaders($this->crmHeaders())->postJson("/api/crm/cotizaciones/{$c->id}/venta", $payload);
    }

    public function test_vende_un_renglon_gana_la_oportunidad_con_lo_vendido(): void
    {
        $c = $this->enviada();

        $res = $this->registrar($c, $this->soloElNueve($c))
            ->assertOk()
            ->assertJsonPath('message', 'Venta registrada')
            ->assertJsonPath('data.oportunidad.etapa', 'cerrado_ganado')
            ->assertJsonPath('data.cotizacion.estado', 'aprobado')
            ->assertJsonStructure(['data' => ['oportunidad' => ['monto_origen'], 'cotizacion', 'venta' => ['renglones']]]);
        $this->assertEquals(142500.00, (float) $res->json('data.oportunidad.monto_esperado'));

        $op = $this->oportunidad->fresh();
        $this->assertSame('cerrado_ganado', $op->etapa);
        $this->assertEquals(142500.00, (float) $op->monto_esperado);
        $this->assertNotNull($op->fecha_cierre_real);
        $this->assertSame('aprobado', $c->fresh()->estado);

        $venta = CrmOportunidadVenta::where('oportunidad_id', $op->id)->where('vigente', true)->firstOrFail();
        $this->assertSame($c->id, (int) $venta->cotizacion_id);
        $this->assertSame($this->actingUser->id, (int) $venta->registrada_por);
        $this->assertEquals(0.0, (float) $venta->descuento_global_pct);
        $renglones = $venta->renglones()->orderBy('id')->get();
        $this->assertCount(3, $renglones);

        [$r8, $r9, $r10] = $renglones;
        $this->assertFalse($r8->vendido);
        $this->assertSame('Calibre 8', $r8->calibre);
        $this->assertSame('Caja 4 kg', $r8->presentacion);
        $this->assertSame('Mango Ataulfo', $r8->descripcion);
        $this->assertSame($this->producto->id, (int) $r8->producto_id);
        $this->assertEquals(2000, (float) $r8->cantidad_cotizada);
        $this->assertEquals(31.00, (float) $r8->precio_cotizado);
        $this->assertNull($r8->cantidad_vendida);
        $this->assertNull($r8->precio_vendido);
        $this->assertSame($this->motivoPrecio->id, (int) $r8->motivo_rechazo_id);

        $this->assertTrue($r9->vendido);
        $this->assertEquals(4000, (float) $r9->cantidad_cotizada);
        $this->assertEquals(28.50, (float) $r9->precio_cotizado);
        $this->assertSame(5000, $r9->cantidad_vendida);
        $this->assertEquals(28.50, (float) $r9->precio_vendido);
        $this->assertNull($r9->motivo_rechazo_id);

        $this->assertFalse($r10->vendido);
        $this->assertSame('Fruta chica', $r10->nota);
        $this->assertSame($this->motivoMadurez->id, (int) $r10->motivo_rechazo_id);

        $nota = CrmActividad::where('entidad_type', CrmOportunidad::class)->where('entidad_id', $op->id)->where('fuente', 'sistema')->firstOrFail();
        $this->assertSame('nota', $nota->tipo);
        $this->assertSame('Venta registrada: COT-00001 · 1 de 3 renglones · $142,500.00', $nota->descripcion);
    }

    public function test_aplica_el_descuento_global(): void
    {
        $c = $this->enviada(10);

        $this->registrar($c, $this->soloElNueve($c))->assertOk();

        $this->assertEquals(128250.00, (float) $this->oportunidad->fresh()->monto_esperado);
        $venta = CrmOportunidadVenta::where('oportunidad_id', $this->oportunidad->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $venta->descuento_global_pct);
        $nota = CrmActividad::where('entidad_id', $this->oportunidad->id)->where('fuente', 'sistema')->firstOrFail();
        $this->assertSame('Venta registrada: COT-00001 · 1 de 3 renglones · $128,250.00', $nota->descripcion);
    }

    public function test_el_monto_se_redondea_a_dos_decimales(): void
    {
        $c = $this->enviada(7.5);
        $id = $this->ids($c);

        // 333 × 12.34 = 4109.22; × 0.925 = 3801.0285 → 3,801.03
        $this->registrar($c, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => true, 'cantidad' => 333, 'precio' => 12.34],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk();

        $this->assertEquals(3801.03, (float) $this->oportunidad->fresh()->monto_esperado);
    }

    public function test_los_renglones_vendidos_toman_lo_cotizado_si_no_se_manda_cantidad_ni_precio(): void
    {
        $c = $this->enviada();
        $id = $this->ids($c);

        $this->registrar($c, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => true],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk();

        $this->assertEquals(62000.00, (float) $this->oportunidad->fresh()->monto_esperado);
    }

    public function test_ningun_renglon_vendido_pierde_la_oportunidad(): void
    {
        $c = $this->enviada();
        $id = $this->ids($c);
        $agenda = CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmOportunidad::class, 'entidad_id' => $this->oportunidad->id,
            'tipo' => 'tarea', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-11-26 09:00:00', 'fecha_fin' => '2026-11-26 09:30:00',
        ]);

        $res = $this->registrar($c, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoMadurez->id],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoMadurez->id],
        ]])->assertOk()->assertJsonPath('message', 'La oportunidad se cerró como perdida');

        $op = $this->oportunidad->fresh();
        $this->assertSame('cerrado_perdido', $op->etapa);
        // El renglón de mayor importe cotizado es el calibre 9 (4,000 × 28.50 = 114,000).
        $this->assertSame($this->motivoMadurez->id, (int) $op->motivo_rechazo_id);
        $this->assertSame('Ningún renglón se vendió (COT-00001).', $op->motivo_perdida);
        $this->assertNotNull($op->fecha_cierre_real);
        $this->assertSame('rechazado', $c->fresh()->estado);
        $this->assertTrue((bool) $agenda->fresh()->completado);
        $res->assertJsonPath('data.oportunidad.etapa', 'cerrado_perdido');
        $res->assertJsonPath('data.cotizacion.estado', 'rechazado');
        $this->assertSame(1, CrmActividad::where('entidad_id', $op->id)->where('fuente', 'sistema')->count());
    }

    public function test_ningun_vendido_conserva_el_porque_de_cada_renglon(): void
    {
        $c = $this->enviada();
        $id = $this->ids($c);

        $this->registrar($c, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoMadurez->id, 'nota' => 'Compró a otro'],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoMadurez->id],
        ]])->assertOk();

        $venta = CrmOportunidadVenta::where('oportunidad_id', $this->oportunidad->id)->firstOrFail();
        $this->assertCount(3, $venta->renglones);
        $this->assertSame($this->motivoMadurez->id, (int) $venta->renglones()->orderBy('id')->get()[1]->motivo_rechazo_id);
        $this->assertSame('Compró a otro', $venta->renglones()->orderBy('id')->get()[1]->nota);
    }

    public function test_exige_un_resultado_por_cada_renglon(): void
    {
        $c = $this->enviada();
        $otra = $this->enviada();
        $id = $this->ids($c);
        $ok = $this->soloElNueve($c)['renglones'];

        // falta uno
        $this->registrar($c, ['renglones' => array_slice($ok, 0, 2)])
            ->assertStatus(422)->assertJsonPath('errors.renglones.0', self::SIN_RESULTADO);
        // sobra uno (repetido)
        $this->registrar($c, ['renglones' => array_merge($ok, [$ok[0]])])
            ->assertStatus(422)->assertJsonPath('errors.renglones.0', self::SIN_RESULTADO);
        // un linea_id de otra cotización
        $ajeno = $ok;
        $ajeno[2]['linea_id'] = $otra->lineas()->first()->id;
        $this->registrar($c, ['renglones' => $ajeno])
            ->assertStatus(422)->assertJsonPath('errors.renglones.0', self::SIN_RESULTADO);
        // sin renglones
        $this->registrar($c, [])->assertStatus(422)->assertJsonPath('errors.renglones.0', self::SIN_RESULTADO);
        $this->registrar($c, ['renglones' => []])->assertStatus(422)->assertJsonPath('errors.renglones.0', self::SIN_RESULTADO);

        $this->assertSame(0, CrmOportunidadVenta::count());
        $this->assertSame('negociacion', $this->oportunidad->fresh()->etapa);
        $this->assertSame('enviado', $c->fresh()->estado);
        $this->assertNotEmpty($id);
    }

    public function test_no_vendido_exige_motivo(): void
    {
        $c = $this->enviada();
        $payload = $this->soloElNueve($c);

        unset($payload['renglones'][2]['motivo_rechazo_id']);
        $this->registrar($c, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['renglones.2.motivo_rechazo_id' => 'Elige por qué no se vendió.']);

        $payload = $this->soloElNueve($c);
        $payload['renglones'][0]['motivo_rechazo_id'] = null;
        $this->registrar($c, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['renglones.0.motivo_rechazo_id' => 'Elige por qué no se vendió.']);

        // Motivo de otra empresa
        $otraEmpresa = $this->crearOtraEmpresa();
        $categoria = CrmCategoriaRechazo::create(['empresa_id' => $otraEmpresa->id, 'nombre' => 'Ajena']);
        $ajeno = CrmMotivoRechazo::create(['categoria_id' => $categoria->id, 'nombre' => 'Ajeno']);
        $payload = $this->soloElNueve($c);
        $payload['renglones'][2]['motivo_rechazo_id'] = $ajeno->id;
        $this->registrar($c, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['renglones.2.motivo_rechazo_id' => 'Elige por qué no se vendió.']);

        // Motivo desactivado
        $this->motivoMadurez->update(['activo' => false]);
        $this->registrar($c, $this->soloElNueve($c))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['renglones.2.motivo_rechazo_id' => 'Elige por qué no se vendió.']);

        $this->assertSame(0, CrmOportunidadVenta::count());
        $this->assertSame('enviado', $c->fresh()->estado);
    }

    public function test_cajas_enteras_mayores_a_cero_y_precio_no_negativo(): void
    {
        $c = $this->enviada();

        foreach ([1.5, 0, -1] as $cantidad) {
            $this->registrar($c, $this->soloElNueve($c, ['cantidad' => $cantidad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['renglones.1.cantidad']);
        }
        $this->registrar($c, $this->soloElNueve($c, ['precio' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['renglones.1.precio']);

        $this->assertSame(0, CrmOportunidadVenta::count());
        $this->assertSame('negociacion', $this->oportunidad->fresh()->etapa);
        $this->assertSame('enviado', $c->fresh()->estado);
        $this->assertSame(0, CrmActividad::where('fuente', 'sistema')->count());
    }

    public function test_un_precio_cero_es_valido(): void
    {
        $c = $this->enviada();

        $this->registrar($c, $this->soloElNueve($c, ['precio' => 0]))->assertOk();

        $this->assertEquals(0.0, (float) $this->oportunidad->fresh()->monto_esperado);
    }

    public function test_un_monto_que_no_cabe_da_422(): void
    {
        $c = $this->enviada();

        $this->registrar($c, $this->soloElNueve($c, ['cantidad' => 1000000, 'precio' => 9999999.99]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['renglones']);

        $this->assertSame(0, CrmOportunidadVenta::count());
        $this->assertSame('enviado', $c->fresh()->estado);
    }

    public function test_registrar_dos_veces_la_misma_cotizacion_da_422(): void
    {
        $c = $this->enviada();
        $this->registrar($c, $this->soloElNueve($c))->assertOk();

        $this->registrar($c, $this->soloElNueve($c))
            ->assertStatus(422)
            ->assertJsonPath('message', 'La cotización ya tiene venta registrada; registra un ajuste en la oportunidad.');

        $this->assertSame(1, CrmOportunidadVenta::count());
        $this->assertSame(1, CrmActividad::where('fuente', 'sistema')->count());
    }

    public function test_otra_cotizacion_reemplaza_la_venta_sin_ajustes(): void
    {
        $a = $this->enviada();
        $this->registrar($a, $this->soloElNueve($a))->assertOk();
        $fechaCierre = $this->oportunidad->fresh()->fecha_cierre_real;
        $this->travel(2)->days();

        $b = $this->enviada();
        $id = $this->ids($b);
        $this->registrar($b, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => true, 'cantidad' => 1000, 'precio' => 30.00],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk()->assertJsonPath('message', 'Venta registrada');

        $this->assertSame('superado', $a->fresh()->estado);
        $this->assertSame('aprobado', $b->fresh()->estado);
        $op = $this->oportunidad->fresh();
        $this->assertSame('cerrado_ganado', $op->etapa);
        $this->assertEquals(30000.00, (float) $op->monto_esperado);
        $this->assertTrue($op->fecha_cierre_real->equalTo($fechaCierre), 'conserva la fecha de cierre');

        $this->assertSame(2, CrmOportunidadVenta::count());
        $vigentes = CrmOportunidadVenta::where('vigente', true)->get();
        $this->assertCount(1, $vigentes);
        $this->assertSame($b->id, (int) $vigentes->first()->cotizacion_id);
        $this->assertFalse((bool) CrmOportunidadVenta::where('cotizacion_id', $a->id)->first()->vigente);
    }

    public function test_reemplaza_aunque_los_ajustes_esten_anulados(): void
    {
        $a = $this->enviada();
        $this->registrar($a, $this->soloElNueve($a))->assertOk();
        $this->ajuste(CrmOportunidadVenta::where('cotizacion_id', $a->id)->first(), anulado: true);

        $b = $this->enviada();
        $this->registrar($b, $this->soloElNueve($b))->assertOk();

        $this->assertSame('aprobado', $b->fresh()->estado);
    }

    public function test_no_reemplaza_si_hay_ajustes_vigentes(): void
    {
        $a = $this->enviada();
        $this->registrar($a, $this->soloElNueve($a))->assertOk();
        $this->ajuste(CrmOportunidadVenta::where('cotizacion_id', $a->id)->first());
        $montoAntes = $this->oportunidad->fresh()->monto_esperado;

        $b = $this->enviada();
        $this->registrar($b, $this->soloElNueve($b))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta venta ya tiene ajustes; anúlalos antes de registrar otra.');

        $this->assertSame('aprobado', $a->fresh()->estado);
        $this->assertSame('enviado', $b->fresh()->estado);
        $this->assertSame($montoAntes, $this->oportunidad->fresh()->monto_esperado);
        $this->assertSame(1, CrmOportunidadVenta::count());
        $this->assertTrue((bool) CrmOportunidadVenta::first()->vigente);
    }

    public function test_una_oportunidad_ya_ganada_no_se_pierde_si_nada_se_vende(): void
    {
        $a = $this->enviada();
        $this->registrar($a, $this->soloElNueve($a))->assertOk();

        $b = $this->enviada();
        $id = $this->ids($b);
        $this->registrar($b, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertStatus(422)->assertJsonValidationErrors(['renglones']);

        $this->assertSame('cerrado_ganado', $this->oportunidad->fresh()->etapa);
        $this->assertSame('aprobado', $a->fresh()->estado);
        $this->assertSame('enviado', $b->fresh()->estado);
    }

    public function test_solo_se_registra_una_enviada_o_una_aprobada_sin_venta(): void
    {
        $this->withHeaders($this->crmHeaders());
        $borrador = CrmCotizacion::findOrFail($this->enviada()->id);
        $borrador->update(['estado' => 'borrador']);
        $rechazada = $this->enviada();
        $rechazada->update(['estado' => 'rechazado']);
        $superada = $this->enviada();
        $superada->update(['estado' => 'superado']);

        foreach ([$borrador, $rechazada, $superada] as $c) {
            $this->registrar($c, $this->soloElNueve($c))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Solo una cotización enviada puede registrar lo vendido.');
        }

        // Aprobada sin venta (aprobada antes de la bandera): sí se registra.
        $aprobada = $this->enviada();
        $aprobada->update(['estado' => 'aprobado']);
        $this->registrar($aprobada, $this->soloElNueve($aprobada))->assertOk();
        $this->assertSame('aprobado', $aprobada->fresh()->estado);
        $this->assertSame(1, CrmOportunidadVenta::count());
    }

    public function test_un_vendedor_sin_aprobar_ni_cerrar_registra_su_venta(): void
    {
        $c = $this->enviada();
        $this->quitarPermisosYDejar(propio: true);

        $this->registrar($c, $this->soloElNueve($c))->assertOk();

        $this->assertSame('cerrado_ganado', $this->oportunidad->fresh()->etapa);
        $this->assertSame('aprobado', $c->fresh()->estado);
    }

    public function test_vendedor_ajeno_recibe_403(): void
    {
        $c = $this->enviada();
        $this->quitarPermisosYDejar(propio: false);

        $this->registrar($c, $this->soloElNueve($c))->assertForbidden();

        $this->assertSame('enviado', $c->fresh()->estado);
        $this->assertSame('negociacion', $this->oportunidad->fresh()->etapa);
        $this->assertSame(0, CrmOportunidadVenta::count());
    }

    public function test_sin_permiso_de_editar_cotizaciones_u_oportunidades_da_403(): void
    {
        $c = $this->enviada();
        $this->quitarPermisosYDejar(propio: true, conCotizacionesEditar: false);
        $this->registrar($c, $this->soloElNueve($c))->assertForbidden();

        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', ['editar']);
        $this->quitarPermiso('oportunidades', 'oportunidades', 'editar');
        $this->registrar($c, $this->soloElNueve($c))->assertForbidden();

        $this->assertSame('enviado', $c->fresh()->estado);
    }

    public function test_otra_empresa_da_404_antes_que_cualquier_otra_cosa(): void
    {
        $c = $this->enviada();
        $otra = $this->crearOtraEmpresa();
        $this->otorgarAccesoA($otra);

        $this->withHeaders($this->crmHeaders($otra->id))
            ->postJson("/api/crm/cotizaciones/{$c->id}/venta", $this->soloElNueve($c))
            ->assertNotFound();
    }

    public function test_oportunidad_perdida_da_422(): void
    {
        $c = $this->enviada();
        $this->oportunidad->update(['etapa' => 'cerrado_perdido']);

        $this->registrar($c, $this->soloElNueve($c))
            ->assertStatus(422)
            ->assertJsonPath('message', 'La oportunidad está cerrada como perdida; no se puede registrar lo vendido.');

        $this->assertSame('enviado', $c->fresh()->estado);
        $this->assertSame(0, CrmOportunidadVenta::count());
    }

    public function test_sin_bandera_venta_da_422_y_aprobar_sigue_igual(): void
    {
        $this->config(['cotizacion_por_caja' => false]);
        $c = $this->enviada();

        $this->registrar($c, $this->soloElNueve($c))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta empresa no registra lo vendido por renglón.');
        $this->assertSame(0, CrmOportunidadVenta::count());
        $this->assertSame('enviado', $c->fresh()->estado);

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$c->id}/aprobar")
            ->assertOk()
            ->assertJsonPath('message', 'Cotización aprobada — la oportunidad se cerró como ganada');
        $op = $this->oportunidad->fresh();
        $this->assertSame('cerrado_ganado', $op->etapa);
        $this->assertEquals(3600.0 * 0 + 62000 + 114000 + 40500, (float) $op->monto_esperado);
        $this->assertSame('aprobado', $c->fresh()->estado);
    }

    public function test_aprobar_en_sbp_da_422(): void
    {
        $c = $this->enviada();

        $this->withHeaders($this->crmHeaders())->patchJson("/api/crm/cotizaciones/{$c->id}/aprobar")
            ->assertStatus(422)
            ->assertJsonPath('message', 'En esta empresa se registra lo vendido de la cotización.');

        $this->assertSame('enviado', $c->fresh()->estado);
        $this->assertSame('negociacion', $this->oportunidad->fresh()->etapa);
    }

    public function test_difunde_los_eventos_despues_de_registrar(): void
    {
        $c = $this->enviada();
        CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmOportunidad::class, 'entidad_id' => $this->oportunidad->id,
            'tipo' => 'tarea', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-11-26 09:00:00', 'fecha_fin' => '2026-11-26 09:30:00',
        ]);
        $id = $this->ids($c);
        Event::fake([OportunidadUpdated::class, CotizacionUpdated::class, ActividadUpdated::class, AgendaUpdated::class]);

        $this->registrar($c, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[9], 'vendido' => true, 'cantidad' => 10, 'precio' => 28.5],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk();

        Event::assertDispatched(OportunidadUpdated::class);
        Event::assertDispatched(CotizacionUpdated::class);
        Event::assertDispatched(ActividadUpdated::class);
        // Ganada: la agenda pendiente no se toca.
        Event::assertNotDispatched(AgendaUpdated::class);
    }

    public function test_difunde_la_agenda_completada_al_perder(): void
    {
        $c = $this->enviada();
        CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'entidad_type' => CrmOportunidad::class, 'entidad_id' => $this->oportunidad->id,
            'tipo' => 'tarea', 'titulo' => 'Dar seguimiento',
            'fecha_inicio' => '2026-11-26 09:00:00', 'fecha_fin' => '2026-11-26 09:30:00',
        ]);
        $id = $this->ids($c);
        Event::fake([AgendaUpdated::class]);

        $this->registrar($c, ['renglones' => [
            ['linea_id' => $id[8], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[9], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
            ['linea_id' => $id[10], 'vendido' => false, 'motivo_rechazo_id' => $this->motivoPrecio->id],
        ]])->assertOk();

        Event::assertDispatched(AgendaUpdated::class, fn ($e) => $e->action === 'completed');
    }

    public function test_monto_venta_del_servicio_suma_solo_lo_vendido_con_el_descuento(): void
    {
        $c = $this->enviada(10);
        $this->registrar($c, $this->soloElNueve($c))->assertOk();
        $venta = CrmOportunidadVenta::firstOrFail();

        $this->assertSame(128250.0, app(\App\Services\CRM\VentaOportunidadService::class)->montoVenta($venta));
        $this->assertSame('−$28,500.00', \App\Services\CRM\VentaOportunidadService::dinero(-28500));
        $this->assertSame('$1,234.50', \App\Services\CRM\VentaOportunidadService::dinero(1234.5));
    }

    // ── apoyo ─────────────────────────────────────────────────────────────────

    /** Un ajuste vigente (o anulado) sobre la venta, solo para probar el reemplazo. */
    private function ajuste(CrmOportunidadVenta $venta, bool $anulado = false): void
    {
        $renglon = $venta->renglones()->where('vendido', true)->firstOrFail();
        CrmOportunidadAjuste::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $venta->oportunidad_id,
            'venta_renglon_id' => $renglon->id, 'tipo' => 'rechazo', 'cajas' => 100, 'importe' => -2850,
            'motivo_rechazo_id' => $this->motivoMadurez->id, 'fecha' => now()->toDateString(),
            'user_id' => $this->actingUser->id,
            'anulado_at' => $anulado ? now() : null,
            'anulado_por' => $anulado ? $this->actingUser->id : null,
            'motivo_anulacion' => $anulado ? 'Error de captura' : null,
        ]);
    }

    /**
     * Deja al usuario como vendedor (sin equipo, aprobar ni cerrar) con solo
     * ver/editar. `propio`: la oportunidad es suya; si no, de otro vendedor.
     */
    private function quitarPermisosYDejar(bool $propio, bool $conCotizacionesEditar = true): void
    {
        \App\Models\UserSubmodulePermission::where('user_id', $this->actingUser->id)->delete();

        $yo = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->oportunidad->update(['vendedor_id' => $propio ? $yo->id : $this->vendedor->id]);

        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver', 'editar']);
        $this->otorgarPermisosCrm('cotizaciones', 'cotizaciones', $conCotizacionesEditar ? ['ver', 'editar'] : ['ver']);
    }

    private function quitarPermiso(string $modulo, string $submodulo, string $permiso): void
    {
        \App\Models\UserSubmodulePermission::where('user_id', $this->actingUser->id)
            ->whereHas('permissionType', fn ($q) => $q->where('slug', $permiso)
                ->whereHas('submodule', fn ($s) => $s->where('slug', $submodulo)))
            ->delete();
    }
}
