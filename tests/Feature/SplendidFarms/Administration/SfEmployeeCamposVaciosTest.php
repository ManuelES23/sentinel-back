<?php

namespace Tests\Feature\SplendidFarms\Administration;

use App\Models\SfEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

/**
 * La ficha SF se guarda siempre como multipart con POST + _method=PUT. El
 * update solo toca las llaves recibidas: para vaciar un campo el front debe
 * mandarlo en "", que ConvertEmptyStringsToNull vuelve null.
 */
class SfEmployeeCamposVaciosTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private const BASE = '/api/splendidfarms/administration/personal/empleados';

    private function guardar(int $id, array $datos)
    {
        return $this->post(self::BASE."/{$id}", $datos + ['_method' => 'PUT'], ['Accept' => 'application/json']);
    }

    private function empleadoCompleto(int $empresaId, array $extra = []): SfEmployee
    {
        return $this->createSfEmployee($empresaId, $extra + [
            'email' => 'ana@ejemplo.mx',
            'phone' => '6441234567',
            'mobile' => '6449876543',
            'position' => 'Tractorista',
            'salary' => 500,
            'daily_rate' => 100,
            'birth_date' => '1990-05-20',
            'termination_date' => now()->toDateString(),
        ]);
    }

    public function test_un_campo_opcional_en_vacio_se_borra(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->empleadoCompleto($empresa->id);

        $this->guardar($empleado->id, [
            'email' => '',
            'phone' => '',
            'mobile' => '',
            'position' => '',
            'salary' => '',
            'daily_rate' => '',
            'birth_date' => '',
            'termination_date' => '',
        ])->assertOk();

        $empleado->refresh();
        $this->assertNull($empleado->email);
        $this->assertNull($empleado->phone);
        $this->assertNull($empleado->mobile);
        $this->assertNull($empleado->position);
        $this->assertNull($empleado->salary);
        $this->assertNull($empleado->daily_rate);
        $this->assertNull($empleado->birth_date);
        $this->assertNull($empleado->termination_date);
    }

    public function test_con_foto_nueva_tambien_se_borran_los_campos_vacios(): void
    {
        Storage::fake('public');
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->empleadoCompleto($empresa->id);

        $this->guardar($empleado->id, [
            'email' => '',
            'phone' => '',
            'photo' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertOk();

        $empleado->refresh();
        $this->assertNull($empleado->email);
        $this->assertNull($empleado->phone);
        Storage::disk('public')->assertExists($empleado->photo);
    }

    public function test_un_campo_opcional_con_valor_se_guarda_y_uno_no_recibido_se_conserva(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->empleadoCompleto($empresa->id);

        $this->guardar($empleado->id, ['email' => 'nuevo@ejemplo.mx'])->assertOk();

        $empleado->refresh();
        $this->assertSame('nuevo@ejemplo.mx', $empleado->email);
        $this->assertSame('6441234567', $empleado->phone);
    }

    public function test_un_campo_obligatorio_en_vacio_se_rechaza(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->createSfEmployee($empresa->id, ['first_name' => 'Ana']);

        $this->guardar($empleado->id, ['first_name' => '', 'hire_date' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'hire_date']);

        $this->assertSame('Ana', $empleado->fresh()->first_name);
    }

    private function empleadoConIdentificadores(int $empresaId): SfEmployee
    {
        return $this->createSfEmployee($empresaId, [
            'curp' => 'PUVA900520MSRRGN09',
            'rfc' => 'PUVA900520AB1',
            'nss' => '12345678901',
            'checker_key' => 'SF-42',
        ]);
    }

    public function test_un_guardado_parcial_conserva_curp_rfc_nss_y_llave_de_checador(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->empleadoConIdentificadores($empresa->id);

        $this->guardar($empleado->id, ['notes' => 'Otra nota'])->assertOk();

        $empleado->refresh();
        $this->assertSame('Otra nota', $empleado->notes);
        $this->assertSame('PUVA900520MSRRGN09', $empleado->curp);
        $this->assertSame('PUVA900520AB1', $empleado->rfc);
        $this->assertSame('12345678901', $empleado->nss);
        $this->assertSame('SF-42', $empleado->checker_key);
    }

    public function test_curp_rfc_nss_y_llave_de_checador_en_vacio_se_borran(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->empleadoConIdentificadores($empresa->id);

        $this->guardar($empleado->id, ['curp' => '', 'rfc' => '', 'nss' => '', 'checker_key' => ''])->assertOk();

        $empleado->refresh();
        $this->assertNull($empleado->curp);
        $this->assertNull($empleado->rfc);
        $this->assertNull($empleado->nss);
        $this->assertNull($empleado->checker_key);
    }

    public function test_curp_rfc_nss_y_llave_de_checador_recibidos_se_normalizan(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->createSfEmployee($empresa->id);

        $this->guardar($empleado->id, [
            'curp' => 'puva900520msrrgn09',
            'rfc' => ' puva900520ab1 ',
            'nss' => '12345678901',
            'checker_key' => 'sf-42',
        ])->assertOk();

        $empleado->refresh();
        $this->assertSame('PUVA900520MSRRGN09', $empleado->curp);
        $this->assertSame('PUVA900520AB1', $empleado->rfc);
        $this->assertSame('12345678901', $empleado->nss);
        $this->assertSame('SF-42', $empleado->checker_key);
    }

    public function test_el_alta_sin_identificadores_los_deja_vacios(): void
    {
        [, $empresa] = $this->createAuthenticatedUserWithEnterprise();

        $id = $this->post(self::BASE, [
            'enterprise_id' => $empresa->id,
            'first_name' => 'Luis',
            'last_name' => 'Nuevo',
            'hire_date' => '2026-10-01',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $empleado = SfEmployee::findOrFail($id);
        $this->assertNull($empleado->curp);
        $this->assertNull($empleado->rfc);
        $this->assertNull($empleado->nss);
        $this->assertNull($empleado->checker_key);
    }
}
