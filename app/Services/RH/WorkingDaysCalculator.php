<?php

namespace App\Services\RH;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Días de un rango que se descuentan del saldo de vacaciones: los que el
 * empleado trabajaría según su horario y que no son festivo para él (EmpresaDeFestivos).
 * El domingo no se trabaja: nunca se descuenta, aunque el horario lo marque
 * (el «Mexicano» lo trae para el checador). Horario: el propio; si no tiene, el
 * predeterminado de su empresa (enterprise_work_schedule.is_default); si no
 * hay, lunes a sábado.
 */
class WorkingDaysCalculator
{
    public function __construct(private EmpresaDeFestivos $empresaDeFestivos) {}

    /** @return array{descontables: list<string>, excluidos: list<array{fecha: string, motivo: string}>} */
    public function calcular(Employee $empleado, Carbon $inicio, Carbon $fin): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $fin = $fin->copy()->startOfDay();
        $resultado = ['descontables' => [], 'excluidos' => []];

        if ($fin->lt($inicio)) {
            return $resultado;
        }

        $horario = $this->horarioDe($empleado);
        // Los festivos de su empresa, de la que esta sigue o de su excepción (EmpresaDeFestivos).
        $festivos = Holiday::paraEmpresa($this->empresaDeFestivos->deEmpleado($empleado))
            ->whereBetween('date', [$inicio->format('Y-m-d'), $fin->format('Y-m-d')])
            ->get()
            ->mapWithKeys(fn (Holiday $festivo) => [$festivo->date => $festivo->name])
            ->all();

        foreach (CarbonPeriod::create($inicio, $fin) as $dia) {
            $fecha = $dia->format('Y-m-d');

            if (! $this->trabaja($horario, $dia)) {
                $resultado['excluidos'][] = ['fecha' => $fecha, 'motivo' => 'descanso'];
            } elseif (isset($festivos[$fecha])) {
                $resultado['excluidos'][] = ['fecha' => $fecha, 'motivo' => 'festivo: '.$festivos[$fecha]];
            } else {
                $resultado['descontables'][] = $fecha;
            }
        }

        return $resultado;
    }

    private function horarioDe(Employee $empleado): ?WorkSchedule
    {
        if ($empleado->work_schedule_id && ($propio = WorkSchedule::find($empleado->work_schedule_id))) {
            return $propio;
        }

        return WorkSchedule::whereHas('enterprises', fn ($q) => $q
            ->where('enterprises.id', $empleado->enterprise_id)
            ->where('enterprise_work_schedule.is_default', true))
            ->first();
    }

    private function trabaja(?WorkSchedule $horario, Carbon $dia): bool
    {
        if ($dia->isSunday()) {
            return false;
        }

        if (! $horario) {
            return true;
        }

        return $horario->{WorkSchedule::DAYS[$dia->dayOfWeek].'_start'} !== null;
    }
}
