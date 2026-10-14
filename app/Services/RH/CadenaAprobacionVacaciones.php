<?php

namespace App\Services\RH;

use App\Models\ApprovalFlowStep;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;

/**
 * La cadena de cada solicitud se congela al crearla (spec §5.2): un paso por
 * cada step_order configurado (reglas con el mismo número = alternativas),
 * numerados 1..n, más un paso final de RH. Avanzar salta los pasos sin nadie
 * elegible; el paso de RH solo espera si ningún paso anterior aprobó.
 */
class CadenaAprobacionVacaciones
{
    public const DESCRIPCION_RH = 'RH Grupo Espléndido';

    public const SIN_APROBADOR = 'Sin aprobador con usuario';

    public const NO_REQUERIDO = 'No requerido';

    public function __construct(private AprobadorVacaciones $aprobador) {}

    public function construir(VacationRequest $solicitud): ?VacationRequestApproval
    {
        $empresaId = (int) ($solicitud->enterprise_id ?? $solicitud->employee->enterprise_id);
        $orden = 0;

        foreach ($this->aprobador->pasosPara($empresaId) as $reglas) {
            $orden++;
            $solicitud->approvals()->create([
                'step_order' => $orden,
                'description' => $reglas->map(fn (ApprovalFlowStep $regla) => $regla->approver_description)->unique()->implode(' o '),
                'rules' => $reglas->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'is_rh_final' => false,
                'decision' => VacationRequestApproval::WAITING,
            ]);
        }

        $solicitud->approvals()->create([
            'step_order' => $orden + 1,
            'description' => self::DESCRIPCION_RH,
            'rules' => [],
            'is_rh_final' => true,
            'decision' => VacationRequestApproval::WAITING,
        ]);

        return $this->avanzar($solicitud);
    }

    /** Deja la solicitud en su siguiente paso con aprobador. null = la cadena terminó aprobada. */
    public function avanzar(VacationRequest $solicitud): ?VacationRequestApproval
    {
        $pasos = $solicitud->approvals()->get();

        foreach ($pasos as $paso) {
            if ($paso->decision !== VacationRequestApproval::WAITING) {
                continue;
            }

            if ($paso->is_rh_final) {
                if ($pasos->contains('decision', VacationRequestApproval::APPROVED)) {
                    $this->saltar($paso, self::NO_REQUERIDO);
                    break;
                }

                $solicitud->update(['current_step' => $paso->step_order]);

                return $paso;
            }

            if ($this->aprobador->elegiblesDelPaso($solicitud, $paso)->isEmpty()) {
                $this->saltar($paso, self::SIN_APROBADOR);

                continue;
            }

            $solicitud->update(['current_step' => $paso->step_order]);

            return $paso;
        }

        $solicitud->update(['current_step' => null]);

        return null;
    }

    public function pasoActual(VacationRequest $solicitud): ?VacationRequestApproval
    {
        if ($solicitud->current_step === null) {
            return null;
        }

        return $solicitud->approvals()
            ->where('step_order', $solicitud->current_step)
            ->where('decision', VacationRequestApproval::WAITING)
            ->first();
    }

    /** Cierra los pasos en espera (cancelación, rechazo o resolución de RH). */
    public function cerrarPendientes(VacationRequest $solicitud, string $motivo): void
    {
        $solicitud->approvals()->where('decision', VacationRequestApproval::WAITING)->get()
            ->each(fn (VacationRequestApproval $paso) => $this->saltar($paso, $motivo));
    }

    private function saltar(VacationRequestApproval $paso, string $motivo): void
    {
        $paso->update(['decision' => VacationRequestApproval::SKIPPED, 'comment' => $motivo, 'decided_at' => now()]);
    }
}
