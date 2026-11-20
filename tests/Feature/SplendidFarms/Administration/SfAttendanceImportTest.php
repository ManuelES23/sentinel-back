<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfAttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

/**
 * Importación del Excel «Transaction» del checador en Asistencia SF.
 * El archivo real trae 3 filas de título antes de los encabezados y claves con
 * ceros a la izquierda; el empleado puede estar guardado sin ceros.
 */
class SfAttendanceImportTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private const URL = '/api/splendidfarms/administration/personal/asistencia/importar-excel';

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $punches [ID, Date, Time]
     */
    private function transactionFile(array $punches, string $name = 'Transaction (5).xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [
            [''],
            ['Transaction'],
            ['Export Time: 2026-11-19 16:26'],
            ['First Name', 'Last Name', 'ID', 'Department', 'Date', 'Time', 'Weekday', 'Data Source', 'Device Name', 'Device Serial No.', 'Punch State', 'Location', 'Remarks'],
        ];
        foreach ($punches as [$id, $date, $time]) {
            $rows[] = ['NOMBRE', 'APELLIDO', $id, 'CAMPO', $date, $time, 'Sunday', 'Device', 'Empaque Charay', 'FV7061234', '--', '--', '--'];
        }
        foreach ($rows as $i => $row) {
            foreach ($row as $j => $value) {
                // Como texto, igual que el export real ("0000000023", "2026-11-01", "05:30").
                $sheet->setCellValueExplicit([$j + 1, $i + 1], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'sfxl') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_reconoce_clave_con_ceros_a_la_izquierda(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['checker_key' => '153']);

        $response = $this->post(self::URL, [
            'file' => $this->transactionFile([['0000000153', '2026-11-01', '06:44']]),
            'enterprise_id' => $enterprise->id,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.not_matched', 0);
        $this->assertSame(1, SfAttendanceRecord::where('sf_employee_id', $employee->id)->count());
    }

    public function test_agrupa_marcaciones_del_dia(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['checker_key' => '0000000023']);

        $this->post(self::URL, [
            'file' => $this->transactionFile([
                ['0000000023', '2026-11-01', '05:30'],
                ['0000000023', '2026-11-01', '12:00'],
                ['0000000023', '2026-11-01', '17:57'],
                ['0000000023', '2026-11-01', '17:57'],
            ]),
            'enterprise_id' => $enterprise->id,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 1);

        $record = SfAttendanceRecord::where('sf_employee_id', $employee->id)->sole();
        $this->assertSame('05:30', $record->check_in->format('H:i'));
        $this->assertSame('17:57', $record->check_out->format('H:i'));
        $this->assertSame('Transaction (5).xlsx', $record->source_file);
    }

    public function test_devuelve_rango_y_claves_sin_coincidencia(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $this->createSfEmployee($enterprise->id, ['checker_key' => '3']);

        $response = $this->post(self::URL, [
            'file' => $this->transactionFile([
                ['0000000003', '2026-11-02', '06:00'],
                ['0000000003', '2026-11-05', '06:00'],
                ['0000000999', '2026-11-03', '06:00'],
            ]),
            'enterprise_id' => $enterprise->id,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.total_rows', 3)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.not_matched', 1)
            ->assertJsonPath('data.unmatched_keys', ['0000000999'])
            ->assertJsonPath('data.unmatched_employees', [['key' => '0000000999', 'name' => 'NOMBRE APELLIDO', 'days' => 1]])
            ->assertJsonPath('data.start_date', '2026-11-02')
            ->assertJsonPath('data.end_date', '2026-11-05')
            ->assertJsonPath('data.imported_dates', ['2026-11-02', '2026-11-05']);
    }

    public function test_no_carga_empleados_de_otra_empresa(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        [, $otra] = $this->createAuthenticatedUserWithEnterprise();
        $ajeno = $this->createSfEmployee($otra->id, ['checker_key' => '77']);

        $this->post(self::URL, [
            'file' => $this->transactionFile([['0000000077', '2026-11-01', '06:00']]),
            'enterprise_id' => $enterprise->id,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.not_matched', 1);

        $this->assertSame(0, SfAttendanceRecord::where('sf_employee_id', $ajeno->id)->count());
    }

    public function test_archivo_sin_renglones_devuelve_422(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();

        $this->post(self::URL, [
            'file' => $this->transactionFile([]),
            'enterprise_id' => $enterprise->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, SfAttendanceRecord::count());
    }

    public function test_rechaza_extension_no_valida(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();

        $this->post(self::URL, [
            'file' => UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf'),
            'enterprise_id' => $enterprise->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_nomina_procesar_archivo_sigue_guardando_asistencia(): void
    {
        [, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $employee = $this->createSfEmployee($enterprise->id, ['checker_key' => '153']);

        $this->post('/api/splendidfarms/administration/personal/nomina/procesar-archivo', [
            'file' => $this->transactionFile([
                ['0000000153', '2026-11-01', '06:00'],
                ['0000000153', '2026-11-01', '15:00'],
                ['0000000888', '2026-11-01', '06:00'],
            ]),
            'enterprise_id' => $enterprise->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.import.created', 1)
            ->assertJsonPath('data.import.not_matched', 1)
            ->assertJsonPath('data.import.unmatched_keys', ['0000000888']);

        $this->assertSame(1, SfAttendanceRecord::where('sf_employee_id', $employee->id)->count());
    }
}
