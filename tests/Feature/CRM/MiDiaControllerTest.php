<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Models\UserSubmodulePermission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

class MiDiaControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const URL = '/api/crm/mi-dia';

    private CrmVendedor $propio;
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
            'empresa_id' => $this->enterprise->id,
            'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor propio',
            'activo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function miDia(array $query = [])
    {
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->withHeaders($this->crmHeaders())->getJson(self::URL.$qs);
    }

    private function evento(array $datos = []): CrmAgenda
    {
        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'tipo' => 'llamada',
            'titulo' => 'Evento',
            'fecha_inicio' => $this->ahora->addHour(),
            'fecha_fin' => $this->ahora->addHours(2),
            'completado' => false,
        ], $datos));
    }

    private function oportunidad(int $diasDeAntiguedad, array $datos = []): CrmOportunidad
    {
        $op = CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'nombre' => 'Oportunidad',
            'etapa' => 'propuesta',
        ], $datos));
        $op->forceFill(['created_at' => $this->ahora->subDays($diasDeAntiguedad)])->saveQuietly();

        return $op;
    }

    private function actividad(string $claseEntidad, int $entidadId, CarbonImmutable $fecha): CrmActividad
    {
        return CrmActividad::create([
            'empresa_id' => $this->enterprise->id,
            'entidad_type' => $claseEntidad,
            'entidad_id' => $entidadId,
            'vendedor_id' => $this->propio->id,
            'tipo' => 'llamada',
            'descripcion' => 'Contacto',
            'fecha_actividad' => $fecha,
            'fuente' => 'manual',
        ]);
    }

    private function cotizacion(CrmOportunidad $op, int $diasDesdeEmision, ?int $vigencia, string $estado = 'enviado'): CrmCotizacion
    {
        return CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id,
            'oportunidad_id' => $op->id,
            'folio' => 'COT-'.uniqid(),
            'estado' => $estado,
            'fecha_emision' => $this->ahora->subDays($diasDesdeEmision)->toDateString(),
            'vigencia_dias' => $vigencia,
            'total' => 1000,
        ]);
    }

    public function test_vencidos_incluye_pendientes_cuya_hora_de_fin_paso_y_excluye_completados_y_ajenos(): void
    {
        $vencido = $this->evento(['fecha_inicio' => $this->ahora->subHour(), 'fecha_fin' => $this->ahora->subMinute()]);
        $this->evento(['fecha_inicio' => $this->ahora->subHour(), 'fecha_fin' => $this->ahora->subMinute(), 'completado' => true]);
        $this->evento(['fecha_inicio' => $this->ahora->subHour(), 'fecha_fin' => $this->ahora->subMinute(), 'vendedor_id' => $this->vendedor->id]);

        $this->miDia()
            ->assertOk()
            ->assertJsonPath('data.vendedor.id', $this->propio->id)
            ->assertJsonPath('data.contadores.vencidos', 1)
            ->assertJsonPath('data.vencidos.0.id', $vencido->id);
    }

    public function test_hoy_incluye_de_las_00_a_las_23_59_locales_y_excluye_manana(): void
    {
        $inicio = $this->evento(['fecha_inicio' => '2026-09-14 00:00:00', 'fecha_fin' => '2026-09-14 00:30:00', 'completado' => true]);
        $fin = $this->evento(['fecha_inicio' => '2026-09-14 23:59:00', 'fecha_fin' => '2026-09-15 00:30:00']);
        $this->evento(['fecha_inicio' => '2026-09-15 00:00:00', 'fecha_fin' => '2026-09-15 00:30:00']);

        $respuesta = $this->miDia()->assertOk()->assertJsonPath('data.contadores.hoy', 2);

        $this->assertSame([$inicio->id, $fin->id], array_column($respuesta->json('data.hoy'), 'id'));
        $respuesta->assertJsonPath('data.hoy.0.completado', true);
    }

    public function test_tratos_detenidos_respeta_el_limite_de_7_dias(): void
    {
        $this->oportunidad(6, ['nombre' => 'Seis días']);
        $siete = $this->oportunidad(7, ['nombre' => 'Siete días']);

        $this->miDia()
            ->assertOk()
            ->assertJsonPath('data.contadores.tratos_detenidos', 1)
            ->assertJsonPath('data.tratos_detenidos.0.id', $siete->id)
            ->assertJsonPath('data.tratos_detenidos.0.dias_sin_actividad', 7)
            ->assertJsonPath('data.tratos_detenidos.0.ultima_actividad_at', null);
    }

    public function test_una_actividad_en_el_cliente_renueva_el_trato_y_las_etapas_cerradas_no_cuentan(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente A']);
        $conActividad = $this->oportunidad(30, ['cliente_id' => $cliente->id]);
        $this->actividad(CrmCliente::class, $cliente->id, $this->ahora->subDays(2));

        $conActividadVieja = $this->oportunidad(30, ['nombre' => 'Vieja']);
        $this->actividad(CrmOportunidad::class, $conActividadVieja->id, $this->ahora->subDays(10));

        $this->oportunidad(30, ['etapa' => 'cerrado_ganado']);
        $this->oportunidad(30, ['etapa' => 'cerrado_perdido']);

        $respuesta = $this->miDia()->assertOk()->assertJsonPath('data.contadores.tratos_detenidos', 1);

        $this->assertSame($conActividadVieja->id, $respuesta->json('data.tratos_detenidos.0.id'));
        $this->assertSame(10, $respuesta->json('data.tratos_detenidos.0.dias_sin_actividad'));
        $this->assertNotContains($conActividad->id, array_column($respuesta->json('data.tratos_detenidos'), 'id'));
    }

    public function test_cotizaciones_por_vencer_con_3_dias_o_menos_incluidas_vencidas(): void
    {
        $op = $this->oportunidad(1);
        $this->cotizacion($op, 6, 10);                       // quedan 4: no
        $tres = $this->cotizacion($op, 7, 10);               // quedan 3: sí
        $cero = $this->cotizacion($op, 10, 10);              // vence hoy: sí
        $vencida = $this->cotizacion($op, 12, 10);           // venció hace 2: sí
        $this->cotizacion($op, 12, null);                    // sin vigencia: no
        $this->cotizacion($op, 12, 10, 'borrador');          // no enviada: no

        $respuesta = $this->miDia()->assertOk()->assertJsonPath('data.contadores.cotizaciones_por_vencer', 3);

        $filas = $respuesta->json('data.cotizaciones_por_vencer');
        $this->assertSame([$vencida->id, $cero->id, $tres->id], array_column($filas, 'id'));
        $this->assertSame([-2, 0, 3], array_column($filas, 'dias_restantes'));
        $this->assertSame('2026-09-14', $filas[1]['vence_el']);
    }

    public function test_los_bloques_se_cortan_en_50_pero_el_contador_es_real(): void
    {
        foreach (range(1, 51) as $i) {
            $this->evento(['titulo' => "Vencido {$i}", 'fecha_inicio' => $this->ahora->subDays(2), 'fecha_fin' => $this->ahora->subDay()]);
        }

        $respuesta = $this->miDia()->assertOk()->assertJsonPath('data.contadores.vencidos', 51);
        $this->assertCount(50, $respuesta->json('data.vencidos'));
    }

    public function test_sin_permiso_equipo_no_puede_pedir_otro_vendedor_ni_recibe_la_lista(): void
    {
        $this->miDia()->assertOk()
            ->assertJsonPath('data.puede_ver_equipo', false)
            ->assertJsonPath('data.vendedores', []);

        $this->miDia(['vendedor_id' => $this->vendedor->id])->assertForbidden();
    }

    public function test_con_permiso_equipo_puede_ver_el_dia_de_otro_vendedor(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver', 'equipo']);
        $ajeno = $this->evento(['vendedor_id' => $this->vendedor->id, 'fecha_inicio' => $this->ahora->subHour(), 'fecha_fin' => $this->ahora->subMinute()]);

        $respuesta = $this->miDia(['vendedor_id' => $this->vendedor->id])
            ->assertOk()
            ->assertJsonPath('data.vendedor.id', $this->vendedor->id)
            ->assertJsonPath('data.puede_ver_equipo', true)
            ->assertJsonPath('data.vencidos.0.id', $ajeno->id);

        $this->assertEqualsCanonicalizing(
            [$this->vendedor->id, $this->propio->id],
            array_column($respuesta->json('data.vendedores'), 'id'),
        );
    }

    public function test_con_equipo_un_vendedor_de_otra_empresa_da_404(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver', 'equipo']);
        $ajeno = CrmVendedor::create(['empresa_id' => $this->crearOtraEmpresa()->id, 'nombre' => 'Ajeno']);

        $this->miDia(['vendedor_id' => $ajeno->id])->assertNotFound();
    }

    public function test_usuario_sin_vendedor_recibe_422_con_mensaje(): void
    {
        $this->propio->delete();

        $this->miDia()
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tu usuario no está vinculado a un vendedor. Pide a tu administrador que te dé de alta.');
    }

    public function test_gerencia_sin_vendedor_propio_recibe_bloques_vacios_y_la_lista_para_elegir(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['ver', 'equipo']);
        $this->propio->delete();

        $this->miDia()
            ->assertOk()
            ->assertJsonPath('data.vendedor', null)
            ->assertJsonPath('data.contadores.vencidos', 0)
            ->assertJsonPath('data.vendedores.0.id', $this->vendedor->id);
    }

    public function test_sin_permiso_ver_da_403(): void
    {
        UserSubmodulePermission::query()->delete();

        $this->miDia()->assertForbidden();
    }

    public function test_no_mezcla_datos_de_otra_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        CrmAgenda::create([
            'empresa_id' => $otra->id, 'vendedor_id' => $this->propio->id, 'tipo' => 'llamada', 'titulo' => 'Ajeno',
            'fecha_inicio' => $this->ahora->subHour(), 'fecha_fin' => $this->ahora->subMinute(),
        ]);

        $this->miDia()->assertOk()->assertJsonPath('data.contadores.vencidos', 0);
    }
}
