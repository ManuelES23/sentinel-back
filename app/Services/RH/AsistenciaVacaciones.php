<?php

namespace App\Services\RH;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Models\VacationRequest;
use Illuminate\Support\Facades\Log;

/** Refleja en attendance_records los días de vacaciones aprobados (spec §4.7). */
class AsistenciaVacaciones
{
    public function __construct(private WorkingDaysCalculator $dias) {}

    public function registrar(VacationRequest $solicitud, User $aprobador): void
    {
        $fechas = $this->dias->calcular($solicitud->employee, $solicitud->start_date, $solicitud->end_date)['descontables'];

        foreach ($fechas as $fecha) {
            $registro = AttendanceRecord::where('employee_id', $solicitud->employee_id)->whereDate('date', $fecha)->first();

            if (! $registro) {
                AttendanceRecord::create([
                    'employee_id' => $solicitud->employee_id,
                    'date' => $fecha,
                    'status' => 'vacation',
                    'approved_by' => $aprobador->id,
                    'notes' => "Vacaciones (solicitud #{$solicitud->id})",
                ]);
            } elseif ($registro->check_in === null) {
                $registro->update(['status' => 'vacation', 'approved_by' => $aprobador->id]);
            } else {
                Log::warning('Vacaciones: el día ya tiene entrada registrada y no se marcó', [
                    'vacation_request_id' => $solicitud->id, 'employee_id' => $solicitud->employee_id, 'fecha' => $fecha,
                ]);
            }
        }
    }

    /** Sin recalcular fechas: el horario o los festivos pudieron cambiar desde la aprobación. */
    public function revertir(VacationRequest $solicitud): void
    {
        AttendanceRecord::where('employee_id', $solicitud->employee_id)
            ->where('status', 'vacation')
            ->whereDate('date', '>=', $solicitud->start_date->format('Y-m-d'))
            ->whereDate('date', '<=', $solicitud->end_date->format('Y-m-d'))
            ->get()
            ->each->delete();
    }
}
