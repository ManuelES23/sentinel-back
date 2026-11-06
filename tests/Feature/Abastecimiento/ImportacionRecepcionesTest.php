<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\TipoCarga;
use App\Services\Abastecimiento\LectorRecepciones;
use App\Services\Abastecimiento\ValidadorImportacionRecepciones;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/**
 * Importación de recepciones desde Excel. Parte de validación: el validador interpreta las filas
 * del lector (todo texto) sin guardar. «Hoy» es el martes 14 de julio de 2026.
 */
class ImportacionRecepcionesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    private Convenio $convenio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();

        $hoy = CarbonImmutable::parse('2026-07-14 10:00', 'America/Mazatlan');
        Carbon::setTestNow($hoy);
        CarbonImmutable::setTestNow($hoy);

        // Vigencia del 1 al 31 de julio: hay fechas pasadas, de hoy y futuras dentro del convenio.
        $this->convenio = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Renglón como lo entrega LectorRecepciones (todo texto). */
    private function fila(array $over = []): array
    {
        return array_merge([
            'fila' => 2,
            'convenio_folio' => $this->convenio->folio,
            'fecha' => '2026-07-10',
            'tipo_carga' => 'Caja empacada',
            'cantidad' => '120',
            'kilos' => '1450.50',
            'rezaga_kg' => '35',
            'ticket' => 'T-0001',
        ], $over);
    }

    private function validar(array $filas): array
    {
        return app(ValidadorImportacionRecepciones::class)->validar($this->sbp, $filas);
    }

    private function unica(array $over = []): array
    {
        return $this->validar([$this->fila($over)])['filas'][0];
    }

    public function test_un_renglon_correcto_queda_listo_con_los_datos_para_crear_la_recepcion(): void
    {
        $r = $this->validar([$this->fila()]);

        $f = $r['filas'][0];
        $this->assertSame('lista', $f['estado']);
        $this->assertSame([], $f['errores']);
        $this->assertSame(2, $f['fila']);
        $this->assertSame('Caja empacada', $f['tipo_carga']);
        $this->assertSame([
            'id' => $this->convenio->id, 'folio' => $this->convenio->folio,
            'productor' => 'Productor de prueba', 'moneda' => 'MXN',
        ], $f['convenio']);
        $this->assertSame([
            'convenio_id' => $this->convenio->id,
            'fecha' => '2026-07-10',
            'tipo_carga_id' => TipoCarga::where('enterprise_id', $this->sbp->id)->where('nombre', 'Caja empacada')->value('id'),
            'cantidad' => '120',
            'kilos' => '1450.50',
            'rezaga_kg' => '35',
            'ticket_proveedor' => 'T-0001',
            'notas' => null,
        ], $f['datos']);
        $this->assertSame(1, $r['resumen']['total']);
        $this->assertSame(1, $r['resumen']['listas']);
        $this->assertSame(0, $r['resumen']['con_error']);
        $this->assertSame([], $r['advertencias']);
        $this->assertSame([[
            'convenio_id' => $this->convenio->id, 'folio' => $this->convenio->folio,
            'renglones' => 1, 'cantidad' => 120.0, 'kilos' => 1450.5,
        ]], $r['resumen']['por_convenio']);

        // Los datos sirven tal cual para crear la recepción.
        $recepcion = \App\Models\Abastecimiento\Recepcion::create($f['datos'] + [
            'enterprise_id' => $this->sbp->id, 'folio' => 'RC-9999',
        ]);
        $this->assertSame('120.00', $recepcion->fresh()->cantidad);
    }

    public function test_folio_y_tipo_de_carga_se_resuelven_sin_importar_mayusculas_ni_acentos(): void
    {
        TipoCarga::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Tarima Cañera', 'is_active' => true]);
        $tipo = TipoCarga::where('nombre', 'Tarima Cañera')->first();
        ConvenioPrecio::create([
            'convenio_id' => $this->convenio->id, 'tipo_carga_id' => $tipo->id,
            'precio' => 50, 'vigencia_inicio' => '2026-07-01', 'es_principal' => false,
        ]);

        $f = $this->unica([
            'convenio_folio' => ' '.strtolower($this->convenio->folio).' ',
            'tipo_carga' => 'tarima  CANERA',
        ]);

        $this->assertSame('lista', $f['estado'], implode(' | ', $f['errores']));
        $this->assertSame('Tarima Cañera', $f['tipo_carga']);
    }

    public function test_folio_inexistente_o_de_otra_empresa_se_rechaza_igual(): void
    {
        // Folios por empresa: SBP solo tiene el CV-0001; la otra empresa tiene CV-0001 y CV-0002.
        $this->crearConvenio($this->otra);
        $ajeno = $this->crearConvenio($this->otra);
        $this->assertNotSame($this->convenio->folio, $ajeno->folio);

        foreach ([$ajeno->folio, 'CV-9999'] as $folio) {
            $f = $this->unica(['convenio_folio' => $folio]);
            $this->assertSame('error', $f['estado']);
            $this->assertNull($f['convenio']);
            $this->assertNull($f['datos']);
            $this->assertSame(["No existe el convenio «{$folio}» en esta empresa."], $f['errores']);
        }

        $this->assertSame(['Falta el folio del convenio.'], $this->unica(['convenio_folio' => null])['errores']);
    }

    public function test_tipo_de_carga_inexistente_o_de_otra_empresa(): void
    {
        TipoCarga::create(['enterprise_id' => $this->otra->id, 'nombre' => 'Solo de otra', 'is_active' => true]);

        $f = $this->unica(['tipo_carga' => 'Solo de otra']);
        $this->assertSame('error', $f['estado']);
        $this->assertSame('No existe el tipo de carga «Solo de otra».', $f['errores'][0]);

        $this->assertSame('Falta el tipo de carga.', $this->unica(['tipo_carga' => null])['errores'][0]);
    }

    public function test_convenio_en_borrador_no_recibe_recepciones(): void
    {
        $borrador = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31', 'status' => 'borrador']);

        $f = $this->unica(['convenio_folio' => $borrador->folio]);

        $this->assertSame('error', $f['estado']);
        $this->assertContains('El convenio no está activo.', $f['errores']);
    }

    public function test_fecha_futura_fuera_de_vigencia_e_ilegible(): void
    {
        $futura = $this->unica(['fecha' => '2026-07-20']);
        $this->assertContains('La fecha no puede ser futura.', $futura['errores']);

        $fuera = $this->unica(['fecha' => '2026-06-30']);
        $this->assertSame(['La fecha está fuera del convenio (1 jul a 31 jul).'], $fuera['errores']);

        $ilegible = $this->unica(['fecha' => null, 'fecha_original' => 'ayer']);
        $this->assertSame(['La fecha «ayer» no se entiende; usa AAAA-MM-DD o DD/MM/AAAA.'], $ilegible['errores']);

        $this->assertSame(['Falta la fecha.'], $this->unica(['fecha' => null])['errores']);
        $this->assertStringContainsString('no es válida', $this->unica(['fecha' => '2026-02-31'])['errores'][0]);
    }

    public function test_tipo_de_carga_sin_precio_vigente_en_esa_fecha(): void
    {
        $tipo = TipoCarga::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Tarima', 'is_active' => true]);
        ConvenioPrecio::create([
            'convenio_id' => $this->convenio->id, 'tipo_carga_id' => $tipo->id,
            'precio' => 900, 'vigencia_inicio' => '2026-07-10', 'es_principal' => false,
        ]);

        $antes = $this->unica(['tipo_carga' => 'Tarima', 'fecha' => '2026-07-05']);
        $this->assertSame(['Este tipo de carga no tiene precio en el convenio para esa fecha.'], $antes['errores']);

        $this->assertSame('lista', $this->unica(['tipo_carga' => 'Tarima', 'fecha' => '2026-07-10'])['estado']);

        // Un tipo que el convenio nunca ha tenido.
        TipoCarga::create(['enterprise_id' => $this->sbp->id, 'nombre' => 'Bin', 'is_active' => true]);
        $this->assertSame('error', $this->unica(['tipo_carga' => 'Bin'])['estado']);
    }

    public function test_cantidad_con_decimales_cero_o_faltante(): void
    {
        $this->assertSame(['La cantidad va en números enteros.'], $this->unica(['cantidad' => '10.5'])['errores']);
        $this->assertSame(['La cantidad debe ser mayor a cero.'], $this->unica(['cantidad' => '0'])['errores']);
        $this->assertSame(['La cantidad debe ser mayor a cero.'], $this->unica(['cantidad' => '-3'])['errores']);
        $this->assertSame(['Falta la cantidad.'], $this->unica(['cantidad' => null])['errores']);
        // Un entero escrito con decimales en cero sigue siendo entero.
        $this->assertSame('lista', $this->unica(['cantidad' => '10.0'])['estado']);
    }

    public function test_convenio_por_kilo_exige_kilos(): void
    {
        $porKg = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31', 'unidad' => 'kg']);

        $f = $this->unica(['convenio_folio' => $porKg->folio, 'kilos' => null, 'rezaga_kg' => null]);

        $this->assertSame(['Este convenio se paga por kilo: captura los kilos.'], $f['errores']);
        $this->assertSame('lista', $this->unica(['convenio_folio' => $porKg->folio])['estado']);
    }

    public function test_rezaga_mayor_que_los_kilos_y_rezaga_sin_kilos(): void
    {
        $this->assertSame(
            ['La rezaga no puede ser mayor que los kilos.'],
            $this->unica(['kilos' => '100', 'rezaga_kg' => '100.01'])['errores'],
        );
        $this->assertSame(
            ['Captura los kilos de báscula para registrar la rezaga.'],
            $this->unica(['kilos' => null, 'rezaga_kg' => '5'])['errores'],
        );
        $this->assertSame('lista', $this->unica(['kilos' => '100', 'rezaga_kg' => '100'])['estado']);
        $this->assertSame(['Los kilos aceptan hasta 2 decimales.'], $this->unica(['kilos' => '100.123', 'rezaga_kg' => null])['errores']);
    }

    public function test_coma_espacios_y_formatos_ambiguos_se_rechazan_sin_adivinar(): void
    {
        foreach (['1,234', '100,5', '1.234,50', '1 234'] as $valor) {
            $cantidad = $this->unica(['cantidad' => $valor]);
            $this->assertSame('error', $cantidad['estado'], $valor);
            $this->assertStringContainsString('punto decimal', $cantidad['errores'][0], $valor);
            $this->assertStringContainsString("«{$valor}»", $cantidad['errores'][0]);

            $kilos = $this->unica(['kilos' => $valor, 'rezaga_kg' => null]);
            $this->assertSame('error', $kilos['estado'], $valor);
            $this->assertStringContainsString('Los kilos', $kilos['errores'][0]);

            $rezaga = $this->unica(['rezaga_kg' => $valor]);
            $this->assertSame('error', $rezaga['estado'], $valor);
            $this->assertStringContainsString('La rezaga', $rezaga['errores'][0]);
        }

        foreach (['abc', '12abc', '1e3', '.5', '5.', '$100', '--5'] as $valor) {
            $f = $this->unica(['cantidad' => $valor]);
            $this->assertSame('error', $f['estado'], $valor);
            $this->assertStringContainsString('no es un número válido', $f['errores'][0], $valor);
        }

        $this->assertStringContainsString('demasiado grande', $this->unica(['cantidad' => '1000000000000'])['errores'][0]);
        $this->assertSame('lista', $this->unica(['cantidad' => '999999999999'])['estado']);
    }

    public function test_renglon_identico_repetido_en_el_archivo_se_rechaza(): void
    {
        $r = $this->validar([
            $this->fila(['fila' => 2]),
            $this->fila(['fila' => 3, 'ticket' => 'T-0002']),
            $this->fila(['fila' => 4]),
            // Mismo contenido con otro formato numérico: sigue siendo idéntico al renglón 3.
            $this->fila(['fila' => 5, 'ticket' => 'T-0002', 'cantidad' => '120.0', 'kilos' => '1450.5']),
        ]);

        $this->assertSame('lista', $r['filas'][0]['estado']);
        $this->assertSame('lista', $r['filas'][1]['estado']);
        $this->assertSame(['Es idéntico al renglón 2 del archivo.'], $r['filas'][2]['errores']);
        $this->assertSame(['Es idéntico al renglón 3 del archivo.'], $r['filas'][3]['errores']);
    }

    public function test_renglones_identicos_sin_ticket_tambien_se_rechazan(): void
    {
        $r = $this->validar([
            $this->fila(['fila' => 2, 'ticket' => null]),
            $this->fila(['fila' => 3, 'ticket' => null]),
        ]);

        $this->assertSame('lista', $r['filas'][0]['estado']);
        $this->assertSame(['Es idéntico al renglón 2 del archivo.'], $r['filas'][1]['errores']);
    }

    public function test_ticket_repetido_en_el_archivo_con_otros_datos(): void
    {
        $r = $this->validar([
            $this->fila(['fila' => 2, 'ticket' => 'T-77']),
            $this->fila(['fila' => 3, 'ticket' => 't-77', 'cantidad' => '50']),
        ]);

        $this->assertSame('lista', $r['filas'][0]['estado']);
        $this->assertSame(
            ['El ticket «t-77» ya aparece en el renglón 2 del archivo para este convenio.'],
            $r['filas'][1]['errores'],
        );
    }

    public function test_el_mismo_ticket_en_otro_convenio_es_valido(): void
    {
        $otro = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']);
        $this->crearRecepcion($otro, ['fecha' => '2026-07-02', 'ticket_proveedor' => 'T-0001']);

        $r = $this->validar([$this->fila(['fila' => 2]), $this->fila(['fila' => 3, 'convenio_folio' => $otro->folio, 'ticket' => 'T-0002'])]);

        $this->assertSame('lista', $r['filas'][0]['estado']);
        $this->assertSame('lista', $r['filas'][1]['estado']);
    }

    public function test_ticket_ya_existente_en_el_convenio_se_rechaza_salvo_que_la_recepcion_este_borrada(): void
    {
        $existente = $this->crearRecepcion($this->convenio, ['fecha' => '2026-07-02', 'ticket_proveedor' => 'T-0001']);
        $borrada = $this->crearRecepcion($this->convenio, ['fecha' => '2026-07-03', 'ticket_proveedor' => 'T-0002']);
        $borrada->delete();

        $f1 = $this->unica(['ticket' => 'T-0001']);
        $this->assertSame(
            ["El ticket «T-0001» ya existe en el convenio {$this->convenio->folio} (recepción {$existente->folio})."],
            $f1['errores'],
        );
        $this->assertNull($f1['datos']);

        $this->assertSame('lista', $this->unica(['ticket' => 'T-0002'])['estado']);
    }

    public function test_renglones_con_y_sin_errores_conservan_su_numero_de_fila(): void
    {
        $r = $this->validar([
            $this->fila(['fila' => 2, 'ticket' => 'A']),
            $this->fila(['fila' => 5, 'convenio_folio' => 'CV-9999', 'ticket' => 'B']),
            $this->fila(['fila' => 9, 'ticket' => 'C', 'cantidad' => '7.5']),
            $this->fila(['fila' => 12, 'ticket' => 'D', 'cantidad' => '30', 'kilos' => '10', 'rezaga_kg' => '1']),
        ]);

        $this->assertSame([2, 5, 9, 12], array_column($r['filas'], 'fila'));
        $this->assertSame(['lista', 'error', 'error', 'lista'], array_column($r['filas'], 'estado'));
        $this->assertSame(4, $r['resumen']['total']);
        $this->assertSame(2, $r['resumen']['listas']);
        $this->assertSame(2, $r['resumen']['con_error']);
        $this->assertNull($r['filas'][1]['datos']);
        $this->assertNotNull($r['filas'][3]['datos']);

        // El resumen por convenio solo cuenta lo que quedó listo.
        $this->assertSame([[
            'convenio_id' => $this->convenio->id, 'folio' => $this->convenio->folio,
            'renglones' => 2, 'cantidad' => 150.0, 'kilos' => 1460.5,
        ]], $r['resumen']['por_convenio']);
    }

    public function test_un_renglon_con_varios_errores_los_lista_todos(): void
    {
        $f = $this->unica(['convenio_folio' => 'CV-9999', 'tipo_carga' => 'Nada', 'cantidad' => '1,5', 'fecha' => null]);

        $this->assertCount(4, $f['errores']);
    }

    public function test_advertencia_fija_cuando_hay_renglones_sin_ticket(): void
    {
        $con = $this->validar([$this->fila()]);
        $this->assertSame([], $con['advertencias']);

        $sin = $this->validar([$this->fila(['fila' => 2, 'ticket' => null]), $this->fila(['fila' => 3, 'ticket' => null, 'cantidad' => '5'])]);
        $this->assertSame(
            ['2 renglones no traen ticket: sin ticket no se puede detectar si el mismo archivo ya se importó antes.'],
            $sin['advertencias'],
        );
    }

    public function test_el_ticket_de_mas_de_60_caracteres_se_rechaza(): void
    {
        $f = $this->unica(['ticket' => str_repeat('T', 61)]);

        $this->assertSame(['El ticket no puede pasar de 60 caracteres.'], $f['errores']);
    }

    public function test_quinientos_renglones_no_hacen_una_consulta_por_renglon(): void
    {
        $otro = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']);
        $this->crearRecepcion($otro, ['fecha' => '2026-07-02', 'ticket_proveedor' => 'EXISTE']);

        $armar = function (int $n) use ($otro) {
            return array_map(fn (int $i) => $this->fila([
                'fila' => $i + 2,
                'convenio_folio' => $i % 2 === 0 ? $this->convenio->folio : $otro->folio,
                'fecha' => sprintf('2026-07-%02d', 1 + ($i % 14)),
                'ticket' => 'TK-'.$i,
                'cantidad' => (string) (10 + $i),
            ]), range(0, $n - 1));
        };

        $consultas = function (array $filas) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $r = $this->validar($filas);
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$total, $r];
        };

        [$pocas] = $consultas($armar(5));
        [$muchas, $r] = $consultas($armar(500));

        $this->assertSame(500, $r['resumen']['listas']);
        $this->assertSame($pocas, $muchas, "5 renglones: {$pocas} consultas; 500 renglones: {$muchas}");
        $this->assertLessThanOrEqual(6, $muchas);
    }

    public function test_valida_lo_que_entrega_el_lector_del_excel(): void
    {
        $archivo = $this->crearXlsx([
            [$this->convenio->folio, '2026-07-10', 'Caja empacada', 120, 1450.5, 35, 'T-0001'],
            [$this->convenio->folio, '10/07/2026', 'caja empacada', '1,500', 100, null, null],
        ]);

        $r = $this->validar(app(LectorRecepciones::class)->leer($archivo));

        $this->assertSame([2, 3], array_column($r['filas'], 'fila'));
        $this->assertSame('lista', $r['filas'][0]['estado']);
        $this->assertSame('error', $r['filas'][1]['estado']);
        $this->assertStringContainsString('punto decimal', $r['filas'][1]['errores'][0]);
        $this->assertCount(1, $r['advertencias']);
    }
}
