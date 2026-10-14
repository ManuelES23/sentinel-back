<?php

namespace App\Support\RH;

use App\Models\Employee;
use App\Services\VacationCalculatorService;

/**
 * Lo único que devuelve el portal de vacaciones (spec §3.5). Lista blanca
 * explícita: nunca se serializa un modelo completo, así que un campo nuevo en
 * employees o users no se filtra solo al portal.
 */
class PresentadorPortalVacaciones
{
    public static function empleado(Employee $empleado): array
    {
        $empleado->loadMissing(['enterprise:id,name', 'position:id,name', 'department:id,name']);

        return [
            'nombre' => $empleado->full_name,
            'numero' => $empleado->employee_number,
            'empresa' => $empleado->enterprise?->name,
            'puesto' => $empleado->position?->name,
            'departamento' => $empleado->department?->name,
            'fecha_ingreso' => $empleado->hire_date?->format('Y-m-d'),
        ];
    }

    public static function saldo(Employee $empleado): array
    {
        $info = VacationCalculatorService::getEmployeeVacationInfo($empleado);

        if (isset($info['error'])) {
            return [
                'disponibles' => 0, 'acumulados' => 0, 'ajustes' => 0, 'usados' => 0, 'en_revision' => 0,
                'antiguedad' => null, 'anios_servicio' => 0, 'proximo_aniversario' => null,
                'dias_para_aniversario' => null, 'dias_proximo_periodo' => null, 'sin_fecha_ingreso' => true,
            ];
        }

        $acumulado = $info['accumulated'];

        return [
            'disponibles' => (int) $acumulado['total_available_days'],
            'acumulados' => (int) $acumulado['total_accumulated_days'],
            'ajustes' => (int) $acumulado['total_adjustment_days'],
            'usados' => (int) $acumulado['total_used_days'],
            'en_revision' => (int) $acumulado['total_pending_days'],
            'antiguedad' => $info['seniority_text'],
            'anios_servicio' => (int) $info['years_of_service'],
            'proximo_aniversario' => $info['next_anniversary'],
            'dias_para_aniversario' => (int) $info['days_until_anniversary'],
            'dias_proximo_periodo' => (int) $info['next_year_entitled_days'],
            'sin_fecha_ingreso' => false,
        ];
    }
}
