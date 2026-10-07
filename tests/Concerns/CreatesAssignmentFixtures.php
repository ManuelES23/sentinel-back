<?php

namespace Tests\Concerns;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\FixedAsset;
use App\Models\SfEmployee;
use Illuminate\Support\Str;

/**
 * Fixtures de asignaciones. Se usa junto con CreatesAssetFixtures
 * (necesita validFixedAssetPayload y las empresas de setUpAssetFixtures).
 */
trait CreatesAssignmentFixtures
{
    protected int $contadorFixtures = 0;

    protected function crearActivo(array $overrides = []): FixedAsset
    {
        $this->contadorFixtures++;

        return FixedAsset::create($this->validFixedAssetPayload(array_merge([
            'code' => sprintf('SF-AF-%06d', 900 + $this->contadorFixtures),
            'name' => 'Laptop '.$this->contadorFixtures,
            'status' => 'disponible',
        ], $overrides)));
    }

    protected function crearEmpleado(Enterprise $empresa, array $overrides = []): Employee
    {
        $this->contadorFixtures++;

        return Employee::create(array_merge([
            'enterprise_id' => $empresa->id,
            'employee_number' => 'EMP-'.$this->contadorFixtures.'-'.Str::random(4),
            'qr_code' => Str::random(32),
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'hire_date' => '2024-01-15',
            'status' => 'active',
        ], $overrides));
    }

    protected function crearEmpleadoSf(Enterprise $empresa, array $overrides = []): SfEmployee
    {
        $this->contadorFixtures++;

        return SfEmployee::create(array_merge([
            'enterprise_id' => $empresa->id,
            'code' => 'SFE-'.$this->contadorFixtures.'-'.Str::random(4),
            'first_name' => 'Luis',
            'last_name' => 'Gómez',
            'hire_date' => '2024-02-01',
            'status' => 'active',
            'position' => 'Jornalero',
            'department' => 'Campo',
        ], $overrides));
    }

    /** Cuerpo mínimo válido para asignar. */
    protected function datosAsignacion(string $tipo, int $id, array $overrides = []): array
    {
        return array_merge([
            'assignee_type' => $tipo,
            'assignee_id' => $id,
            'condition_out' => 'bueno',
            'accessories' => 'Cargador y funda',
        ], $overrides);
    }
}
