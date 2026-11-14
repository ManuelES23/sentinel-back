<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Events\AbastecimientoUpdated;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\User;
use App\Services\Abastecimiento\ImportadorRecepciones;
use App\Services\Abastecimiento\IndicadoresConvenio;
use App\Services\Abastecimiento\LectorRecepciones;
use App\Services\Abastecimiento\ValidadorImportacionRecepciones;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
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
            'rezaga_kg' => '35.00',
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
            $this->assertSame("La cantidad «{$valor}» no es válida: use un número entero, sin comas ni puntos.", $cantidad['errores'][0], $valor);
            $this->assertStringNotContainsString('1234.5', $cantidad['errores'][0]);
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
            $this->assertStringContainsString('La cantidad «'.$valor.'» no es válida', $f['errores'][0], $valor);
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
        $this->assertStringContainsString('número entero', $r['filas'][1]['errores'][0]);
        $this->assertCount(1, $r['advertencias']);
    }

    // ---- Ajustes del validador (decimales, ticket, mensajes) ----

    public function test_los_datos_salen_normalizados_para_decimal_14_2(): void
    {
        $d = $this->unica(['cantidad' => '10.0', 'kilos' => '1450.5', 'rezaga_kg' => '0', 'ticket' => 'N-1'])['datos'];

        $this->assertSame('10', $d['cantidad']);
        $this->assertSame('1450.50', $d['kilos']);
        $this->assertSame('0.00', $d['rezaga_kg']);

        $max = $this->unica(['cantidad' => '999999999999', 'kilos' => '999999999999.99', 'rezaga_kg' => '100.500', 'ticket' => 'N-2']);
        $this->assertSame('lista', $max['estado'], implode(' | ', $max['errores']));
        $this->assertSame('999999999999.99', $max['datos']['kilos']);
        $this->assertSame('100.50', $max['datos']['rezaga_kg']);
        // Cabe en la columna: no hay 500 al insertar.
        $this->assertSame('999999999999.99', Recepcion::create($max['datos'] + ['enterprise_id' => $this->sbp->id, 'folio' => 'RC-9998'])->fresh()->kilos);
    }

    public function test_valores_con_demasiados_decimales_se_rechazan_en_vez_de_desbordar_la_columna(): void
    {
        $desborde = '999999999999.9999999999';

        $this->assertSame(['Los kilos aceptan hasta 2 decimales.'], $this->unica(['kilos' => $desborde, 'rezaga_kg' => null])['errores']);
        $this->assertSame(['La rezaga acepta hasta 2 decimales.'], $this->unica(['rezaga_kg' => $desborde])['errores']);
        $this->assertSame(['La cantidad va en números enteros.'], $this->unica(['cantidad' => $desborde])['errores']);
        $this->assertSame(['La cantidad va en números enteros.'], $this->unica(['cantidad' => '10.001'])['errores']);
        $this->assertSame(['Los kilos aceptan hasta 2 decimales.'], $this->unica(['kilos' => '100.005', 'rezaga_kg' => null])['errores']);
    }

    public function test_tickets_de_otros_alfabetos_no_se_confunden_y_los_espacios_y_mayusculas_si_se_igualan(): void
    {
        $r = $this->validar([
            $this->fila(['fila' => 2, 'ticket' => '日本']),
            $this->fila(['fila' => 3, 'ticket' => '中国', 'cantidad' => '5']),
            $this->fila(['fila' => 4, 'ticket' => 'Ж-1', 'cantidad' => '6']),
            $this->fila(['fila' => 5, 'ticket' => 'Я-1', 'cantidad' => '7']),
            $this->fila(['fila' => 6, 'ticket' => 'Ab   12', 'cantidad' => '8']),
            $this->fila(['fila' => 7, 'ticket' => ' aB 12 ', 'cantidad' => '9']),
        ]);

        $this->assertSame(['lista', 'lista', 'lista', 'lista', 'lista', 'error'], array_column($r['filas'], 'estado'));
        $this->assertStringContainsString('ya aparece en el renglón 6', $r['filas'][5]['errores'][0]);
    }

    public function test_mensajes_numericos_con_buena_gramatica_y_texto_del_usuario_recortado(): void
    {
        $this->assertStringStartsWith('La cantidad «abc» no es válida', $this->unica(['cantidad' => 'abc'])['errores'][0]);
        $this->assertStringStartsWith('Los kilos «abc» no son válidos', $this->unica(['kilos' => 'abc', 'rezaga_kg' => null])['errores'][0]);
        $this->assertStringStartsWith('La rezaga «abc» no es válida', $this->unica(['rezaga_kg' => 'abc'])['errores'][0]);

        $largo = str_repeat('x', 300);
        foreach ([
            $this->unica(['convenio_folio' => $largo]),
            $this->unica(['tipo_carga' => $largo]),
            $this->unica(['fecha' => null, 'fecha_original' => $largo]),
            $this->unica(['cantidad' => $largo]),
        ] as $f) {
            $this->assertStringContainsString(str_repeat('x', 60).'…', $f['errores'][0]);
            $this->assertStringNotContainsString(str_repeat('x', 61), $f['errores'][0]);
        }
    }

    // ---- Endpoints de plantilla, vista previa e importación ----

    private function url(string $ruta = ''): string
    {
        return '/api/splendidbyporvenir/administration/abastecimiento/recepciones'.($ruta === '' ? '' : "/{$ruta}");
    }

    private function comoAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** Sube el Excel con los renglones [folio, fecha, tipo, cantidad, kilos, rezaga, ticket]. */
    private function subir(string $ruta, array $filas): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Accept', 'application/json')
            ->post($this->url($ruta), ['archivo' => $this->crearXlsx($filas)]);
    }

    private function renglon(int $i, ?Convenio $convenio = null): array
    {
        return [($convenio ?? $this->convenio)->folio, sprintf('2026-07-%02d', 1 + ($i % 14)), 'Caja empacada', 10 + $i, 100 + $i, null, "TK-{$i}"];
    }

    public function test_la_plantilla_se_descarga_con_importar(): void
    {
        $this->comoAdmin();

        $r = $this->get($this->url('plantilla'));

        $r->assertOk();
        $this->assertStringContainsString('Plantilla_Recepciones.xlsx', $r->headers->get('content-disposition'));
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('content-type'));
    }

    public function test_sin_el_permiso_importar_las_tres_rutas_responden_403(): void
    {
        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['view', 'create', 'edit', 'delete']);
        Sanctum::actingAs($this->captor);

        $this->get($this->url('plantilla'))->assertForbidden();
        $this->subir('importar/vista-previa', [$this->renglon(1)])->assertForbidden();
        $this->subir('importar', [$this->renglon(1)])->assertForbidden();
        $this->assertSame(0, Recepcion::count());

        $this->otorgarAbs($this->captor, $this->sbp, 'recepciones', ['importar']);
        $this->get($this->url('plantilla'))->assertOk();
        $this->subir('importar', [$this->renglon(1)])->assertCreated();
    }

    public function test_la_vista_previa_no_escribe_nada_ni_gasta_folios(): void
    {
        $this->comoAdmin();
        $this->assertSame(0, DB::table('abs_folios')->where('prefijo', 'RC')->count());

        $r = $this->subir('importar/vista-previa', [
            $this->renglon(1), $this->renglon(2),
            [$this->convenio->folio, '2026-07-02', 'Caja empacada', 'x', 1, null, null],
        ]);

        $r->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.resumen.total', 3)
            ->assertJsonPath('data.resumen.listas', 2)
            ->assertJsonPath('data.resumen.con_error', 1)
            ->assertJsonPath('data.filas.2.estado', 'error');
        $this->assertSame(0, Recepcion::count());
        $this->assertSame(0, DB::table('abs_folios')->where('prefijo', 'RC')->count());
    }

    public function test_importacion_valida_crea_las_recepciones_con_folios_consecutivos_y_autor(): void
    {
        $usuario = $this->comoAdmin();
        $otro = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']);

        $r = $this->subir('importar', [$this->renglon(1), $this->renglon(2, $otro), $this->renglon(3)]);

        $r->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.importadas', 3)
            ->assertJsonPath('data.folios.primero', 'RC-0001')
            ->assertJsonPath('data.folios.ultimo', 'RC-0003')
            ->assertJsonCount(2, 'data.por_convenio');
        $this->assertSame(['RC-0001', 'RC-0002', 'RC-0003'], Recepcion::orderBy('id')->pluck('folio')->all());
        $this->assertSame(['TK-1', 'TK-2', 'TK-3'], Recepcion::orderBy('id')->pluck('ticket_proveedor')->all());
        $this->assertSame([$usuario->id], Recepcion::pluck('created_by')->unique()->values()->all());
        $this->assertSame([$this->sbp->id], Recepcion::pluck('enterprise_id')->unique()->values()->all());
        $this->assertNull(Recepcion::first()->notas);
        $this->assertSame('11.00', Recepcion::first()->cantidad);
    }

    public function test_un_renglon_invalido_entre_quinientos_validos_no_guarda_nada_ni_gasta_folios(): void
    {
        $this->comoAdmin();
        $filas = array_map(fn (int $i) => $this->renglon($i), range(1, 500));
        array_splice($filas, 250, 0, [[$this->convenio->folio, '2026-07-05', 'Caja empacada', '7.5', 100, null, 'MALO']]);

        $r = $this->subir('importar', $filas);

        $r->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonCount(1, 'errors.filas')
            ->assertJsonPath('errors.filas.0.fila', 252)
            ->assertJsonPath('errors.filas.0.errores.0', 'La cantidad va en números enteros.');
        $this->assertSame(0, Recepcion::count());

        // El siguiente alta manual recibe el primer folio.
        $this->postJson($this->url(), [
            'convenio_id' => $this->convenio->id, 'fecha' => '2026-07-10',
            'tipo_carga_id' => TipoCarga::where('nombre', 'Caja empacada')->value('id'), 'cantidad' => 3,
        ])->assertCreated()->assertJsonPath('data.folio', 'RC-0001');
    }

    public function test_quinientos_renglones_validos_se_importan_con_folios_del_1_al_500(): void
    {
        $this->comoAdmin();

        $r = $this->subir('importar', array_map(fn (int $i) => $this->renglon($i), range(1, 500)));

        $r->assertCreated()->assertJsonPath('data.importadas', 500)
            ->assertJsonPath('data.folios.primero', 'RC-0001')->assertJsonPath('data.folios.ultimo', 'RC-0500');
        $this->assertSame(500, Recepcion::count());
        $this->assertSame('TK-500', Recepcion::where('folio', 'RC-0500')->value('ticket_proveedor'));
    }

    public function test_reimportar_el_mismo_archivo_con_tickets_se_rechaza_completo_sin_duplicar(): void
    {
        $this->comoAdmin();
        $filas = [$this->renglon(1), $this->renglon(2), $this->renglon(3)];

        $this->subir('importar', $filas)->assertCreated();
        $r = $this->subir('importar', $filas);

        $r->assertStatus(422)->assertJsonCount(3, 'errors.filas');
        $this->assertStringContainsString('ya existe en el convenio', $r->json('errors.filas.0.errores.0'));
        $this->assertSame(3, Recepcion::count());
        $this->assertSame(['RC-0001', 'RC-0002', 'RC-0003'], Recepcion::orderBy('id')->pluck('folio')->all());
    }

    public function test_archivo_ausente_de_otro_tipo_corrupto_o_mayor_de_5_mb_responde_422(): void
    {
        $this->comoAdmin();
        $cabeceras = ['Accept' => 'application/json'];

        $this->withHeaders($cabeceras)->post($this->url('importar'), [])->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->withHeaders($cabeceras)->post($this->url('importar/vista-previa'), [])->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->withHeaders($cabeceras)->post($this->url('importar'), ['archivo' => UploadedFile::fake()->create('datos.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->withHeaders($cabeceras)->post($this->url('importar'), ['archivo' => UploadedFile::fake()->create('grande.xlsx', 6000)])
            ->assertStatus(422)->assertJsonValidationErrors('archivo');

        $falso = UploadedFile::fake()->createWithContent('falso.xlsx', 'esto no es un excel');
        $this->withHeaders($cabeceras)->post($this->url('importar'), ['archivo' => $falso])->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->assertSame(0, Recepcion::count());
    }

    public function test_el_convenio_de_otra_empresa_es_error_del_renglon(): void
    {
        $this->comoAdmin();
        $this->crearConvenio($this->otra);
        $ajeno = $this->crearConvenio($this->otra);

        $r = $this->subir('importar', [$this->renglon(1), $this->renglon(2, $ajeno)]);

        $r->assertStatus(422)->assertJsonCount(1, 'errors.filas')->assertJsonPath('errors.filas.0.fila', 3);
        $this->assertStringContainsString('No existe el convenio', $r->json('errors.filas.0.errores.0'));
        $this->assertSame(0, Recepcion::count());
    }

    public function test_el_evento_trae_solo_los_convenios_y_la_cantidad_importada(): void
    {
        $this->comoAdmin();
        Event::fake([AbastecimientoUpdated::class]);
        $otro = $this->crearConvenio($this->sbp, ['fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31']);

        $this->subir('importar', [$this->renglon(1), $this->renglon(2, $otro), $this->renglon(3)])->assertCreated();

        Event::assertDispatchedTimes(AbastecimientoUpdated::class, 1);
        Event::assertDispatched(AbastecimientoUpdated::class, function ($e) use ($otro) {
            $this->assertSame('imported', $e->action);
            $this->assertEquals(['cantidad' => 3, 'convenio_ids' => [$this->convenio->id, $otro->id], 'entidad' => 'recepcion'], $e->data);

            return true;
        });
    }

    public function test_las_recepciones_importadas_aparecen_en_semana_indicadores_y_tablero(): void
    {
        $this->comoAdmin();
        // Semana 29: lunes 13 y martes 14 de julio.
        $this->subir('importar', [
            [$this->convenio->folio, '2026-07-13', 'Caja empacada', 40, 400, null, 'S-1'],
            [$this->convenio->folio, '2026-07-14', 'Caja empacada', 60, 600, null, 'S-2'],
        ])->assertCreated();

        $semana = $this->getJson($this->url('semana?temporada_id='.$this->convenio->temporada_id.'&lunes=2026-07-13'))->assertOk();
        $this->assertCount(2, $semana->json('data.recepciones'));
        $this->assertEqualsWithDelta(100, $semana->json('data.convenios.0.recibido_semana'), 0.001);

        $indicadores = IndicadoresConvenio::de(Convenio::with(['precios', 'metas'])->find($this->convenio->id));
        $this->assertEqualsWithDelta(100, $indicadores['recibido'], 0.001);

        $tablero = $this->getJson('/api/splendidbyporvenir/administration/abastecimiento/tablero?temporada_id='.$this->convenio->temporada_id)->assertOk();
        $this->assertEqualsWithDelta(100 * 100, $tablero->json('data.kpis.valor_recibido'), 0.01);
    }

    public function test_un_ticket_nuevo_o_un_convenio_inactivo_cancelan_la_importacion_completa(): void
    {
        $usuario = User::factory()->create(['role' => 'admin']);
        $validado = $this->validar(app(LectorRecepciones::class)->leer($this->crearXlsx([$this->renglon(1), $this->renglon(2)])));
        $importador = app(ImportadorRecepciones::class);

        // Un ticket del archivo se registra después de validar.
        $this->crearRecepcion($this->convenio, ['fecha' => '2026-07-02', 'ticket_proveedor' => 'tk-2']);
        try {
            $importador->importar($this->sbp, $usuario, $validado['filas']);
            $this->fail('Debía rechazar el ticket ya registrado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('TK-2', $e->errors()['archivo'][0]);
        }
        $this->assertSame(1, Recepcion::count());

        // El convenio se suspende después de validar.
        Recepcion::query()->forceDelete();
        $this->convenio->update(['status' => 'suspendido']);
        try {
            $importador->importar($this->sbp, $usuario, $validado['filas']);
            $this->fail('Debía rechazar el convenio inactivo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya no está activo', $e->errors()['archivo'][0]);
        }
        $this->assertSame(0, Recepcion::count());
    }

    public function test_un_convenio_que_pasa_a_por_calibre_despues_de_validar_cancela_la_importacion(): void
    {
        $usuario = User::factory()->create(['role' => 'admin']);
        $validado = $this->validar(app(LectorRecepciones::class)->leer($this->crearXlsx([$this->renglon(1), $this->renglon(2)])));
        $this->assertSame(0, $validado['resumen']['con_error']);

        // Otra sesión cambia el convenio (aún sin recepciones) a por calibre entre la validación y el bloqueo.
        $this->convenio->update(['usa_calibres' => true]);

        try {
            app(ImportadorRecepciones::class)->importar($this->sbp, $usuario, $validado['filas']);
            $this->fail('Debía rechazar el convenio que ahora es por calibre.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('ahora es por calibre', $e->errors()['archivo'][0]);
            $this->assertStringContainsString($this->convenio->folio, $e->errors()['archivo'][0]);
        }
        $this->assertSame(0, Recepcion::count());
    }

    // ---- Ronda 1: re-chequeo de tickets con bloqueo, folios en bloque ----

    /** Consultas SQL que ejecuta $accion, en orden. */
    private function consultas(callable $accion): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        $accion();

        return $sql;
    }

    public function test_el_rechequeo_de_tickets_ocurre_despues_de_tomar_el_folio_y_detecta_el_ticket_confirmado_entre_medias(): void
    {
        $usuario = User::factory()->create(['role' => 'admin']);
        $validado = $this->validar(app(LectorRecepciones::class)->leer($this->crearXlsx([$this->renglon(1), $this->renglon(2)])));
        // Alguien confirma el ticket del renglón 2 después de la vista previa.
        $this->crearRecepcion($this->convenio, ['fecha' => '2026-07-02', 'ticket_proveedor' => 'TK-2']);

        $sql = $this->consultas(function () use ($usuario, $validado) {
            try {
                app(ImportadorRecepciones::class)->importar($this->sbp, $usuario, $validado['filas']);
                $this->fail('Debía rechazar el ticket confirmado entre la vista previa y el guardado.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('renglón 3', $e->errors()['archivo'][0]);
            }
        });

        $contador = null;
        $tickets = null;
        foreach ($sql as $i => $q) {
            if ($contador === null && str_starts_with($q, 'update') && str_contains($q, 'abs_folios')) {
                $contador = $i;
            }
            if ($tickets === null && str_starts_with($q, 'select') && str_contains($q, 'abs_recepciones') && str_contains($q, 'ticket_proveedor')) {
                $tickets = $i;
            }
        }
        $this->assertNotNull($contador, 'Debe reservar los folios.');
        $this->assertNotNull($tickets, 'Debe releer los tickets.');
        $this->assertGreaterThan($contador, $tickets, 'Los tickets se releen después de tomar el contador de folios.');
        $this->assertSame(1, Recepcion::count());
    }

    public function test_los_folios_se_reservan_con_un_solo_update_sin_importar_cuantos_renglones(): void
    {
        $usuario = User::factory()->create(['role' => 'admin']);
        $importar = function (int $desde, int $hasta) use ($usuario) {
            $filas = $this->validar(app(LectorRecepciones::class)->leer(
                $this->crearXlsx(array_map(fn (int $i) => $this->renglon($i), range($desde, $hasta))),
            ))['filas'];

            return $this->consultas(fn () => app(ImportadorRecepciones::class)->importar($this->sbp, $usuario, $filas));
        };

        $importar(1, 3); // crea la fila del contador
        $pocas = $importar(11, 13);
        $muchas = $importar(101, 600); // 500 renglones

        $deFolios = fn (array $sql) => count(array_filter($sql, fn ($q) => str_contains($q, 'abs_folios')));
        $this->assertSame($deFolios($pocas), $deFolios($muchas), 'Las consultas del contador no crecen con los renglones.');
        $this->assertLessThanOrEqual(4, $deFolios($muchas));
        $updates = array_filter($muchas, fn ($q) => str_starts_with($q, 'update') && str_contains($q, 'abs_folios'));
        $this->assertCount(1, $updates);
        // Folios consecutivos y sin huecos entre las dos importaciones.
        $this->assertSame(506, Recepcion::count());
        $this->assertSame('RC-0506', Recepcion::orderByDesc('id')->value('folio'));
        $this->assertSame(range(1, 506), Recepcion::orderBy('id')->pluck('folio')->map(fn ($f) => (int) substr($f, 3))->all());
    }

    public function test_una_importacion_que_se_revierte_no_deja_huecos_de_folio(): void
    {
        $usuario = $this->comoAdmin();
        $existente = $this->crearRecepcion($this->convenio, ['fecha' => '2026-07-02', 'ticket_proveedor' => 'TK-2']); // RC-0001
        $validado = $this->validar(app(LectorRecepciones::class)->leer($this->crearXlsx([$this->renglon(5), $this->renglon(6)])));
        // El ticket llega entre la vista previa y el guardado: la transacción se revierte con los folios ya reservados.
        $this->crearRecepcion($this->convenio, ['fecha' => '2026-07-03', 'ticket_proveedor' => 'TK-6']); // RC-0002

        try {
            app(ImportadorRecepciones::class)->importar($this->sbp, $usuario, $validado['filas']);
            $this->fail('Debía revertirse.');
        } catch (ValidationException) {
        }

        $this->assertSame(2, Recepcion::count());
        $this->assertSame(2, (int) DB::table('abs_folios')->where(['enterprise_id' => $this->sbp->id, 'prefijo' => 'RC'])->value('ultimo'));
        $this->postJson($this->url(), [
            'convenio_id' => $this->convenio->id, 'fecha' => '2026-07-10',
            'tipo_carga_id' => TipoCarga::where('nombre', 'Caja empacada')->value('id'), 'cantidad' => 3,
        ])->assertCreated()->assertJsonPath('data.folio', 'RC-0003');
        $this->assertNotNull($existente);
    }
}
