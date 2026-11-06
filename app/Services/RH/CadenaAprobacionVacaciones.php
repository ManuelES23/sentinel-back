<?php

namespace App\Services\RH;

use App\Models\ApprovalFlowStep;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use Illuminate\Support\Collection;

/**
 * La cadena de cada solicitud se congela al crearla (spec §5.2): un paso por
 * cada step_order configurado (reglas con el mismo número = alternativas),
 * numerados 1..n, más un paso final de RH. Avanzar salta los pasos sin nadie
 * elegible; el paso de RH solo espera si ningún paso anterior aprobó o si el último que aprobó
 * fue un visto bueno de RH (que nunca cierra la solicitud).
 */
class CadenaAprobacionVacaciones
{
    public const DESCRIPCION_RH = 'RH Grupo Espléndido';

    public const SIN_APROBADOR = 'Sin aprobador con usuario';

    public const NO_REQUERIDO = 'No requerido';

    /** Único paso de una solicitud que RH capturó ya autorizada (sin cadena). */
    public const YA_AUTORIZADA = 'Autorizada antes de capturarse';

    public function __construct(private AprobadorVacaciones $aprobador) {}

    public function construir(VacationRequest $solicitud): ?VacationRequestApproval
    {
        $empresaId = (int) ($solicitud->enterprise_id ?? $solicitud->employee->enterprise_id);
        $orden = 0;

        foreach ($this->aprobador->pasosPara($empresaId) as $reglas) {
            $orden++;
            $solicitud->approvals()->create([
                'step_order' => $orden,
                // description es string(255): muchas reglas en el mismo paso pueden exceder la columna.
                'description' => mb_substr($reglas->map(fn (ApprovalFlowStep $regla) => $regla->approver_description)->unique()->implode(' o '), 0, 255),
                'rules' => $reglas->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'is_rh_final' => false,
                // Paso de visto bueno de RH: todas sus reglas son rh_review (la configuración impide mezclarlas).
                'is_rh_review' => $reglas->every(fn (ApprovalFlowStep $regla) => $regla->approver_type === ApprovalFlowStep::TYPE_RH_REVIEW),
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
                if ($this->respaldoNoRequerido($pasos)) {
                    $this->saltar($paso, self::NO_REQUERIDO);
                    break;
                }

                $solicitud->update(['current_step' => $paso->step_order]);

                return $paso;
            }

            if (! $this->aprobador->hayQuienDecida($solicitud, $paso)) {
                $this->saltar($paso, self::SIN_APROBADOR);

                continue;
            }

            $solicitud->update(['current_step' => $paso->step_order]);

            return $paso;
        }

        $solicitud->update(['current_step' => null]);

        return null;
    }

    /**
     * Simula, sin escribir, qué paso quedaría en espera si se aprobara el actual (mismos saltos y
     * misma regla del respaldo que `avanzar`, tratando al actual como aprobado). null = la
     * solicitud quedaría aprobada. No sabe quién aprobaría el actual, así que un paso posterior
     * cuyo único aprobador sea esa persona se muestra como si hubiera quien lo decida.
     */
    public function siguienteSiAprueba(VacationRequest $solicitud): ?VacationRequestApproval
    {
        $actual = $this->pasoActual($solicitud);
        if (! $actual) {
            return null;
        }

        $pasos = $solicitud->approvals()->get();
        // El actual cuenta como aprobado (copia en memoria: no se escribe nada).
        $aprobados = $pasos->where('decision', VacationRequestApproval::APPROVED)
            ->push((clone $actual)->setAttribute('decision', VacationRequestApproval::APPROVED));

        foreach ($pasos as $paso) {
            if ($paso->step_order <= $actual->step_order || $paso->decision !== VacationRequestApproval::WAITING) {
                continue;
            }

            if ($paso->is_rh_final) {
                return $this->respaldoNoRequerido($aprobados) ? null : $paso;
            }

            if ($this->aprobador->hayQuienDecida($solicitud, $paso)) {
                return $paso;
            }
        }

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

    /**
     * El respaldo de RH se salta solo si hubo una aprobación y la última (la de mayor step_order)
     * no fue un visto bueno: el visto bueno nunca cierra la solicitud, el director tiene la última palabra.
     */
    private function respaldoNoRequerido(Collection $pasos): bool
    {
        $ultimo = $pasos->where('decision', VacationRequestApproval::APPROVED)->sortByDesc('step_order')->first();

        return $ultimo !== null && ! $ultimo->is_rh_review;
    }

    private function saltar(VacationRequestApproval $paso, string $motivo): void
    {
        $paso->update(['decision' => VacationRequestApproval::SKIPPED, 'comment' => $motivo, 'decided_at' => now()]);
    }
}
