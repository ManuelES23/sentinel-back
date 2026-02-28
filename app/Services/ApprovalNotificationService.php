<?php

namespace App\Services;

use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\Department;

/**
 * Servicio para notificar a los aprobadores cuando se crea una solicitud pendiente.
 *
 * Respeta el scope de cada aprobador:
 *   - own_department: solo recibe si el solicitante es de su departamento
 *   - child_departments: si es de su departamento o un subdepartamento
 *   - enterprise: recibe todas las solicitudes de la empresa
 */
class ApprovalNotificationService
{
    /**
     * Notificar a los aprobadores relevantes sobre una nueva solicitud.
     *
     * @param string $processCode  Código del proceso (e.g. 'vacation_requests')
     * @param Employee $requester  Empleado que solicita
     * @param string $title        Título de la notificación
     * @param string $message      Mensaje de la notificación
     * @param string|null $actionUrl URL de acción (botón en la notificación)
     * @param string $category     Categoría (vacation, rh, etc.)
     */
    public static function notifyApprovers(
        string $processCode,
        Employee $requester,
        string $title,
        string $message,
        ?string $actionUrl = null,
        string $category = 'info'
    ): void {
        $process = ApprovalProcess::findByCode($processCode);

        if (!$process || !$process->is_active || !$process->requires_approval) {
            return;
        }

        $enterpriseId = $requester->enterprise_id;

        // Obtener IDs de posiciones aprobadoras
        $approverPositionIds = $process->getApprovers($enterpriseId);

        if (empty($approverPositionIds)) {
            return;
        }

        // Buscar empleados con esas posiciones en la misma empresa
        $approverEmployees = Employee::whereIn('position_id', $approverPositionIds)
            ->where('enterprise_id', $enterpriseId)
            ->where('status', 'active')
            ->where('id', '!=', $requester->id) // No notificar al solicitante
            ->with(['position', 'user', 'department'])
            ->get();

        // Obtener los steps del proceso para determinar el scope de cada aprobador
        $steps = $process->activeSteps()
            ->where(function ($query) use ($enterpriseId) {
                $query->whereNull('enterprise_id');
                if ($enterpriseId) {
                    $query->orWhere('enterprise_id', $enterpriseId);
                }
            })
            ->get();

        /** @var Employee $approver */
        foreach ($approverEmployees as $approver) {
            if (!$approver->user) {
                continue; // Sin usuario vinculado, no puede recibir notificación
            }

            // Determinar el scope de este aprobador
            $approverScope = self::getApproverScope($approver, $steps);

            // Verificar si el solicitante cae dentro del scope del aprobador
            if (!self::isRequesterInScope($requester, $approver, $approverScope)) {
                continue;
            }

            // Enviar notificación
            $notification = NotificationService::toUser($approver->user)
                ->icon('ClipboardCheck', 'amber')
                ->high();

            if ($actionUrl) {
                $notification->withAction($actionUrl, 'Ver solicitud');
            }

            // Enviar según categoría
            match ($category) {
                'vacation' => $notification->vacation($title, $message),
                'rh' => $notification->rh($title, $message),
                'alert' => $notification->alert($title, $message),
                default => $notification->info($title, $message),
            };
        }
    }

    /**
     * Determinar el scope de un aprobador según los steps del proceso
     */
    private static function getApproverScope(Employee $approver, $steps): string
    {
        $position = $approver->position;

        if (!$position) {
            return 'own_department';
        }

        // Primero: el scope del puesto del empleado
        $positionScope = $position->approval_scope ?? 'own_department';

        // Buscar en los steps si alguno coincide con este aprobador
        $stepScope = null;
        /** @var \App\Models\ApprovalFlowStep $step */
        foreach ($steps as $step) {
            if ($step->matchesEmployee($approver, $position)) {
                if ($step->approval_scope === 'enterprise') {
                    $stepScope = 'enterprise';
                    break;
                } elseif ($step->approval_scope === 'child_departments' && $stepScope !== 'enterprise') {
                    $stepScope = 'child_departments';
                } elseif (!$stepScope) {
                    $stepScope = $step->approval_scope;
                }
            }
        }

        if (!$stepScope) {
            return $positionScope;
        }

        // Usar el menor de ambos (intersecar scope de puesto y step)
        $order = ['own_department' => 1, 'child_departments' => 2, 'enterprise' => 3];
        $val1 = $order[$positionScope] ?? 1;
        $val2 = $order[$stepScope] ?? 1;

        return $val1 <= $val2 ? $positionScope : $stepScope;
    }

    /**
     * Verificar si el solicitante cae dentro del scope del aprobador
     */
    private static function isRequesterInScope(Employee $requester, Employee $approver, string $scope): bool
    {
        switch ($scope) {
            case 'own_department':
                return $requester->department_id === $approver->department_id;

            case 'child_departments':
                $departmentIds = self::getDepartmentAndChildIds($approver->department_id);
                return in_array($requester->department_id, $departmentIds);

            case 'enterprise':
                return $requester->enterprise_id === $approver->enterprise_id;

            default:
                return false;
        }
    }

    /**
     * Obtener IDs de departamento y sus hijos recursivamente
     */
    private static function getDepartmentAndChildIds(int $departmentId): array
    {
        $ids = [$departmentId];

        $children = Department::where('parent_id', $departmentId)->pluck('id')->toArray();

        foreach ($children as $childId) {
            $ids = array_merge($ids, self::getDepartmentAndChildIds($childId));
        }

        return $ids;
    }
}
