<?php

namespace App\Http\Controllers\Api;

use App\Events\VacationRequestUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryMovementController;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\EmployeeIncident;
use App\Models\Enterprise;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\VacationBalance;
use App\Models\VacationRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PendingApprovalController extends Controller
{
    /**
     * Obtener resumen de pendientes por aprobar para el usuario autenticado
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $hasTransferPermission = $this->hasTransferApprovalPermission($request, $user);

        // Sin empleado solo se habilita validación de transferencias por permiso explícito del submódulo.
        if (! $employee && ! $hasTransferPermission) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => 0,
                    'processes' => [],
                    'can_approve' => false,
                ],
            ]);
        }

        if (! $employee && $hasTransferPermission) {
            $process = $this->getInventoryApprovalProcess();
            $count = $this->countPendingTransferReceptionsByPermission($request, $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => $count,
                    'processes' => [[
                        'process_id' => $process?->id,
                        'code' => ApprovalProcess::INVENTORY_MOVEMENTS,
                        'name' => $process?->name ?? 'Movimientos de inventario',
                        'module' => $process?->module ?? 'inventario',
                        'description' => $process?->description ?? 'Validación de transferencias entre entidades',
                        'pending_count' => $count,
                        'scope' => 'enterprise',
                        'route' => $this->getProcessRoute(ApprovalProcess::INVENTORY_MOVEMENTS),
                    ]],
                    'can_approve' => $count > 0,
                    'approver_info' => [
                        'position' => null,
                        'department' => null,
                        'hierarchy_level' => null,
                        'approval_scope' => 'enterprise',
                    ],
                ],
            ]);
        }

        $employee->load('position', 'department');
        $enterpriseId = $employee->enterprise_id;

        // Obtener procesos activos que requieren aprobación
        $processes = ApprovalProcess::active()
            ->requiresApproval()
            ->with(['activeSteps' => function ($query) use ($enterpriseId) {
                $query->where(function ($q) use ($enterpriseId) {
                    $q->whereNull('enterprise_id');
                    if ($enterpriseId) {
                        $q->orWhere('enterprise_id', $enterpriseId);
                    }
                });
            }])
            ->get();

        $result = [];
        $totalPending = 0;

        /** @var ApprovalProcess $process */
        foreach ($processes as $process) {
            $canApproveProcess = $this->canApproveProcess(
                $request,
                $user,
                $employee,
                $enterpriseId,
                $process
            );

            if (! $canApproveProcess) {
                continue;
            }

            // Determinar el alcance (scope) del empleado para este proceso
            $scope = $process->code === ApprovalProcess::INVENTORY_MOVEMENTS && $hasTransferPermission
                ? 'enterprise'
                : $this->getEmployeeScope($process, $employee);

            // Contar pendientes según el tipo de proceso
            $count = $this->countPendingItems($process->code, $employee, $scope);

            if ($count >= 0) {
                $result[] = [
                    'process_id' => $process->id,
                    'code' => $process->code,
                    'name' => $process->name,
                    'module' => $process->module,
                    'description' => $process->description,
                    'pending_count' => $count,
                    'scope' => $scope,
                    'route' => $this->getProcessRoute($process->code),
                ];
                $totalPending += $count;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_pending' => $totalPending,
                'processes' => $result,
                'can_approve' => count($result) > 0,
                'approver_info' => [
                    'position' => $employee->position->name ?? null,
                    'department' => $employee->department->name ?? null,
                    'hierarchy_level' => $employee->position->hierarchy_level ?? null,
                    'approval_scope' => $employee->position->approval_scope ?? 'own_department',
                ],
            ],
        ]);
    }

    /**
     * Obtener lista detallada de pendientes por aprobar
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $hasTransferPermission = $this->hasTransferApprovalPermission($request, $user);

        if (! $employee && ! $hasTransferPermission) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => 0,
                    'processes' => [],
                ],
            ]);
        }

        if (! $employee && $hasTransferPermission) {
            $process = $this->getInventoryApprovalProcess();
            $items = $this->getPendingTransferReceptionsByPermission($request, $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => count($items),
                    'processes' => [[
                        'process_id' => $process?->id,
                        'code' => ApprovalProcess::INVENTORY_MOVEMENTS,
                        'name' => $process?->name ?? 'Movimientos de inventario',
                        'module' => $process?->module ?? 'inventario',
                        'description' => $process?->description ?? 'Validación de transferencias entre entidades',
                        'pending_count' => count($items),
                        'items' => $items,
                        'scope' => 'enterprise',
                        'route' => $this->getProcessRoute(ApprovalProcess::INVENTORY_MOVEMENTS),
                    ]],
                ],
            ]);
        }

        $employee->load('position', 'department');
        $enterpriseId = $employee->enterprise_id;
        $filterProcess = $request->query('process');

        $processes = ApprovalProcess::active()
            ->requiresApproval()
            ->when($filterProcess, fn ($q) => $q->where('code', $filterProcess))
            ->get();

        $result = [];
        $totalPending = 0;

        /** @var ApprovalProcess $process */
        foreach ($processes as $process) {
            $canApproveProcess = $this->canApproveProcess(
                $request,
                $user,
                $employee,
                $enterpriseId,
                $process
            );

            if (! $canApproveProcess) {
                continue;
            }

            $scope = $process->code === ApprovalProcess::INVENTORY_MOVEMENTS && $hasTransferPermission
                ? 'enterprise'
                : $this->getEmployeeScope($process, $employee);
            $items = $this->getPendingItems($process->code, $employee, $scope);

            $result[] = [
                'process_id' => $process->id,
                'code' => $process->code,
                'name' => $process->name,
                'module' => $process->module,
                'description' => $process->description,
                'pending_count' => count($items),
                'items' => $items,
                'scope' => $scope,
                'route' => $this->getProcessRoute($process->code),
            ];
            $totalPending += count($items);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_pending' => $totalPending,
                'processes' => $result,
            ],
        ]);
    }

    /**
     * Determinar el alcance de aprobación del empleado para un proceso.
     *
     * Se usa el approval_scope del Puesto (Position) del empleado como fuente principal:
     *   - own_department: Solo ve solicitudes de su propio departamento
     *   - child_departments: Ve su departamento + departamentos hijos
     *   - enterprise: Ve todos los departamentos de la empresa (ej. Director General)
     *
     * Si el paso de aprobación (ApprovalFlowStep) define un alcance más restrictivo,
     * se usa el más restrictivo entre ambos.
     */
    private function getEmployeeScope(ApprovalProcess $process, Employee $employee): string
    {
        $position = $employee->position;

        if (! $position) {
            return 'own_department';
        }

        // Alcance base: viene del puesto del empleado
        $positionScope = $position->approval_scope ?? 'own_department';

        // Buscar si algún step del proceso define un alcance más restrictivo
        $enterpriseId = $employee->enterprise_id;
        $steps = $process->activeSteps()
            ->where(function ($query) use ($enterpriseId) {
                $query->whereNull('enterprise_id');
                if ($enterpriseId) {
                    $query->orWhere('enterprise_id', $enterpriseId);
                }
            })
            ->get();

        $stepScope = null;
        /** @var \App\Models\ApprovalFlowStep $step */
        foreach ($steps as $step) {
            if ($step->matchesEmployee($employee, $position)) {
                // Tomar el alcance más amplio de los steps que coinciden
                if ($step->approval_scope === 'enterprise') {
                    $stepScope = 'enterprise';
                    break;
                } elseif ($step->approval_scope === 'child_departments' && $stepScope !== 'enterprise') {
                    $stepScope = 'child_departments';
                } elseif (! $stepScope) {
                    $stepScope = $step->approval_scope;
                }
            }
        }

        // Si no hay step que aplique, usar solo el scope del puesto
        if (! $stepScope) {
            return $positionScope;
        }

        // Usar el MÁS RESTRICTIVO entre el puesto y el step
        return $this->getMostRestrictiveScope($positionScope, $stepScope);
    }

    /**
     * Obtener el alcance más restrictivo entre dos scopes.
     * Orden de amplitud: own_department < child_departments < enterprise
     */
    private function getMostRestrictiveScope(string $scope1, string $scope2): string
    {
        $order = [
            'own_department' => 1,
            'child_departments' => 2,
            'enterprise' => 3,
        ];

        $val1 = $order[$scope1] ?? 1;
        $val2 = $order[$scope2] ?? 1;

        return $val1 <= $val2 ? $scope1 : $scope2;
    }

    /**
     * Contar items pendientes según proceso
     */
    private function countPendingItems(string $processCode, Employee $employee, string $scope): int
    {
        return match ($processCode) {
            ApprovalProcess::VACATION_REQUESTS => $this->countPendingVacations($employee, $scope),
            ApprovalProcess::INCIDENTS => $this->countPendingIncidents($employee, $scope),
            ApprovalProcess::PURCHASE_ORDERS => $this->countPendingPurchaseOrders($employee, $scope),
            ApprovalProcess::INVENTORY_MOVEMENTS => $this->countPendingInventoryMovements($employee, $scope),
            default => 0,
        };
    }

    /**
     * Obtener items pendientes detallados según proceso
     */
    private function getPendingItems(string $processCode, Employee $employee, string $scope): array
    {
        return match ($processCode) {
            ApprovalProcess::VACATION_REQUESTS => $this->getPendingVacations($employee, $scope),
            ApprovalProcess::INCIDENTS => $this->getPendingIncidents($employee, $scope),
            ApprovalProcess::PURCHASE_ORDERS => $this->getPendingPurchaseOrders($employee, $scope),
            ApprovalProcess::INVENTORY_MOVEMENTS => $this->getPendingInventoryMovements($employee, $scope),
            default => [],
        };
    }

    // ===== Vacaciones =====

    private function getVacationQuery(Employee $employee, string $scope)
    {
        $query = VacationRequest::where('status', 'pending')
            ->where('employee_id', '!=', $employee->id) // No sus propias solicitudes
            ->whereHas('employee', function ($q) use ($employee) {
                $q->where('enterprise_id', $employee->enterprise_id);
            });

        $this->applyScopeFilter($query, $employee, $scope);

        return $query;
    }

    private function countPendingVacations(Employee $employee, string $scope): int
    {
        return $this->getVacationQuery($employee, $scope)->count();
    }

    private function getPendingVacations(Employee $employee, string $scope): array
    {
        return $this->getVacationQuery($employee, $scope)
            ->with([
                'employee:id,first_name,last_name,employee_number,department_id,photo',
                'employee.department:id,name',
            ])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'vacation_request',
                'title' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'subtitle' => $item->employee->employee_number ?? '',
                'description' => "{$item->days_requested} día(s) - ".
                    optional($item->start_date)->format('d/m/Y').' al '.
                    optional($item->end_date)->format('d/m/Y'),
                'department' => $item->employee->department->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
                'date' => $item->created_at?->toISOString(),
                'reason' => $item->reason,
                'days' => $item->days_requested,
                'start_date' => $item->start_date?->format('Y-m-d'),
                'end_date' => $item->end_date?->format('Y-m-d'),
            ])
            ->toArray();
    }

    // ===== Incidencias =====

    private function getIncidentQuery(Employee $employee, string $scope)
    {
        $query = EmployeeIncident::where('status', 'pending')
            ->where('employee_id', '!=', $employee->id)
            ->whereHas('employee', function ($q) use ($employee) {
                $q->where('enterprise_id', $employee->enterprise_id);
            });

        $this->applyScopeFilter($query, $employee, $scope);

        return $query;
    }

    private function countPendingIncidents(Employee $employee, string $scope): int
    {
        return $this->getIncidentQuery($employee, $scope)->count();
    }

    private function getPendingIncidents(Employee $employee, string $scope): array
    {
        return $this->getIncidentQuery($employee, $scope)
            ->with([
                'employee:id,first_name,last_name,employee_number,department_id,photo',
                'employee.department:id,name',
                'incidentType:id,name,category',
            ])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'incident',
                'title' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'subtitle' => $item->incidentType->name ?? 'Incidencia',
                'description' => ($item->days ?? 1).' día(s) - '.
                    optional($item->start_date)->format('d/m/Y').
                    ($item->end_date ? ' al '.$item->end_date->format('d/m/Y') : ''),
                'department' => $item->employee->department->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
                'date' => $item->created_at?->toISOString(),
                'category' => $item->incidentType->category ?? null,
            ])
            ->toArray();
    }

    // ===== Órdenes de Compra =====

    private function getPurchaseOrderQuery(Employee $employee, string $scope)
    {
        $query = PurchaseOrder::where('status', 'pending')
            ->where('created_by', '!=', $employee->user_id); // No sus propias OC

        $this->applyScopeFilterByCreator($query, $employee, $scope, 'createdByUser');

        return $query;
    }

    private function countPendingPurchaseOrders(Employee $employee, string $scope): int
    {
        return $this->getPurchaseOrderQuery($employee, $scope)->count();
    }

    private function getPendingPurchaseOrders(Employee $employee, string $scope): array
    {
        return $this->getPurchaseOrderQuery($employee, $scope)
            ->with(['supplier:id,name', 'createdByUser:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'purchase_order',
                'title' => $item->order_number ?? "OC-{$item->id}",
                'subtitle' => $item->supplier->name ?? 'Sin proveedor',
                'description' => '$'.number_format($item->total_amount ?? 0, 2).
                    ' - '.($item->createdByUser->name ?? 'Usuario'),
                'department' => null,
                'photo' => null,
                'date' => $item->created_at?->toISOString(),
                'total_amount' => $item->total_amount,
            ])
            ->toArray();
    }

    // ===== Movimientos de Inventario =====

    private function getInventoryMovementQuery(Employee $employee, string $scope)
    {
        return InventoryMovement::where('status', 'pending')
            ->where(function ($query) use ($employee, $scope) {
                $query->where(function ($localQuery) use ($employee, $scope) {
                    $localQuery->where('created_by', '!=', $employee->user_id)
                        ->whereHas('creator', function ($creatorQuery) use ($employee) {
                            $creatorQuery->whereHas('employee', function ($employeeQuery) use ($employee) {
                                $employeeQuery->where('enterprise_id', $employee->enterprise_id);
                            });
                        })
                        ->where(function ($metadataQuery) {
                            $metadataQuery->whereNull('metadata->requires_external_validation')
                                ->orWhere('metadata->requires_external_validation', false);
                        });

                    $this->applyScopeFilterByCreator($localQuery, $employee, $scope, 'creator');
                })->orWhere(function ($externalQuery) use ($employee) {
                    $externalQuery->where('created_by', '!=', $employee->user_id)
                        ->where('metadata->requires_external_validation', true)
                        ->where('metadata->approval_enterprise_id', $employee->enterprise_id);
                });
            });
    }

    private function countPendingInventoryMovements(Employee $employee, string $scope): int
    {
        return $this->getInventoryMovementQuery($employee, $scope)->count();
    }

    private function getPendingInventoryMovements(Employee $employee, string $scope): array
    {
        return $this->getInventoryMovementQuery($employee, $scope)
            ->with(['creator:id,name', 'movementType:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'inventory_movement',
                'title' => $item->reference_number ?? "MOV-{$item->id}",
                'subtitle' => $item->movementType->name ?? 'Movimiento',
                'description' => ($item->notes ?? 'Sin observaciones').
                    ' - '.($item->creator->name ?? 'Usuario'),
                'creator_name' => $item->creator->name ?? null,
                'department' => null,
                'photo' => null,
                'date' => $item->created_at?->toISOString(),
            ])
            ->toArray();
    }

    /**
     * Aplicar filtro de alcance según departamento del empleado
     * Solo aplica a modelos que tienen employee_id (vacaciones, incidencias)
     */
    private function applyScopeFilter($query, Employee $employee, string $scope): void
    {
        switch ($scope) {
            case 'own_department':
                // Solo solicitudes de empleados de su mismo departamento
                $query->whereHas('employee', function ($q) use ($employee) {
                    $q->where('department_id', $employee->department_id);
                });
                break;

            case 'child_departments':
                // Su departamento + departamentos hijos
                $departmentIds = $this->getDepartmentAndChildIds($employee->department_id);
                $query->whereHas('employee', function ($q) use ($departmentIds) {
                    $q->whereIn('department_id', $departmentIds);
                });
                break;

            case 'enterprise':
                // Todos los empleados de la empresa
                $query->whereHas('employee', function ($q) use ($employee) {
                    $q->where('enterprise_id', $employee->enterprise_id);
                });
                break;
        }
    }

    /**
     * Aplicar filtro de alcance para modelos con created_by (OC, movimientos inventario)
     * Filtra por el departamento del empleado vinculado al usuario creador
     */
    private function applyScopeFilterByCreator($query, Employee $employee, string $scope, string $creatorRelation = 'createdByUser'): void
    {
        switch ($scope) {
            case 'own_department':
                // Solo registros creados por usuarios cuyo empleado está en el mismo departamento
                $query->whereHas($creatorRelation, function ($q) use ($employee) {
                    $q->whereHas('employee', function ($eq) use ($employee) {
                        $eq->where('department_id', $employee->department_id);
                    });
                });
                break;

            case 'child_departments':
                // Departamento propio + hijos
                $departmentIds = $this->getDepartmentAndChildIds($employee->department_id);
                $query->whereHas($creatorRelation, function ($q) use ($departmentIds) {
                    $q->whereHas('employee', function ($eq) use ($departmentIds) {
                        $eq->whereIn('department_id', $departmentIds);
                    });
                });
                break;

            case 'enterprise':
                // Todos - no se filtra (ya está filtrado por empresa implícitamente)
                break;
        }
    }

    /**
     * Obtener IDs de departamento y sus hijos recursivamente
     */
    private function getDepartmentAndChildIds(int $departmentId): array
    {
        $ids = [$departmentId];

        $children = \App\Models\Department::where('parent_id', $departmentId)->pluck('id')->toArray();

        foreach ($children as $childId) {
            $ids = array_merge($ids, $this->getDepartmentAndChildIds($childId));
        }

        return $ids;
    }

    /**
     * Aprobar un item desde el panel de pendientes
     */
    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (in_array($type, ['inventory_movement', 'inventory_movements'], true)) {
            if (! $this->canApproveInventoryMovement($request, $user, $employee)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permiso para validar transferencias',
                ], 403);
            }

            return $this->approveInventoryMovement($id, $user);
        }

        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'No tiene empleado vinculado'], 403);
        }

        return match ($type) {
            'vacation_request' => $this->approveVacation($id, $user),
            'incident' => $this->approveIncident($id, $user),
            'purchase_order' => $this->approvePurchaseOrder($id, $user),
            'inventory_movement', 'inventory_movements' => $this->approveInventoryMovement($id, $user),
            default => response()->json(['success' => false, 'message' => 'Tipo no válido'], 400),
        };
    }

    /**
     * Rechazar un item desde el panel de pendientes
     */
    public function reject(Request $request, string $type, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'No tiene empleado vinculado'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        return match ($type) {
            'vacation_request' => $this->rejectVacation($id, $user, $validated['rejection_reason']),
            'incident' => $this->rejectIncident($id, $user, $validated['rejection_reason']),
            'purchase_order' => $this->rejectPurchaseOrder($id, $user, $validated['rejection_reason']),
            'inventory_movement', 'inventory_movements' => $this->rejectInventoryMovement($id, $user, $validated['rejection_reason']),
            default => response()->json(['success' => false, 'message' => 'Tipo no válido'], 400),
        };
    }

    /**
     * Obtener detalle completo de un item pendiente
     */
    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $item = match ($type) {
            'vacation_request' => VacationRequest::with([
                'employee:id,first_name,last_name,employee_number,department_id,position_id,photo,hire_date',
                'employee.department:id,name',
                'employee.position:id,name',
            ])->find($id),
            'incident' => EmployeeIncident::with([
                'employee:id,first_name,last_name,employee_number,department_id,position_id,photo',
                'employee.department:id,name',
                'employee.position:id,name',
                'incidentType:id,name,category,description',
            ])->find($id),
            'purchase_order' => PurchaseOrder::with([
                'supplier:id,name,rfc,phone,email',
                'createdByUser:id,name',
                'details.product:id,name,sku',
                'details.unit:id,name,abbreviation',
            ])->find($id),
            'inventory_movement', 'inventory_movements' => InventoryMovement::with([
                'creator:id,name',
                'movementType:id,name,code,direction',
                'details.product:id,name,sku',
                'details.unit:id,name,abbreviation',
            ])->find($id),
            default => null,
        };

        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado'], 404);
        }

        $detail = match ($type) {
            'vacation_request' => $this->formatVacationDetail($item),
            'incident' => $this->formatIncidentDetail($item),
            'purchase_order' => $this->formatPurchaseOrderDetail($item),
            'inventory_movement', 'inventory_movements' => $this->formatInventoryMovementDetail($item),
            default => [],
        };

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Historial de aprobaciones/rechazos del usuario
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->query('limit', 30);

        // Buscar en todas las tablas donde approved_by = user_id
        $vacations = VacationRequest::where('approved_by', $user->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->with([
                'employee:id,first_name,last_name,employee_number,department_id,photo',
                'employee.department:id,name',
            ])
            ->orderBy('approved_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'vacation_request',
                'type_label' => 'Vacaciones',
                'title' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'subtitle' => $item->employee->employee_number ?? '',
                'description' => "{$item->days_requested} día(s) - ".
                    optional($item->start_date)->format('d/m/Y').' al '.
                    optional($item->end_date)->format('d/m/Y'),
                'department' => $item->employee->department->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
                'status' => $item->status,
                'status_label' => $item->status === 'approved' ? 'Aprobada' : 'Rechazada',
                'rejection_reason' => $item->rejection_reason,
                'decided_at' => $item->approved_at?->toISOString(),
                'created_at' => $item->created_at?->toISOString(),
            ]);

        $incidents = EmployeeIncident::where('approved_by', $user->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->with([
                'employee:id,first_name,last_name,employee_number,department_id,photo',
                'employee.department:id,name',
                'incidentType:id,name,category',
            ])
            ->orderBy('approved_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'incident',
                'type_label' => 'Incidencia',
                'title' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'subtitle' => $item->incidentType->name ?? 'Incidencia',
                'description' => ($item->days ?? 1).' día(s) - '.
                    optional($item->start_date)->format('d/m/Y').
                    ($item->end_date ? ' al '.$item->end_date->format('d/m/Y') : ''),
                'department' => $item->employee->department->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
                'status' => $item->status,
                'status_label' => $item->status === 'approved' ? 'Aprobada' : 'Rechazada',
                'rejection_reason' => $item->rejection_reason,
                'decided_at' => $item->approved_at?->toISOString(),
                'created_at' => $item->created_at?->toISOString(),
            ]);

        $purchaseOrders = PurchaseOrder::where('approved_by', $user->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->with(['supplier:id,name', 'createdByUser:id,name'])
            ->orderBy('approved_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'purchase_order',
                'type_label' => 'Orden de Compra',
                'title' => $item->order_number ?? "OC-{$item->id}",
                'subtitle' => $item->supplier->name ?? 'Sin proveedor',
                'description' => '$'.number_format($item->total_amount ?? 0, 2).
                    ' - '.($item->createdByUser->name ?? 'Usuario'),
                'department' => null,
                'photo' => null,
                'status' => $item->status,
                'status_label' => $item->status === 'approved' ? 'Aprobada' : 'Rechazada',
                'rejection_reason' => $item->cancellation_reason ?? null,
                'decided_at' => $item->approved_at?->toISOString(),
                'created_at' => $item->created_at?->toISOString(),
            ]);

        // Combinar y ordenar por fecha de decisión
        $allItems = $vacations
            ->concat($incidents)
            ->concat($purchaseOrders)
            ->sortByDesc('decided_at')
            ->take($limit)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $allItems,
                'total' => $allItems->count(),
            ],
        ]);
    }

    // ===== Métodos de aprobación específicos =====

    private function approveVacation(int $id, $user): JsonResponse
    {
        $vacation = VacationRequest::with(['employee.enterprise', 'employee.position', 'employee.user'])->find($id);

        if (! $vacation) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        if ($vacation->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden aprobar solicitudes pendientes'], 422);
        }

        $vacation->approve($user);
        $vacation->load(['approver']);
        $vacation->append(['status_label', 'status_color']);

        // Notificar al empleado
        if ($vacation->employee->user) {
            NotificationService::toUser($vacation->employee->user)
                ->withAction('/profile', 'Ver mis vacaciones')
                ->vacation(
                    '¡Vacaciones aprobadas!',
                    "Tu solicitud de vacaciones del {$vacation->start_date->format('d/m/Y')} al {$vacation->end_date->format('d/m/Y')} ({$vacation->days_requested} días) ha sido aprobada."
                );
        }

        // Broadcast
        broadcast(new VacationRequestUpdated(
            'approved',
            $vacation->toArray(),
            $vacation->employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud aprobada exitosamente',
            'data' => $vacation,
        ]);
    }

    private function rejectVacation(int $id, $user, string $reason): JsonResponse
    {
        $vacation = VacationRequest::with(['employee.enterprise', 'employee.position', 'employee.user'])->find($id);

        if (! $vacation) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        if ($vacation->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden rechazar solicitudes pendientes'], 422);
        }

        // Revertir días pendientes
        $balance = VacationBalance::where('employee_id', $vacation->employee_id)
            ->where('year', $vacation->vacation_year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', $vacation->days_requested);
        }

        $vacation->reject($user, $reason);
        $vacation->load(['approver']);
        $vacation->append(['status_label', 'status_color']);

        // Notificar al empleado
        if ($vacation->employee->user) {
            NotificationService::toUser($vacation->employee->user)
                ->high()
                ->withAction('/profile', 'Ver detalles')
                ->vacation(
                    'Solicitud de vacaciones rechazada',
                    "Tu solicitud del {$vacation->start_date->format('d/m/Y')} al {$vacation->end_date->format('d/m/Y')} fue rechazada. Motivo: {$reason}"
                );
        }

        // Broadcast
        broadcast(new VacationRequestUpdated(
            'rejected',
            $vacation->toArray(),
            $vacation->employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud rechazada',
            'data' => $vacation,
        ]);
    }

    private function approveIncident(int $id, $user): JsonResponse
    {
        $incident = EmployeeIncident::with(['employee.enterprise', 'employee.position', 'incidentType', 'employee.user'])->find($id);

        if (! $incident) {
            return response()->json(['success' => false, 'message' => 'Incidencia no encontrada'], 404);
        }

        if ($incident->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden aprobar incidencias pendientes'], 422);
        }

        $incident->approve($user);
        $incident->load(['approver']);
        $incident->append(['status_label', 'status_color']);

        // Notificar al empleado
        if ($incident->employee->user) {
            NotificationService::toUser($incident->employee->user)
                ->withAction('/profile', 'Ver detalles')
                ->rh(
                    'Incidencia aprobada',
                    "Tu solicitud de {$incident->incidentType->name} ha sido aprobada."
                );
        }

        return response()->json([
            'success' => true,
            'message' => 'Incidencia aprobada exitosamente',
            'data' => $incident,
        ]);
    }

    private function rejectIncident(int $id, $user, string $reason): JsonResponse
    {
        $incident = EmployeeIncident::with(['employee.enterprise', 'employee.position', 'incidentType', 'employee.user'])->find($id);

        if (! $incident) {
            return response()->json(['success' => false, 'message' => 'Incidencia no encontrada'], 404);
        }

        if ($incident->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden rechazar incidencias pendientes'], 422);
        }

        $incident->reject($user, $reason);
        $incident->load(['approver']);
        $incident->append(['status_label', 'status_color']);

        // Notificar al empleado
        if ($incident->employee->user) {
            NotificationService::toUser($incident->employee->user)
                ->high()
                ->withAction('/profile', 'Ver detalles')
                ->alert(
                    'Incidencia rechazada',
                    "Tu solicitud de {$incident->incidentType->name} fue rechazada. Motivo: {$reason}"
                );
        }

        return response()->json([
            'success' => true,
            'message' => 'Incidencia rechazada',
            'data' => $incident,
        ]);
    }

    private function approvePurchaseOrder(int $id, $user): JsonResponse
    {
        $order = PurchaseOrder::with(['supplier', 'createdByUser'])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden aprobar órdenes pendientes'], 422);
        }

        $order->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Orden de compra aprobada exitosamente',
            'data' => $order->fresh(),
        ]);
    }

    private function rejectPurchaseOrder(int $id, $user, string $reason): JsonResponse
    {
        $order = PurchaseOrder::with(['supplier', 'createdByUser'])->find($id);

        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden rechazar órdenes pendientes'], 422);
        }

        $order->update([
            'status' => 'rejected',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Orden de compra rechazada',
            'data' => $order->fresh(),
        ]);
    }

    private function approveInventoryMovement(int $id, $user): JsonResponse
    {
        $movement = InventoryMovement::with(['creator', 'movementType'])->find($id);

        if (! $movement) {
            return response()->json(['success' => false, 'message' => 'Movimiento no encontrado'], 404);
        }

        if ($movement->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden aprobar movimientos pendientes'], 422);
        }

        return app(InventoryMovementController::class)->approve(request(), $movement);
    }

    private function rejectInventoryMovement(int $id, $user, string $reason): JsonResponse
    {
        $movement = InventoryMovement::with(['creator', 'movementType'])->find($id);

        if (! $movement) {
            return response()->json(['success' => false, 'message' => 'Movimiento no encontrado'], 404);
        }

        if ($movement->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden rechazar movimientos pendientes'], 422);
        }

        $movement->update([
            'status' => 'cancelled',
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Movimiento rechazado',
            'data' => $movement->fresh(),
        ]);
    }

    // ===== Formateo de detalle =====

    private function formatVacationDetail($item): array
    {
        return [
            'id' => $item->id,
            'type' => 'vacation_request',
            'type_label' => 'Solicitud de Vacaciones',
            'status' => $item->status,
            'employee' => [
                'name' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'number' => $item->employee->employee_number ?? '',
                'department' => $item->employee->department->name ?? null,
                'position' => $item->employee->position->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
                'hire_date' => $item->employee->hire_date?->format('d/m/Y'),
            ],
            'details' => [
                ['label' => 'Fecha inicio', 'value' => $item->start_date?->format('d/m/Y')],
                ['label' => 'Fecha fin', 'value' => $item->end_date?->format('d/m/Y')],
                ['label' => 'Días solicitados', 'value' => $item->days_requested],
                ['label' => 'Año vacacional', 'value' => $item->vacation_year],
                ['label' => 'Motivo', 'value' => $item->reason ?: 'No especificado'],
                ['label' => 'Fecha de solicitud', 'value' => $item->created_at?->format('d/m/Y H:i')],
            ],
            'rejection_reason' => $item->rejection_reason,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }

    private function formatIncidentDetail($item): array
    {
        return [
            'id' => $item->id,
            'type' => 'incident',
            'type_label' => 'Incidencia',
            'status' => $item->status,
            'employee' => [
                'name' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'number' => $item->employee->employee_number ?? '',
                'department' => $item->employee->department->name ?? null,
                'position' => $item->employee->position->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
            ],
            'details' => [
                ['label' => 'Tipo', 'value' => $item->incidentType->name ?? 'N/D'],
                ['label' => 'Categoría', 'value' => $item->incidentType->category ?? 'N/D'],
                ['label' => 'Fecha inicio', 'value' => $item->start_date?->format('d/m/Y')],
                ['label' => 'Fecha fin', 'value' => $item->end_date?->format('d/m/Y')],
                ['label' => 'Días', 'value' => $item->days ?? 1],
                ['label' => 'Motivo', 'value' => $item->reason ?: 'No especificado'],
                ['label' => 'Fecha de solicitud', 'value' => $item->created_at?->format('d/m/Y H:i')],
            ],
            'has_document' => ! empty($item->document_path),
            'document_url' => $item->document_url,
            'rejection_reason' => $item->rejection_reason,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }

    private function formatPurchaseOrderDetail($item): array
    {
        $lines = ($item->details ?? collect())->map(fn ($d) => [
            'product' => $d->product->name ?? 'N/D',
            'sku' => $d->product->sku ?? '',
            'quantity' => $d->quantity,
            'unit' => $d->unit->abbreviation ?? $d->unit->name ?? '',
            'unit_price' => $d->unit_price,
            'subtotal' => $d->subtotal ?? ($d->quantity * $d->unit_price),
        ]);

        return [
            'id' => $item->id,
            'type' => 'purchase_order',
            'type_label' => 'Orden de Compra',
            'status' => $item->status,
            'employee' => [
                'name' => $item->createdByUser->name ?? 'N/D',
                'number' => null,
                'department' => null,
                'position' => null,
                'photo' => null,
            ],
            'details' => [
                ['label' => 'Número de orden', 'value' => $item->order_number ?? "OC-{$item->id}"],
                ['label' => 'Proveedor', 'value' => $item->supplier->name ?? 'Sin proveedor'],
                ['label' => 'RFC', 'value' => $item->supplier->rfc ?? 'N/D'],
                ['label' => 'Contacto', 'value' => $item->supplier->phone ?? $item->supplier->email ?? 'N/D'],
                ['label' => 'Total', 'value' => '$'.number_format($item->total_amount ?? 0, 2)],
                ['label' => 'Notas', 'value' => $item->notes ?: 'Sin notas'],
                ['label' => 'Fecha de creación', 'value' => $item->created_at?->format('d/m/Y H:i')],
            ],
            'lines' => $lines,
            'total_amount' => $item->total_amount,
            'rejection_reason' => $item->cancellation_reason ?? null,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }

    private function formatInventoryMovementDetail($item): array
    {
        $lines = ($item->details ?? collect())->map(fn ($d) => [
            'id' => $d->id,
            'product' => $d->product->name ?? 'N/D',
            'sku' => $d->product->sku ?? '',
            'quantity' => (float) ($d->quantity ?? 0) > 0
                ? (float) $d->quantity
                : (float) ($d->base_quantity ?? 0),
            'quantity_requested' => (float) ($d->quantity ?? 0) > 0
                ? (float) $d->quantity
                : (float) ($d->base_quantity ?? 0),
            'unit' => $d->unit->abbreviation ?? $d->unit->name ?? '',
        ]);

        $requiresExternalValidation = (bool) data_get($item->metadata, 'requires_external_validation', false);
        $isTransfer = ($item->movementType->direction ?? null) === 'transfer';

        return [
            'id' => $item->id,
            'type' => 'inventory_movement',
            'type_label' => 'Movimiento de Inventario',
            'status' => $item->status,
            'employee' => [
                'name' => $item->creator->name ?? 'N/D',
                'number' => null,
                'department' => null,
                'position' => null,
                'photo' => null,
            ],
            'details' => [
                ['label' => 'Referencia', 'value' => $item->reference_number ?? "MOV-{$item->id}"],
                ['label' => 'Tipo', 'value' => $item->movementType->name ?? 'N/D'],
                ['label' => 'Validación externa', 'value' => $requiresExternalValidation ? 'Sí' : 'No'],
                ['label' => 'Observaciones', 'value' => $item->notes ?: 'Sin observaciones'],
                ['label' => 'Fecha de creación', 'value' => $item->created_at?->format('d/m/Y H:i')],
            ],
            'lines' => $lines,
            'can_validate_reception' => $requiresExternalValidation && $isTransfer && $item->status === 'pending',
            'rejection_reason' => $item->cancellation_reason ?? null,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }

    /**
     * Obtener ruta del frontend para cada proceso
     */
    private function getProcessRoute(string $code): string
    {
        return '/profile?tab=approvals';
    }

    private function canApproveProcess(Request $request, $user, Employee $employee, ?int $enterpriseId, ApprovalProcess $process): bool
    {
        if ($process->code === ApprovalProcess::INVENTORY_MOVEMENTS) {
            return $this->canApproveInventoryMovement($request, $user, $employee);
        }

        return $process->canBeApprovedBy($employee, $enterpriseId);
    }

    private function canApproveInventoryMovement(Request $request, $user, ?Employee $employee): bool
    {
        if ($this->hasTransferApprovalPermission($request, $user)) {
            return true;
        }

        if (! $employee) {
            return false;
        }

        $process = $this->getInventoryApprovalProcess();
        if (! $process) {
            return false;
        }

        return $process->canBeApprovedBy($employee, $employee->enterprise_id);
    }

    private function getInventoryApprovalProcess(): ?ApprovalProcess
    {
        return ApprovalProcess::active()
            ->requiresApproval()
            ->where('code', ApprovalProcess::INVENTORY_MOVEMENTS)
            ->first();
    }

    private function countPendingTransferReceptionsByPermission(Request $request, $user): int
    {
        return $this->getPendingTransferReceptionQueryByPermission($request, $user)->count();
    }

    private function getPendingTransferReceptionsByPermission(Request $request, $user): array
    {
        return $this->getPendingTransferReceptionQueryByPermission($request, $user)
            ->with(['creator:id,name', 'movementType:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'inventory_movement',
                'title' => $item->reference_number ?? "MOV-{$item->id}",
                'subtitle' => $item->movementType->name ?? 'Movimiento',
                'description' => ($item->notes ?? 'Sin observaciones').
                    ' - '.($item->creator->name ?? 'Usuario'),
                'creator_name' => $item->creator->name ?? null,
                'department' => null,
                'photo' => null,
                'date' => $item->created_at?->toISOString(),
            ])
            ->toArray();
    }

    private function getPendingTransferReceptionQueryByPermission(Request $request, $user)
    {
        $enterpriseId = $this->resolveEnterpriseIdForInventoryApproval($request, $user);

        return InventoryMovement::where('status', 'pending')
            ->where('created_by', '!=', $user->id)
            ->where('metadata->requires_external_validation', true)
            ->when($enterpriseId, fn ($q) => $q->where('metadata->approval_enterprise_id', $enterpriseId));
    }

    private function hasTransferApprovalPermission(Request $request, $user): bool
    {
        $enterpriseId = $this->resolveEnterpriseIdForInventoryApproval($request, $user);

        if (! $enterpriseId || ! Schema::hasTable('user_submodule_access')) {
            return false;
        }

        $transferSubmoduleIds = DB::table('submodules')
            ->join('modules', 'submodules.module_id', '=', 'modules.id')
            ->join('applications', 'modules.application_id', '=', 'applications.id')
            ->where('applications.enterprise_id', $enterpriseId)
            ->where('applications.slug', 'inventario')
            ->where('modules.slug', 'operaciones')
            ->where('submodules.slug', 'transferencias')
            ->pluck('submodules.id');

        if ($transferSubmoduleIds->isEmpty()) {
            return false;
        }

        $hasSubmoduleAccess = DB::table('user_submodule_access')
            ->where('user_id', $user->id)
            ->whereIn('submodule_id', $transferSubmoduleIds)
            ->where('is_active', true)
            ->exists();

        if (! $hasSubmoduleAccess) {
            return false;
        }

        if (! Schema::hasTable('user_submodule_permissions') || ! Schema::hasTable('submodule_permission_types')) {
            return true;
        }

        $permissionRows = DB::table('user_submodule_permissions as usp')
            ->leftJoin('submodule_permission_types as spt', 'usp.permission_type_id', '=', 'spt.id')
            ->where('usp.user_id', $user->id)
            ->whereIn('usp.submodule_id', $transferSubmoduleIds)
            ->orderByDesc('usp.id')
            ->get([
                'usp.id',
                'usp.submodule_id',
                'usp.permission_type_id',
                'usp.is_granted',
                'usp.granted',
                'spt.slug',
                'spt.key',
            ]);

        if ($permissionRows->isEmpty()) {
            return true;
        }

        $acceptedSlugs = [
            'validar',
            'validate',
            'aceptar',
            'accept',
            'aprobar',
            'approve',
            'validar_transferencia',
            'validar_transferencias',
            'aceptar_transferencia',
            'aceptar_transferencias',
            'aprobar_transferencia',
            'aprobar_transferencias',
            'edit',
        ];

        $seen = [];
        foreach ($permissionRows as $row) {
            $key = (int) $row->submodule_id.'-'.(int) ($row->permission_type_id ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isGranted = true;
            if (Schema::hasColumn('user_submodule_permissions', 'is_granted')) {
                $isGranted = (bool) $row->is_granted;
            } elseif (Schema::hasColumn('user_submodule_permissions', 'granted')) {
                $isGranted = (bool) $row->granted;
            }

            $slug = strtolower((string) ($row->slug ?? $row->key ?? ''));
            if ($isGranted && $slug !== '' && in_array($slug, $acceptedSlugs, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveEnterpriseIdForInventoryApproval(Request $request, $user): ?int
    {
        if ($user->employee?->enterprise_id) {
            return (int) $user->employee->enterprise_id;
        }

        $slug = strtolower((string) ($request->header('X-Enterprise-Slug') ?? ''));
        if ($slug === '') {
            return null;
        }

        return Enterprise::where('slug', $slug)->value('id');
    }
}
