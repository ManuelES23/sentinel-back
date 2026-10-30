<?php

namespace App\Services\RH;

use App\Models\Employee;
use App\Models\Enterprise;

/**
 * De qué empresa salen los festivos de un empleado: su excepción (otra empresa) → la empresa que
 * sigue su propia empresa → su empresa. Una empresa sigue a otra a un solo nivel (la pantalla de
 * Festivos no deja encadenarlas), así que no hay recursión.
 */
class EmpresaDeFestivos
{
    public function deEmpleado(Employee $empleado): int
    {
        return $this->deEmpresa((int) ($empleado->holiday_source_enterprise_id ?: $empleado->enterprise_id));
    }

    public function deEmpresa(int $empresaId): int
    {
        return (int) (Enterprise::whereKey($empresaId)->value('holiday_source_enterprise_id') ?: $empresaId);
    }
}
