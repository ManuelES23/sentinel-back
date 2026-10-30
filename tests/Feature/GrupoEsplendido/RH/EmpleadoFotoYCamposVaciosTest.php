<?php

namespace Tests\Feature\GrupoEsplendido\RH;

use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

/** La ficha guardada con foto nueva (multipart) vacía los campos igual que el PUT sin foto. */
class EmpleadoFotoYCamposVaciosTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    private const BASE = '/api/grupoesplendido/rh/empleados';

    private function otraEmpresa(): Enterprise
    {
        return Enterprise::create(['name' => 'Splendid by Porvenir', 'slug' => 'splendidbyporvenir', 'description' => 'Comercio', 'is_active' => true]);
    }

    private function guardarConFoto(int $id, array $datos)
    {
        return $this->post(self::BASE."/{$id}", $datos + [
            '_method' => 'PUT',
            'photo' => UploadedFile::fake()->image('foto.jpg'),
        ], ['Accept' => 'application/json']);
    }

    public function test_con_foto_nueva_regresa_a_los_festivos_de_su_empresa_y_vacia_los_campos(): void
    {
        Storage::fake('public');
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, [
            'holiday_source_enterprise_id' => $this->otraEmpresa()->id,
            'email' => 'ana@ejemplo.mx',
            'phone' => '6441234567',
        ]);

        $this->guardarConFoto($empleado->id, [
            'first_name' => 'Ana',
            'holiday_source_enterprise_id' => '',
            'email' => '',
            'phone' => '',
        ])->assertOk();

        $empleado->refresh();
        $this->assertNull($empleado->holiday_source_enterprise_id);
        $this->assertNull($empleado->email);
        $this->assertNull($empleado->phone);
        $this->assertSame('Ana', $empleado->first_name);
        Storage::disk('public')->assertExists($empleado->photo);
    }

    public function test_con_foto_nueva_tambien_se_guarda_la_excepcion_de_festivos(): void
    {
        Storage::fake('public');
        [, $empresa] = $this->createAuthenticatedRhUser();
        $otra = $this->otraEmpresa();
        $empleado = $this->createEmployee($empresa->id);

        $this->guardarConFoto($empleado->id, ['holiday_source_enterprise_id' => (string) $otra->id])->assertOk();

        $this->assertSame($otra->id, $empleado->fresh()->holiday_source_enterprise_id);
    }

    public function test_un_campo_obligatorio_vacio_con_foto_se_rechaza_como_sin_foto(): void
    {
        Storage::fake('public');
        [, $empresa] = $this->createAuthenticatedRhUser();
        $empleado = $this->createEmployee($empresa->id, ['first_name' => 'Ana']);

        $this->guardarConFoto($empleado->id, ['first_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('first_name');
        $this->assertSame('Ana', $empleado->fresh()->first_name);
    }
}
