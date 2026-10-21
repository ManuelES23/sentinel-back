<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\Employee;
use App\Models\User;
use App\Models\VacationHistoryEntry;
use App\Services\RH\HistorialVacaciones;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Historial de vacaciones que captura RH (formulario e importación del Excel): cuenta como días tomados. */
class HistorialVacacionesTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private const BASE = '/api/grupoesplendido/rh/vacaciones/historico';

    private User $rh;

    private Employee $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->submoduloVacaciones();
        $this->rh = User::factory()->create();
        Sanctum::actingAs($this->rh);
        // Ingreso hace 3 años (fixture): 42 días ganados.
        $this->ana = $this->empleado($this->empresa, ['first_name' => 'Ana', 'last_name' => 'Ruiz']);
    }

    private function servicio(): HistorialVacaciones
    {
        return app(HistorialVacaciones::class);
    }

    private function hace(int $dias): string
    {
        return today()->subDays($dias)->format('Y-m-d');
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'vacation_year' => (int) today()->subDays(40)->year,
            'start_date' => $this->hace(40),
            'end_date' => $this->hace(36),
            'days' => 5,
            'notes' => 'Del Excel',
        ], $cambios);
    }

    // ===== Saldo =====

    public function test_el_historial_cuenta_como_dias_tomados_en_el_saldo(): void
    {
        $this->assertSame(42, VacationCalculatorService::diasDisponibles($this->ana));

        $this->servicio()->crear($this->ana, $this->datos(), $this->rh);
        $this->servicio()->crear($this->ana, $this->datos(['start_date' => null, 'end_date' => null, 'days' => 6]), $this->rh);

        $this->assertSame(31, VacationCalculatorService::diasDisponibles($this->ana));
        $info = VacationCalculatorService::getEmployeeVacationInfo($this->ana);
        $this->assertSame(11, $info['accumulated']['total_used_days']);
        $this->assertSame(11, $info['accumulated']['total_history_days']);
    }

    public function test_borrar_un_periodo_devuelve_los_dias(): void
    {
        $entrada = $this->servicio()->crear($this->ana, $this->datos(), $this->rh);
        $this->servicio()->borrar($entrada);

        $this->assertSame(42, VacationCalculatorService::diasDisponibles($this->ana));
        $this->assertSoftDeleted('vacation_history', ['id' => $entrada->id]);
    }

    // ===== Reglas =====

    public function test_reglas_de_anio_dias_y_fechas(): void
    {
        $casos = [
            [['vacation_year' => (int) today()->year + 1], 'vacation_year', 'El año no puede ser futuro.'],
            [['vacation_year' => 2010], 'vacation_year', 'El año es anterior al ingreso del empleado'],
            [['days' => 0], 'days', 'Los días deben estar entre 1 y 120.'],
            [['days' => 121], 'days', 'Los días deben estar entre 1 y 120.'],
            [['end_date' => null], 'end_date', 'Escribe las dos fechas o ninguna.'],
            [['start_date' => $this->hace(30), 'end_date' => $this->hace(31)], 'end_date', 'La fecha final no puede ser anterior a la inicial.'],
            [['start_date' => $this->hace(1), 'end_date' => today()->addDay()->format('Y-m-d')], 'end_date', 'El historial no admite fechas futuras'],
            [['start_date' => today()->subYears(4)->format('Y-m-d'), 'end_date' => today()->subYears(4)->addDay()->format('Y-m-d'), 'vacation_year' => (int) today()->subYears(3)->year], 'start_date', 'La fecha es anterior al ingreso del empleado.'],
        ];

        foreach ($casos as [$cambios, $campo, $mensaje]) {
            $errores = $this->servicio()->errores($this->ana, $this->datos($cambios));
            $this->assertArrayHasKey($campo, $errores, json_encode($cambios));
            $this->assertStringContainsString($mensaje, $errores[$campo]);
        }

        $this->assertSame([], $this->servicio()->errores($this->ana, $this->datos()));
    }

    public function test_no_se_encima_con_otro_periodo_ni_con_una_solicitud(): void
    {
        $this->servicio()->crear($this->ana, $this->datos(), $this->rh);
        $errores = $this->servicio()->errores($this->ana, $this->datos(['start_date' => $this->hace(38), 'end_date' => $this->hace(30)]));
        $this->assertStringContainsString('Se encima con otro periodo del historial', $errores['start_date']);

        $this->solicitud($this->ana, 'approved', 3, ['start_date' => $this->hace(20), 'end_date' => $this->hace(18)]);
        $errores = $this->servicio()->errores($this->ana, $this->datos(['start_date' => $this->hace(19), 'end_date' => $this->hace(19), 'days' => 1]));
        $this->assertStringContainsString('Se encima con la solicitud VAC-', $errores['start_date']);
    }

    public function test_corregir_un_periodo_no_choca_consigo_mismo(): void
    {
        $entrada = $this->servicio()->crear($this->ana, $this->datos(), $this->rh);

        $entrada = $this->servicio()->actualizar($entrada, $this->datos(['days' => 4, 'end_date' => $this->hace(37)]), $this->rh);

        $this->assertSame(4, $entrada->days);
        $this->assertSame($this->rh->id, (int) $entrada->updated_by);
    }

    // ===== API de RH =====

    public function test_view_consulta_y_edit_captura_corrige_y_borra(): void
    {
        $this->getJson(self::BASE.'?employee_id='.$this->ana->id)->assertForbidden();
        $this->concederVacaciones($this->rh, 'view');
        $this->getJson(self::BASE.'?employee_id='.$this->ana->id)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total_dias', 0);
        $this->postJson(self::BASE, ['employee_id' => $this->ana->id] + $this->datos())->assertForbidden();

        $this->concederVacaciones($this->rh, 'edit');
        $id = $this->postJson(self::BASE, ['employee_id' => $this->ana->id] + $this->datos())
            ->assertCreated()
            ->assertJsonPath('data.days', 5)
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.created_by', $this->rh->name)
            ->json('data.id');

        $this->putJson(self::BASE."/{$id}", $this->datos(['days' => 3]))->assertOk()->assertJsonPath('data.days', 3);
        $this->getJson(self::BASE.'?employee_id='.$this->ana->id)->assertOk()->assertJsonPath('meta.total_dias', 3);
        $this->deleteJson(self::BASE."/{$id}")->assertOk();
        $this->assertSame(0, VacationHistoryEntry::count());
    }

    public function test_la_api_responde_422_con_el_campo_del_error(): void
    {
        $this->concederVacaciones($this->rh, 'edit');

        $this->postJson(self::BASE, ['employee_id' => $this->ana->id] + $this->datos(['end_date' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->postJson(self::BASE, ['employee_id' => $this->ana->id] + $this->datos(['start_date' => '14/03/2025']))
            ->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    }

    // ===== Importación =====

    /** Excel con el formato de RH: «Control de Personal» y «Registro de Vacaciones» (fecha de regreso). */
    private function excelDeRh(array $registros, array $personal = []): UploadedFile
    {
        $libro = new Spreadsheet();
        $control = $libro->getActiveSheet()->setTitle('Control de Personal');
        $control->fromArray(['No.', 'ID-Emp', 'Nombre del empleado', 'Puesto', 'Empresa', 'Área / Departamento', 'Fecha de ingreso', 'Antigüedad (años)', 'Días que corresponden', 'TOTAL ACUMULADO', 'Días disfrutados', 'Días pendientes', 'Periodo', 'Último inicio', 'Próximo periodo', 'Observaciones'], null, 'A1');
        foreach ($personal as $i => $p) {
            $control->fromArray([$i + 1, 99, $p['nombre'], 'X', 'SPLENDID FARMS', 'VENTAS', FechaExcel::PHPToExcel(new \DateTime($p['ingreso'])), 3, 16, 42, 10, $p['pendientes'], '', '', '', $p['obs'] ?? null], null, 'A'.($i + 2));
        }

        $registro = $libro->createSheet()->setTitle('Registro de Vacaciones');
        $registro->fromArray(['No.', 'Nombre del empleado', 'Periodo', 'Fecha de inicio', 'Fecha de regreso', 'Días disfrutados', 'Observaciones'], null, 'A1');
        foreach ($registros as $i => [$nombre, $periodo, $inicio, $regreso, $dias, $nota]) {
            $registro->fromArray([
                $i + 1, $nombre, $periodo,
                $inicio ? FechaExcel::PHPToExcel(new \DateTime($inicio)) : null,
                $regreso ? FechaExcel::PHPToExcel(new \DateTime($regreso)) : null,
                $dias, $nota,
            ], null, 'A'.($i + 2));
        }

        $ruta = tempnam(sys_get_temp_dir(), 'hist').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'Control_de_Vacaciones.xlsx', null, null, true);
    }

    public function test_leer_el_excel_de_rh_propone_empleados_y_marca_cada_fila(): void
    {
        $this->concederVacaciones($this->rh, 'edit');
        $luis = $this->empleado($this->empresa, ['first_name' => 'Luis Alberto', 'last_name' => 'Soto', 'second_last_name' => 'Pérez']);
        $anio = (int) today()->subDays(60)->year;
        $archivo = $this->excelDeRh([
            ['ANA RUIZ', $anio, $this->hace(60), $this->hace(57), 3, null],                                 // lista; último día = regreso − 1
            ['LUIS SOTO', "{$anio}-".($anio + 1), $this->hace(50), $this->hace(48), 2, '(1) 1RO DE ENERO'],     // parcial; revisar por la nota
            ['ANA RUIZ', $anio, $this->hace(60), $this->hace(57), 3, null],                                 // duplicada
            ['ANA RUIZ', $anio, today()->addDays(10)->format('Y-m-d'), today()->addDays(12)->format('Y-m-d'), 2, null], // futura
            ['ANA RUIZ', null, null, null, null, '24 DIAS DE PATERNIDAD'],                                  // paternidad
            ['ANA RUIZ', 2024, null, null, null, '6 DIAS 2024'],                                            // sin fechas ni días
            ['PERSONA NUEVA', $anio, $this->hace(30), $this->hace(29), 1, null],                            // sin coincidencia
        ], [['nombre' => 'ANA RUIZ', 'ingreso' => '2019-01-01', 'pendientes' => 12, 'obs' => 'SE LE PAGARON 5 DIAS']]);

        $datos = $this->post(self::BASE.'/importar/leer', ['archivo' => $archivo], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('rh', $datos['formato']);
        $this->assertSame('Registro de Vacaciones', $datos['hoja']);
        $filas = collect($datos['filas'])->keyBy('fila');
        $this->assertSame(['lista', $this->hace(60), $this->hace(58), 3], [$filas[2]['estado'], $filas[2]['start_date'], $filas[2]['end_date'], $filas[2]['days']]);
        $this->assertSame('revisar', $filas[3]['estado']);
        $this->assertSame($anio, $filas[3]['vacation_year']);
        $this->assertStringContainsString('Duplicada en el archivo (fila 2)', $filas[4]['avisos'][0]);
        $this->assertStringContainsString('Fecha futura', $filas[5]['avisos'][0]);
        $this->assertStringContainsString('paternidad', $filas[6]['avisos'][0]);
        $this->assertSame('revisar', $filas[7]['estado']);
        $this->assertSame('omitida', $filas[4]['estado']);

        $empleados = collect($datos['empleados'])->keyBy('nombre');
        $this->assertSame(['exacta', $this->ana->id], [$empleados['ANA RUIZ']['coincidencia'], $empleados['ANA RUIZ']['employee_id']]);
        $this->assertSame(12, $empleados['ANA RUIZ']['pendientes_excel']);
        $this->assertStringContainsString('Fecha de ingreso distinta', implode(' ', $empleados['ANA RUIZ']['avisos']));
        $this->assertStringContainsString('SE LE PAGARON 5 DIAS', implode(' ', $empleados['ANA RUIZ']['avisos']));
        $this->assertSame(['parcial', $luis->id], [$empleados['LUIS SOTO']['coincidencia'], $empleados['LUIS SOTO']['employee_id']]);
        $this->assertSame(['ninguna', null], [$empleados['PERSONA NUEVA']['coincidencia'], $empleados['PERSONA NUEVA']['employee_id']]);
    }

    public function test_leer_una_plantilla_csv_busca_por_numero_de_empleado(): void
    {
        $this->concederVacaciones($this->rh, 'edit');
        $inicio = Carbon::parse($this->hace(20));
        $csv = "Número de empleado,Nombre,Año,Desde,Hasta,Días,Nota\n"
            ."{$this->ana->employee_number},Otro nombre,{$inicio->year},{$inicio->format('d/m/Y')},{$inicio->copy()->addDay()->format('d/m/Y')},2,Plantilla\n";
        $ruta = tempnam(sys_get_temp_dir(), 'hist').'.csv';
        file_put_contents($ruta, $csv);

        $datos = $this->post(self::BASE.'/importar/leer', ['archivo' => new UploadedFile($ruta, 'historial.csv', 'text/csv', null, true)], ['Accept' => 'application/json'])
            ->assertOk()->json('data');

        $this->assertSame('plantilla', $datos['formato']);
        $this->assertSame(['numero', $this->ana->id], [$datos['empleados'][0]['coincidencia'], $datos['empleados'][0]['employee_id']]);
        $this->assertSame([$inicio->format('Y-m-d'), $inicio->copy()->addDay()->format('Y-m-d'), 2, 'lista'], [
            $datos['filas'][0]['start_date'], $datos['filas'][0]['end_date'], $datos['filas'][0]['days'], $datos['filas'][0]['estado'],
        ]);
    }

    public function test_un_archivo_que_no_es_del_historial_responde_422(): void
    {
        $this->concederVacaciones($this->rh, 'edit');
        $ruta = tempnam(sys_get_temp_dir(), 'hist').'.csv';
        file_put_contents($ruta, "foo,bar\n1,2\n");

        $this->post(self::BASE.'/importar/leer', ['archivo' => new UploadedFile($ruta, 'otro.csv', 'text/csv', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['archivo']);
    }

    private function fila(array $cambios = []): array
    {
        return array_merge(['fila' => 2, 'employee_id' => $this->ana->id], $this->datos(), $cambios);
    }

    public function test_validar_marca_errores_encimados_del_lote_ya_importadas_y_saldos(): void
    {
        $this->concederVacaciones($this->rh, 'edit');
        $this->servicio()->crear($this->ana, $this->datos(['start_date' => $this->hace(100), 'end_date' => $this->hace(99), 'days' => 2]), $this->rh);

        $datos = $this->postJson(self::BASE.'/importar/validar', ['filas' => [
            $this->fila(),
            $this->fila(['fila' => 3, 'start_date' => $this->hace(38), 'end_date' => $this->hace(37), 'days' => 2]), // se encima con la fila 2
            $this->fila(['fila' => 4, 'start_date' => $this->hace(100), 'end_date' => $this->hace(99), 'days' => 2]), // ya importada
            $this->fila(['fila' => 5, 'start_date' => null, 'end_date' => null, 'days' => 4]),
        ]])->assertOk()->json('data');

        $this->assertSame([], $datos['filas'][0]['errores']);
        $this->assertSame('Se encima con la fila 2 del archivo.', $datos['filas'][1]['errores']['start_date']);
        $this->assertTrue($datos['filas'][2]['ya_importada']);
        $this->assertSame([], $datos['filas'][3]['errores']);
        $this->assertSame(['saldo_actual' => 40, 'dias_a_importar' => 9, 'saldo_despues' => 31], array_intersect_key($datos['saldos'][0], array_flip(['saldo_actual', 'dias_a_importar', 'saldo_despues'])));
        $this->assertSame(1, VacationHistoryEntry::count());
    }

    public function test_importar_es_todo_o_nada_y_reimportar_no_duplica(): void
    {
        $this->concederVacaciones($this->rh, 'edit');
        $buenas = [$this->fila(), $this->fila(['fila' => 5, 'start_date' => null, 'end_date' => null, 'days' => 4])];

        $this->postJson(self::BASE.'/importar', ['filas' => [...$buenas, $this->fila(['fila' => 6, 'days' => 0])], 'archivo' => 'Control.xlsx'])
            ->assertStatus(422)->assertJsonValidationErrors(['filas.2.days']);
        $this->assertSame(0, VacationHistoryEntry::count());

        $this->postJson(self::BASE.'/importar', ['filas' => $buenas, 'archivo' => 'Control.xlsx'])
            ->assertCreated()->assertJsonPath('data.importadas', 2)->assertJsonPath('data.ya_importadas', 0);
        $this->assertSame(['importacion', 'Control.xlsx'], [VacationHistoryEntry::first()->source, VacationHistoryEntry::first()->import_file]);

        $this->postJson(self::BASE.'/importar', ['filas' => $buenas, 'archivo' => 'Control.xlsx'])
            ->assertCreated()->assertJsonPath('data.importadas', 0)->assertJsonPath('data.ya_importadas', 2);
        $this->assertSame(33, VacationCalculatorService::diasDisponibles($this->ana));
    }

    public function test_la_importacion_exige_edit(): void
    {
        $this->concederVacaciones($this->rh, 'view');

        $this->postJson(self::BASE.'/importar/validar', ['filas' => [$this->fila()]])->assertForbidden();
        $this->postJson(self::BASE.'/importar', ['filas' => [$this->fila()]])->assertForbidden();
    }

    // ===== Portal =====

    public function test_el_portal_muestra_el_historial_sin_las_notas_y_el_saldo_lo_cuenta(): void
    {
        $this->servicio()->crear($this->ana, $this->datos(['notes' => 'Le dije 26 días']), $this->rh);
        $token = $this->postJson('/api/portal-vacaciones/login', ['employee_number' => $this->ana->employee_number, 'pin' => '123456'])->json('data.token');
        $encabezados = ['X-Portal-Token' => $token];

        $item = $this->getJson('/api/portal-vacaciones/historial', $encabezados)->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'anio', 'fecha_inicio', 'fecha_fin', 'dias'], array_keys($item));
        $this->assertSame(5, $item['dias']);

        $this->getJson('/api/portal-vacaciones/yo', $encabezados)
            ->assertOk()
            ->assertJsonPath('data.saldo.usados', 5)
            ->assertJsonPath('data.saldo.de_historial', 5)
            ->assertJsonPath('data.saldo.disponibles', 37);
    }

    public function test_crear_fuera_de_la_api_tambien_valida(): void
    {
        $this->expectException(ValidationException::class);
        $this->servicio()->crear($this->ana, $this->datos(['days' => 500]), $this->rh);
    }
}
