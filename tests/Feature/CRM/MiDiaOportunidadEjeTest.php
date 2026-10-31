<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Mi día con la oportunidad como eje: agenda, tablero y por cobrar (spec 2026-10-30 §5.1, §8). */
class MiDiaOportunidadEjeTest extends TestCase
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

    private function oportunidad(array $datos = []): CrmOportunidad
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente '.uniqid()]);

        return CrmOportunidad::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'cliente_id' => $cliente->id,
            'nombre' => 'Oportunidad',
            'etapa' => 'propuesta',
        ], $datos));
    }

    private function evento(array $datos = [], ?CrmOportunidad $op = null): CrmAgenda
    {
        $op ??= $this->oportunidad();

        return CrmAgenda::create(array_merge([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $this->propio->id,
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $op->id,
            'tipo' => 'llamada',
            'titulo' => 'Evento',
            'fecha_inicio' => $this->ahora->addHour(),
            'fecha_fin' => $this->ahora->addHours(2),
            'completado' => false,
        ], $datos));
    }

    private function ganada(?string $estadoCobro = null, array $datos = []): CrmOportunidad
    {
        $op = $this->oportunidad(array_merge(['etapa' => 'cerrado_ganado'], $datos));
        $op->forceFill(['estado_cobro' => $estadoCobro])->saveQuietly();

        return $op;
    }

    private function etapa(array $json, string $etapa): array
    {
        return collect($json['data']['tablero'])->firstWhere('etapa', $etapa)['oportunidades'];
    }

    public function test_agenda_ordena_por_fecha_y_hora(): void
    {
        $tarde = $this->evento(['titulo' => 'Tarde', 'fecha_inicio' => $this->ahora->addHours(5), 'fecha_fin' => $this->ahora->addHours(6)]);
        $temprano = $this->evento(['titulo' => 'Temprano', 'fecha_inicio' => $this->ahora->addHour(), 'fecha_fin' => $this->ahora->addHours(2)]);
        $manana2 = $this->evento(['titulo' => 'M2', 'fecha_inicio' => $this->ahora->addDays(2), 'fecha_fin' => $this->ahora->addDays(2)->addHour()]);
        $manana1 = $this->evento(['titulo' => 'M1', 'fecha_inicio' => $this->ahora->addDay(), 'fecha_fin' => $this->ahora->addDay()->addHour()]);
        $vencida2 = $this->evento(['titulo' => 'V2', 'fecha_inicio' => $this->ahora->subHours(2), 'fecha_fin' => $this->ahora->subHour()]);
        $vencida1 = $this->evento(['titulo' => 'V1', 'fecha_inicio' => $this->ahora->subDays(2), 'fecha_fin' => $this->ahora->subDays(2)->addHour()]);

        $agenda = $this->miDia()->assertOk()->json('data.agenda');

        $this->assertSame([$vencida1->id, $vencida2->id], array_column($agenda['vencidas'], 'id'));
        $this->assertSame([$temprano->id, $tarde->id], array_column($agenda['hoy'], 'id'));
        $this->assertSame([$manana1->id, $manana2->id], array_column($agenda['proximas'], 'id'));
    }

    public function test_evento_vencido_de_hoy_sale_una_vez_en_vencidas(): void
    {
        $vencidoHoy = $this->evento([
            'fecha_inicio' => $this->ahora->setTime(8, 0),
            'fecha_fin' => $this->ahora->setTime(9, 0),
        ]);

        $json = $this->miDia()->assertOk()->json('data');

        $this->assertSame([$vencidoHoy->id], array_column($json['agenda']['vencidas'], 'id'));
        $this->assertSame([], $json['agenda']['hoy']);
        $this->assertSame([], $json['agenda']['proximas']);
        // La clave clásica `hoy` conserva su comportamiento: sigue trayéndolo.
        $this->assertSame([$vencidoHoy->id], array_column($json['hoy'], 'id'));
        $this->assertSame([$vencidoHoy->id], array_column($json['vencidos'], 'id'));
    }

    public function test_completados_no_salen_en_agenda(): void
    {
        $this->evento(['completado' => true, 'fecha_inicio' => $this->ahora->subDays(2), 'fecha_fin' => $this->ahora->subDays(2)->addHour()]);
        $this->evento(['completado' => true]);
        $this->evento(['completado' => true, 'fecha_inicio' => $this->ahora->addDays(2), 'fecha_fin' => $this->ahora->addDays(2)->addHour()]);

        $agenda = $this->miDia()->assertOk()->json('data.agenda');

        $this->assertSame(['vencidas' => [], 'hoy' => [], 'proximas' => []], $agenda);
    }

    public function test_proximas_son_7_dias_y_excluyen_hoy(): void
    {
        $finDeHoy = $this->evento(['fecha_inicio' => $this->ahora->setTime(23, 59), 'fecha_fin' => $this->ahora->addDay()->setTime(0, 30)]);
        $inicioManana = $this->evento(['fecha_inicio' => $this->ahora->addDay()->startOfDay(), 'fecha_fin' => $this->ahora->addDay()->setTime(1, 0)]);
        $dia7 = $this->evento(['fecha_inicio' => $this->ahora->addDays(7)->setTime(23, 30), 'fecha_fin' => $this->ahora->addDays(7)->setTime(23, 45)]);
        $this->evento(['fecha_inicio' => $this->ahora->addDays(8)->startOfDay(), 'fecha_fin' => $this->ahora->addDays(8)->setTime(1, 0)]);

        $agenda = $this->miDia()->assertOk()->json('data.agenda');

        $this->assertSame([$finDeHoy->id], array_column($agenda['hoy'], 'id'));
        $this->assertSame([$inicioManana->id, $dia7->id], array_column($agenda['proximas'], 'id'));
        $this->assertSame([], $agenda['vencidas']);
    }

    public function test_evento_de_varios_dias_en_curso_sale_solo_en_hoy(): void
    {
        $enCurso = $this->evento([
            'titulo' => 'Feria',
            'fecha_inicio' => $this->ahora->subDay(),
            'fecha_fin' => $this->ahora->addDay(),
        ]);
        $deHoy = $this->evento(['titulo' => 'Llamada', 'fecha_inicio' => $this->ahora->addHour(), 'fecha_fin' => $this->ahora->addHours(2)]);

        $json = $this->miDia()->assertOk()->json('data');
        $agenda = $json['agenda'];

        // Ordenado por fecha de inicio: el que empezó ayer va primero.
        $this->assertSame([$enCurso->id, $deHoy->id], array_column($agenda['hoy'], 'id'));
        $this->assertSame([], $agenda['vencidas']);
        $this->assertSame([], $agenda['proximas']);
        // La clave clásica `hoy` no cambia: solo los que inician hoy.
        $this->assertSame([$deHoy->id], array_column($json['hoy'], 'id'));
    }

    public function test_evento_que_empezo_ayer_y_ya_termino_sale_solo_en_vencidas(): void
    {
        $terminado = $this->evento([
            'fecha_inicio' => $this->ahora->subDay(),
            'fecha_fin' => $this->ahora->subHour(),
        ]);

        $agenda = $this->miDia()->assertOk()->json('data.agenda');

        $this->assertSame([$terminado->id], array_column($agenda['vencidas'], 'id'));
        $this->assertSame([], $agenda['hoy']);
        $this->assertSame([], $agenda['proximas']);
    }

    public function test_evento_con_oportunidad_trae_oportunidad_y_etiqueta(): void
    {
        $etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Seguimiento']);
        $op = $this->oportunidad([
            'nombre' => 'Berries Don Memo',
            'monto_esperado' => 1500.5,
            'tipo_producto' => 'berries',
        ]);
        CrmCotizacion::create([
            'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id, 'folio' => 'COT-0139',
            'estado' => 'enviado', 'fecha_emision' => '2026-09-10', 'subtotal' => 1500.5, 'total' => 1740.58,
            'descuento_global_pct' => 0,
        ]);
        $evento = $this->evento(['etiqueta_id' => $etiqueta->id], $op);

        $item = $this->miDia()->assertOk()->json('data.agenda.hoy.0');

        $this->assertSame($evento->id, $item['id']);
        $this->assertSame(['id' => $etiqueta->id, 'nombre' => 'Seguimiento'], $item['etiqueta']);
        $this->assertFalse($item['liga_pendiente']);
        $this->assertSame([
            'id' => $op->id,
            'nombre' => 'Berries Don Memo',
            'etapa' => 'propuesta',
            'tipo_producto' => 'berries',
            'monto_esperado' => 1500.5,
            'monto_origen' => ['tipo' => 'cotizacion', 'folio' => 'COT-0139'],
            'estado_cobro' => $op->fresh()->estado_cobro,
            'entidad' => ['tipo' => 'cliente', 'id' => $op->cliente_id, 'nombre' => $op->cliente->nombre],
            'motivo_perdida' => null,
            'motivo_rechazo' => null,
        ], $item['oportunidad']);
        // Forma clásica del evento intacta.
        $this->assertSame('oportunidad', $item['entidad']['tipo']);
        $this->assertSame('llamada', $item['tipo']);
    }

    public function test_evento_con_cliente_trae_liga_pendiente(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente suelto']);
        $prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Prospecto suelto']);
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Externa SA', 'tipo' => 'otro']);
        $op = $this->oportunidad();
        $hora = fn (int $h) => ['fecha_inicio' => $this->ahora->addHours($h), 'fecha_fin' => $this->ahora->addHours($h)->addMinutes(30)];
        $this->evento(['titulo' => 'cliente', 'entidad_type' => CrmCliente::class, 'entidad_id' => $cliente->id] + $hora(1));
        $this->evento(['titulo' => 'prospecto', 'entidad_type' => CrmProspecto::class, 'entidad_id' => $prospecto->id] + $hora(2));
        $this->evento(['titulo' => 'externa', 'entidad_type' => CrmEmpresaExterna::class, 'entidad_id' => $externa->id] + $hora(3));
        $this->evento(['titulo' => 'oportunidad'] + $hora(4), $op);

        $hoy = collect($this->miDia()->assertOk()->json('data.agenda.hoy'))->keyBy('titulo');

        $this->assertTrue($hoy['cliente']['liga_pendiente']);
        $this->assertNull($hoy['cliente']['oportunidad']);
        $this->assertSame('cliente', $hoy['cliente']['entidad']['tipo']);
        $this->assertTrue($hoy['prospecto']['liga_pendiente']);
        $this->assertNull($hoy['prospecto']['oportunidad']);
        $this->assertFalse($hoy['externa']['liga_pendiente']);
        $this->assertNull($hoy['externa']['oportunidad']);
        $this->assertFalse($hoy['oportunidad']['liga_pendiente']);
        $this->assertNotNull($hoy['oportunidad']['oportunidad']);
    }

    public function test_evento_interno_sin_oportunidad_no_rompe(): void
    {
        $interno = $this->evento(['entidad_type' => null, 'entidad_id' => null, 'titulo' => 'Junta interna']);

        $item = $this->miDia()->assertOk()->json('data.agenda.hoy.0');

        $this->assertSame($interno->id, $item['id']);
        $this->assertNull($item['entidad']);
        $this->assertNull($item['oportunidad']);
        $this->assertNull($item['etiqueta']);
        $this->assertTrue($item['liga_pendiente']);
    }

    public function test_tablero_incluye_abiertas_sin_siguiente(): void
    {
        $conSiguiente = $this->oportunidad(['nombre' => 'Con siguiente', 'etapa' => 'calificado']);
        $this->evento(['fecha_inicio' => $this->ahora->addDays(30), 'fecha_fin' => $this->ahora->addDays(30)->addHour()], $conSiguiente);
        $sinSiguiente = $this->oportunidad(['nombre' => 'Sin siguiente', 'etapa' => 'calificado']);
        $soloCompletada = $this->oportunidad(['nombre' => 'Completada', 'etapa' => 'calificado']);
        $this->evento(['completado' => true], $soloCompletada);
        $ajena = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);
        $this->oportunidad(['nombre' => 'De otro', 'etapa' => 'calificado', 'vendedor_id' => $ajena->id]);

        $json = $this->miDia()->assertOk()->json();

        $this->assertSame(
            ['prospecto', 'calificado', 'propuesta', 'negociacion', 'cerrado_ganado'],
            array_column($json['data']['tablero'], 'etapa'),
        );
        $calificado = $this->etapa($json, 'calificado');
        // Con siguiente primero (aunque sea en 30 días); las demás al final, por id.
        $this->assertSame(
            [$conSiguiente->id, $sinSiguiente->id, $soloCompletada->id],
            array_column($calificado, 'id'),
        );
        $this->assertSame('Con siguiente', $calificado[0]['nombre']);
        $this->assertSame($conSiguiente->id, $calificado[0]['siguiente']['entidad']['id']);
        $this->assertArrayNotHasKey('oportunidad', $calificado[0]['siguiente']);
        $this->assertSame('llamada', $calificado[0]['siguiente']['tipo']);
        $this->assertNull($calificado[1]['siguiente']);
        $this->assertNull($calificado[2]['siguiente']);
        $this->assertSame([], $this->etapa($json, 'prospecto'));
    }

    public function test_tablero_ordena_por_siguiente_y_usa_solo_eventos_del_vendedor(): void
    {
        $a = $this->oportunidad(['nombre' => 'A']);
        $b = $this->oportunidad(['nombre' => 'B']);
        $this->evento(['fecha_inicio' => $this->ahora->addDays(3), 'fecha_fin' => $this->ahora->addDays(3)->addHour()], $a);
        $this->evento(['fecha_inicio' => $this->ahora->addDays(1), 'fecha_fin' => $this->ahora->addDays(1)->addHour()], $a);
        $this->evento(['fecha_inicio' => $this->ahora->addDays(2), 'fecha_fin' => $this->ahora->addDays(2)->addHour()], $b);
        $otro = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);
        // El evento de otro vendedor sobre B, más cercano, no cuenta como su siguiente.
        $this->evento(['vendedor_id' => $otro->id, 'fecha_inicio' => $this->ahora->addMinutes(5), 'fecha_fin' => $this->ahora->addMinutes(20)], $b);

        $propuesta = $this->etapa($this->miDia()->assertOk()->json(), 'propuesta');

        $this->assertSame([$a->id, $b->id], array_column($propuesta, 'id'));
        $this->assertSame(
            $this->ahora->addDays(1)->toIso8601String(),
            CarbonImmutable::parse($propuesta[0]['siguiente']['fecha_inicio'])->toIso8601String(),
        );
        $this->assertSame(
            $this->ahora->addDays(2)->toIso8601String(),
            CarbonImmutable::parse($propuesta[1]['siguiente']['fecha_inicio'])->toIso8601String(),
        );
    }

    public function test_tablero_ganada_solo_con_pendiente_o_por_cobrar(): void
    {
        $porCobrar = $this->ganada('por_cobrar', ['nombre' => 'Por cobrar']);
        $conPendiente = $this->ganada('cobrada', ['nombre' => 'Con pendiente']);
        $this->evento([], $conPendiente);
        $this->ganada('cobrada', ['nombre' => 'Cobrada sin nada']);
        $this->ganada(null, ['nombre' => 'Legado sin estado']);

        $ganada = $this->etapa($this->miDia()->assertOk()->json(), 'cerrado_ganado');

        $this->assertEqualsCanonicalizing([$porCobrar->id, $conPendiente->id], array_column($ganada, 'id'));
        $this->assertSame($conPendiente->id, $ganada[0]['id'], 'La que tiene siguiente va primero.');
        $this->assertSame('por_cobrar', collect($ganada)->firstWhere('id', $porCobrar->id)['estado_cobro']);
    }

    public function test_tablero_excluye_perdidas(): void
    {
        $perdida = $this->oportunidad(['etapa' => 'cerrado_perdido']);
        $this->evento([], $perdida);

        $json = $this->miDia()->assertOk()->json();

        foreach ($json['data']['tablero'] as $columna) {
            $this->assertSame([], $columna['oportunidades']);
        }
    }

    public function test_tablero_limita_cada_columna_a_50(): void
    {
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'C']);
        for ($i = 0; $i < 55; $i++) {
            CrmOportunidad::create([
                'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->propio->id,
                'cliente_id' => $cliente->id, 'nombre' => "Op $i", 'etapa' => 'prospecto',
            ]);
        }

        $json = $this->miDia()->assertOk()->json();

        $this->assertCount(50, $this->etapa($json, 'prospecto'));
    }

    public function test_por_cobrar_ignora_ganadas_sin_estado(): void
    {
        $this->ganada('por_cobrar', ['monto_esperado' => 1000.25]);
        $this->ganada('por_cobrar', ['monto_esperado' => 500]);
        $this->ganada(null, ['monto_esperado' => 9999]);
        $this->ganada('cobrada', ['monto_esperado' => 7777]);
        $this->ganada('rechazada', ['monto_esperado' => 7777]);
        $ajena = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro', 'activo' => true]);
        $this->ganada('por_cobrar', ['monto_esperado' => 4444, 'vendedor_id' => $ajena->id]);

        $json = $this->miDia()->assertOk()->json('data');

        $this->assertSame(2, $json['por_cobrar']['cantidad']);
        $this->assertSame(1500.25, $json['por_cobrar']['monto']);
        $this->assertTrue($json['oportunidad_eje']);
    }

    public function test_conserva_las_claves_clasicas_y_la_marca_de_version(): void
    {
        $json = $this->miDia()->assertOk()->json('data');

        foreach (['umbrales', 'contadores', 'vencidos', 'hoy', 'tratos_detenidos', 'cotizaciones_por_vencer', 'vendedor', 'puede_ver_equipo', 'vendedores', 'mi_mes'] as $clave) {
            $this->assertArrayHasKey($clave, $json);
        }
        $this->assertTrue($json['oportunidad_eje']);
    }

    public function test_sin_vendedor_elegido_devuelve_estructura_vacia_con_marca(): void
    {
        $this->otorgarPermisosCrm('mi-dia', 'mi-dia', ['equipo']);
        $this->propio->update(['activo' => false]);

        $json = $this->miDia()->assertOk()->json('data');

        $this->assertNull($json['vendedor']);
        $this->assertTrue($json['oportunidad_eje']);
        $this->assertSame(['vencidas' => [], 'hoy' => [], 'proximas' => []], $json['agenda']);
        $this->assertSame(
            ['prospecto', 'calificado', 'propuesta', 'negociacion', 'cerrado_ganado'],
            array_column($json['tablero'], 'etapa'),
        );
        $this->assertEquals(['cantidad' => 0, 'monto' => 0.0], $json['por_cobrar']);
    }

    public function test_sin_consultas_n_mas_1(): void
    {
        $etiqueta = CrmEtiquetaActividad::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Seguimiento']);
        $contar = function (int $eventos) use ($etiqueta): int {
            CrmAgenda::query()->delete();
            CrmOportunidad::query()->forceDelete();
            for ($i = 0; $i < $eventos; $i++) {
                $op = $this->oportunidad(['etapa' => $i % 2 ? 'negociacion' : 'calificado']);
                CrmCotizacion::create([
                    'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id, 'folio' => 'COT-'.uniqid(),
                    'estado' => 'enviado', 'fecha_emision' => '2026-09-10', 'subtotal' => 10, 'total' => 11,
                    'descuento_global_pct' => 0,
                ]);
                $this->evento(['etiqueta_id' => $etiqueta->id, 'fecha_inicio' => $this->ahora->addHours(1 + $i % 3), 'fecha_fin' => $this->ahora->addHours(4)], $op);
                $this->evento(['etiqueta_id' => $etiqueta->id, 'fecha_inicio' => $this->ahora->subHours(5), 'fecha_fin' => $this->ahora->subHours(4)], $op);
                $this->evento(['etiqueta_id' => $etiqueta->id, 'fecha_inicio' => $this->ahora->addDays(2), 'fecha_fin' => $this->ahora->addDays(2)->addHour()], $op);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->miDia()->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con2 = $contar(2);
        $con10 = $contar(10);

        $this->assertSame($con2, $con10, 'Mi día no debe consultar por cada evento u oportunidad.');
    }
}
