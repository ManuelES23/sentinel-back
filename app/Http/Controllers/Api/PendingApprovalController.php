<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\SplendidFarms\Inventory\InventoryMovementController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\Employee;
use App\Models\EmployeeIncident;
use App\Models\Enterprise;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\SfFieldCheck;
use App\Models\TimeClockCheck;
use App\Models\UserEnterpriseAccess;
use App\Models\VacationRequest;
use App\Services\Abastecimiento\AprobadorLiquidaciones;
use App\Services\Abastecimiento\AvisosAbastecimiento;
use App\Services\Abastecimiento\CadenaLiquidacion;
use App\Services\Abastecimiento\DespuesDeDecidirLiquidacion;
use App\Services\Abastecimiento\FlujoLiquidacion;
use App\Services\Abastecimiento\PresentadorLiquidacion;
use App\Services\ActivosFijos\AlcanceActivos;
use App\Services\Compras\AprobadorOrdenCompra;
use App\Services\NotificationService;
use App\Services\RH\AprobadorVacaciones;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\VacationRequestService;
use App\Support\RH\PresentadorPortalVacaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PendingApprovalController extends Controller
{
    private ?AprobadorOrdenCompra $aprobadorOc = null;

    private ?AprobadorVacaciones $aprobadorVac = null;

    private ?CadenaAprobacionVacaciones $cadenaVac = null;

    /** @var array<int, Collection> solicitudes de vacaciones decidibles, por id de usuario (el controlador vive una petición) */
    private array $vacacionesPorUsuario = [];

    /** @var array<int, Collection> liquidaciones de abastecimiento decidibles, por id de usuario (el controlador vive una petición) */
    private array $liquidacionesPorUsuario = [];

    /** @var array<int, array<int, array{aprobar: bool, rechazar: bool}>> acciones posibles sobre cada liquidación pendiente, por usuario y liquidación */
    private array $accionesLiquidaciones = [];

    /** @var array<int, ?Enterprise> empresas de las liquidaciones ya consultadas en esta petición */
    private array $empresasLiquidacion = [];

    /**
     * Obtener resumen de pendientes por aprobar para el usuario autenticado
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $hasTransferPermission = $this->hasTransferApprovalPermission($request, $user);
        $fieldCheckEntry = $this->getFieldCheckReviewProcessEntry($request, $user);
        $timeClockReviewEntry = $this->getTimeClockReviewProcessEntry($request, $user);

        // Sin empleado solo se habilita validación de transferencias o de
        // revisión de asistencia biométrica, ambas por permiso explícito de submódulo.
        if (! $employee && ! $hasTransferPermission) {
            $processes = $fieldCheckEntry ? [$fieldCheckEntry] : [];
            $totalPending = $fieldCheckEntry['pending_count'] ?? 0;

            if ($timeClockReviewEntry) {
                $processes[] = $timeClockReviewEntry;
                $totalPending += $timeClockReviewEntry['pending_count'];
            }

            if ($vacacionesEntry = $this->entradaVacacionesSinEmpleado($user, false)) {
                $processes[] = $vacacionesEntry;
                $totalPending += $vacacionesEntry['pending_count'];
            }

            if ($liquidacionesEntry = $this->entradaLiquidaciones($user, false)) {
                $processes[] = $liquidacionesEntry;
                $totalPending += $liquidacionesEntry['pending_count'];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => $totalPending,
                    'processes' => $processes,
                    'can_approve' => $totalPending > 0,
                ],
            ]);
        }

        if (! $employee && $hasTransferPermission) {
            $process = $this->getInventoryApprovalProcess();
            $count = $this->countPendingTransferReceptionsByPermission($request, $user);
            $processes = [[
                'process_id' => $process?->id,
                'code' => ApprovalProcess::INVENTORY_MOVEMENTS,
                'name' => $process?->name ?? 'Movimientos de inventario',
                'module' => $process?->module ?? 'inventario',
                'description' => $process?->description ?? 'Validación de transferencias entre entidades',
                'pending_count' => $count,
                'scope' => 'enterprise',
                'route' => $this->getProcessRoute(ApprovalProcess::INVENTORY_MOVEMENTS),
            ]];
            $totalPending = $count;

            if ($fieldCheckEntry) {
                $processes[] = $fieldCheckEntry;
                $totalPending += $fieldCheckEntry['pending_count'];
            }

            if ($timeClockReviewEntry) {
                $processes[] = $timeClockReviewEntry;
                $totalPending += $timeClockReviewEntry['pending_count'];
            }

            if ($vacacionesEntry = $this->entradaVacacionesSinEmpleado($user, false)) {
                $processes[] = $vacacionesEntry;
                $totalPending += $vacacionesEntry['pending_count'];
            }

            if ($liquidacionesEntry = $this->entradaLiquidaciones($user, false)) {
                $processes[] = $liquidacionesEntry;
                $totalPending += $liquidacionesEntry['pending_count'];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => $totalPending,
                    'processes' => $processes,
                    'can_approve' => $totalPending > 0,
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
            // Liquidaciones de abastecimiento: su entrada (con o sin empleado) se arma aparte, solo si hay algo por decidir.
            if ($process->code === ApprovalProcess::SUPPLY_SETTLEMENTS) {
                continue;
            }

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
            $scope = $this->alcanceParaProceso($process, $employee, $hasTransferPermission);

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

        if ($fieldCheckEntry) {
            $result[] = $fieldCheckEntry;
            $totalPending += $fieldCheckEntry['pending_count'];
        }

        if ($timeClockReviewEntry) {
            $result[] = $timeClockReviewEntry;
            $totalPending += $timeClockReviewEntry['pending_count'];
        }

        if ($liquidacionesEntry = $this->entradaLiquidaciones($user, false)) {
            $result[] = $liquidacionesEntry;
            $totalPending += $liquidacionesEntry['pending_count'];
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
            $vacacionesEntry = $this->entradaVacacionesSinEmpleado($user, true);
            $liquidacionesEntry = $this->entradaLiquidaciones($user, true);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => ($vacacionesEntry['pending_count'] ?? 0) + ($liquidacionesEntry['pending_count'] ?? 0),
                    'processes' => array_values(array_filter([$vacacionesEntry, $liquidacionesEntry])),
                ],
            ]);
        }

        if (! $employee && $hasTransferPermission) {
            $process = $this->getInventoryApprovalProcess();
            $items = $this->getPendingTransferReceptionsByPermission($request, $user);
            $vacacionesEntry = $this->entradaVacacionesSinEmpleado($user, true);
            $liquidacionesEntry = $this->entradaLiquidaciones($user, true);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_pending' => count($items) + ($vacacionesEntry['pending_count'] ?? 0) + ($liquidacionesEntry['pending_count'] ?? 0),
                    'processes' => array_values(array_filter([[
                        'process_id' => $process?->id,
                        'code' => ApprovalProcess::INVENTORY_MOVEMENTS,
                        'name' => $process?->name ?? 'Movimientos de inventario',
                        'module' => $process?->module ?? 'inventario',
                        'description' => $process?->description ?? 'Validación de transferencias entre entidades',
                        'pending_count' => count($items),
                        'items' => $items,
                        'scope' => 'enterprise',
                        'route' => $this->getProcessRoute(ApprovalProcess::INVENTORY_MOVEMENTS),
                    ], $vacacionesEntry, $liquidacionesEntry])),
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
            // Liquidaciones de abastecimiento: su entrada (con o sin empleado) se arma aparte, solo si hay algo por decidir.
            if ($process->code === ApprovalProcess::SUPPLY_SETTLEMENTS) {
                continue;
            }

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

            $scope = $this->alcanceParaProceso($process, $employee, $hasTransferPermission);
            $items =$this->getPendingItems($process->code, $employee, $scope);

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

        if (! $filterProcess || $filterProcess === ApprovalProcess::SUPPLY_SETTLEMENTS) {
            if ($liquidacionesEntry = $this->entradaLiquidaciones($user, true)) {
                $result[] = $liquidacionesEntry;
                $totalPending += $liquidacionesEntry['pending_count'];
            }
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
     * Alcance que se informa por proceso. Las transferencias con permiso
     * explícito y las OC que autoriza un corporativo abarcan toda la empresa.
     */
    private function alcanceParaProceso(ApprovalProcess $process, Employee $employee, bool $hasTransferPermission): string
    {
        if ($process->code === ApprovalProcess::INVENTORY_MOVEMENTS && $hasTransferPermission) {
            return 'enterprise';
        }

        if ($process->code === ApprovalProcess::PURCHASE_ORDERS && $this->aprobadorOc()->esCorporativo($employee)) {
            return 'enterprise';
        }

        return $this->getEmployeeScope($process, $employee);
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
            ApprovalProcess::RECIPE_APPROVAL => $this->countPendingRecipes($employee, $scope),
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
            ApprovalProcess::RECIPE_APPROVAL => $this->getPendingRecipes($employee, $scope),
            default => [],
        };
    }

    // ===== Vacaciones =====

    /**
     * ¿Puede ver el detalle de esta solicitud? Quien decide su paso actual, quien ya decidió algún paso (su historial)
     * o RH. El solicitante nunca (tampoco por un usuario compartido): el comentario del visto bueno no es para él.
     */
    private function puedeVerVacacion($user, VacationRequest $detalle): bool
    {
        // El detalle carga al solicitante con pocas columnas (sin reports_to ni user_id) y las reglas de
        // esSolicitante/puedeDecidir necesitan el empleado completo: se revisa sobre una copia completa.
        $solicitud = VacationRequest::with(['employee.department', 'approvals'])->find($detalle->id);

        if ($this->aprobadorVac()->esSolicitante($user, $solicitud)) {
            return false;
        }

        if ($this->aprobadorVac()->esRh($user)) {
            return true;
        }

        $empleadoId = $user->employee()->value('id');
        $decidioUnPaso = $solicitud->approvals->contains(fn ($paso) => (int) $paso->decided_by === (int) $user->id
            || ($empleadoId !== null && (int) $paso->decided_by_employee_id === (int) $empleadoId));
        if ($decidioUnPaso) {
            return true;
        }

        $paso = $solicitud->status === 'pending' ? $this->cadenaVac()->pasoActual($solicitud) : null;

        return $paso !== null && ($this->aprobadorVac()->puedeDecidir($user, $solicitud, $paso)
            || $this->aprobadorVac()->puedeDecidir($user, $solicitud, $paso, 'rechazar'));
    }

    /** Solicitudes cuyo paso ACTUAL puede decidir este usuario (spec §5.6). */
    private function vacacionesPara($user): Collection
    {
        return $this->vacacionesPorUsuario[$user->id] ??= VacationRequest::where('status', 'pending')
            ->whereNotNull('current_step')
            ->where('employee_id', '!=', (int) $user->employee?->id)
            ->with(['employee.department:id,name,manager_id', 'employee.enterprise:id,name', 'approvals'])
            ->lazyById(100)
            ->filter(function (VacationRequest $solicitud) use ($user) {
                $paso = $this->cadenaVac()->pasoActual($solicitud);

                // El visto bueno de RH se da desde Vacaciones de RH, no desde la bandeja (spec §5.6).
                return $paso !== null && ! $paso->is_rh_review && $this->aprobadorVac()->puedeDecidir($user, $solicitud, $paso);
            })
            ->collect()
            ->sortByDesc('created_at')
            ->values();
    }

    private function countPendingVacations(Employee $employee, string $scope): int
    {
        return $employee->user ? $this->vacacionesPara($employee->user)->count() : 0;
    }

    private function getPendingVacations(Employee $employee, string $scope): array
    {
        return $employee->user ? $this->itemsVacaciones($employee->user) : [];
    }

    /**
     * Entrada de vacaciones para quien no tiene empleado ligado: solo si es «usuario específico»
     * de algún paso (los de RH sin empleado resuelven desde la pantalla de Vacaciones de RH).
     */
    private function entradaVacacionesSinEmpleado($user, bool $conItems): ?array
    {
        $process = ApprovalProcess::active()->requiresApproval()->where('code', ApprovalProcess::VACATION_REQUESTS)->first();
        if (! $process || ! $this->esUsuarioEspecificoDeVacaciones($process, $user)) {
            return null;
        }

        $entrada = [
            'process_id' => $process->id,
            'code' => $process->code,
            'name' => $process->name,
            'module' => $process->module,
            'description' => $process->description,
            'pending_count' => $this->vacacionesPara($user)->count(),
            'scope' => 'enterprise',
            'route' => $this->getProcessRoute($process->code),
        ];

        return $conItems ? $entrada + ['items' => $this->itemsVacaciones($user)] : $entrada;
    }

    private function esUsuarioEspecificoDeVacaciones(ApprovalProcess $process, $user): bool
    {
        return $process->activeSteps()
            ->where('approver_type', ApprovalFlowStep::TYPE_USER)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function itemsVacaciones($user): array
    {
        return $this->vacacionesPara($user)
            ->take(50)
            ->map(fn (VacationRequest $item) => [
                'id' => $item->id,
                'type' => 'vacation_request',
                'title' => ($item->employee->first_name ?? '').' '.($item->employee->last_name ?? ''),
                'subtitle' => $item->employee->employee_number ?? '',
                'description' => "{$item->days_requested} día(s) - ".
                    optional($item->start_date)->format('d/m/Y').' al '.
                    optional($item->end_date)->format('d/m/Y'),
                'department' => $item->employee->department->name ?? null,
                'enterprise' => $item->employee->enterprise->name ?? null,
                'photo' => $item->employee->photo_url ?? null,
                'date' => $item->created_at?->toISOString(),
                'reason' => $item->reason,
                'days' => $item->days_requested,
                'start_date' => $item->start_date?->format('Y-m-d'),
                'end_date' => $item->end_date?->format('Y-m-d'),
                'step' => $item->current_step,
                'steps_total' => $item->approvals->count(),
                'step_description' => $item->approvals->firstWhere('step_order', $item->current_step)?->description,
            ])
            ->toArray();
    }

    // ===== Liquidaciones de abastecimiento =====

    /**
     * Liquidaciones en revisión cuyo paso ACTUAL puede decidir este usuario (spec §6.6). Las candidatas son pocas
     * (`en_revision`) y se traen con sus pasos en una sola consulta; lo que decide es `AprobadorLiquidaciones`.
     * Se memoiza por usuario dentro de la instancia del controlador.
     */
    private function liquidacionesPara($user): Collection
    {
        return $this->liquidacionesPorUsuario[$user->id] ??= $this->calcularLiquidacionesPara($user);
    }

    private function calcularLiquidacionesPara($user): Collection
    {
        // Solo cuentan las empresas a las que el usuario tiene acceso (una consulta; el administrador entra a todas):
        // quien no tiene que ver con Abastecimiento no paga ninguna comprobación por liquidación en revisión.
        $empresaIds = null;
        if (! EnsureUserIsAdmin::esAdmin($user)) {
            $empresaIds = UserEnterpriseAccess::where('user_id', $user->id)->where('is_active', true)->pluck('enterprise_id');
            if ($empresaIds->isEmpty()) {
                return collect();
            }
        }

        return Liquidacion::where('status', 'en_revision')
            ->when($empresaIds !== null, fn ($q) => $q->whereIn('enterprise_id', $empresaIds))
            ->whereNotNull('paso_actual')
            ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
            ->where(fn ($q) => $q->whereNull('enviada_by')->orWhere('enviada_by', '!=', $user->id))
            ->with(['convenio:id,folio,variedad_id,moneda', 'convenio.variedad:id,nombre', 'productor:id,nombre', 'aprobaciones'])
            ->orderByDesc('enviada_at')
            ->orderByDesc('id')
            ->get()
            ->filter(function (Liquidacion $liquidacion) use ($user) {
                $paso = $this->pasoEnEsperaDe($liquidacion);
                if ($paso === null) {
                    return false;
                }

                // Una sola evaluación del servicio; pendiente = puede aprobar O rechazar el paso.
                $acciones = AprobadorLiquidaciones::accionesPara($user, $liquidacion, $paso);
                if (! $acciones['aprobar'] && ! $acciones['rechazar']) {
                    return false;
                }
                $this->accionesLiquidaciones[$user->id][$liquidacion->id] = $acciones;

                return true;
            })
            ->values();
    }

    /** El paso en espera de la ronda vigente, tomado de las aprobaciones ya cargadas (sin consulta). */
    private function pasoEnEsperaDe(Liquidacion $liquidacion): ?\App\Models\Abastecimiento\LiquidacionAprobacion
    {
        return $liquidacion->aprobaciones->first(fn ($paso) => (int) $paso->ronda === (int) $liquidacion->ronda
            && (int) $paso->step_order === (int) $liquidacion->paso_actual
            && $paso->decision === 'waiting');
    }

    private function empresaDeLiquidacion(Liquidacion $liquidacion): ?Enterprise
    {
        return $this->empresasLiquidacion[$liquidacion->enterprise_id] ??= Enterprise::find($liquidacion->enterprise_id);
    }

    /**
     * ¿Puede ver el detalle? Con acceso a la empresa de la liquidación y: decide su paso actual o ya decidió algún
     * paso suyo. Quien la creó o envió, y cualquier otro, no la ve (el controlador responde igual que a un id inexistente).
     */
    private function puedeVerLiquidacion($user, Liquidacion $liquidacion): bool
    {
        $empresa = $this->empresaDeLiquidacion($liquidacion);
        if ($empresa === null || ! AlcanceActivos::tieneAcceso($user, $empresa)) {
            return false;
        }

        $liquidacion->loadMissing('aprobaciones');
        if ($liquidacion->aprobaciones->contains(fn ($paso) => $paso->decided_by !== null && (int) $paso->decided_by === (int) $user->id)) {
            return true;
        }

        $paso = $liquidacion->status === 'en_revision' ? CadenaLiquidacion::pasoActual($liquidacion) : null;

        if ($paso === null) {
            return false;
        }
        $acciones = AprobadorLiquidaciones::accionesPara($user, $liquidacion, $paso);

        return $acciones['aprobar'] || $acciones['rechazar'];
    }

    /** Entrada del proceso de liquidaciones para este usuario (con o sin empleado ligado); null si no tiene nada por decidir. */
    private function entradaLiquidaciones($user, bool $conItems): ?array
    {
        $pendientes = $this->liquidacionesPara($user);
        if ($pendientes->isEmpty()) {
            return null;
        }

        $process = ApprovalProcess::findByCode(ApprovalProcess::SUPPLY_SETTLEMENTS);

        $entrada = [
            'process_id' => $process?->id,
            'code' => ApprovalProcess::SUPPLY_SETTLEMENTS,
            'name' => $process?->name ?? 'Liquidaciones de abastecimiento',
            'module' => $process?->module ?? 'abastecimiento',
            'description' => $process?->description ?? 'Aprobación de liquidaciones de abastecimiento antes de pagarse',
            'pending_count' => $pendientes->count(),
            'scope' => 'enterprise',
            'route' => $this->getProcessRoute(ApprovalProcess::SUPPLY_SETTLEMENTS),
        ];

        return $conItems ? $entrada + ['items' => $this->itemsLiquidaciones($user)] : $entrada;
    }

    private function itemsLiquidaciones($user): array
    {
        $liquidaciones = $this->liquidacionesPara($user)->take(50);
        $empresas = Enterprise::whereIn('id', $liquidaciones->pluck('enterprise_id')->unique())->pluck('name', 'id');

        return $liquidaciones
            ->map(function (Liquidacion $l) use ($empresas, $user) {
                $paso = $this->pasoEnEsperaDe($l);
                $inicio = $l->periodo_inicio?->format('d/m/Y');
                $fin = $l->periodo_fin?->format('d/m/Y');

                return [
                    'id' => $l->id,
                    'type' => 'supply_settlement',
                    'title' => $l->folio.' · '.($l->productor->nombre ?? 'Productor'),
                    'subtitle' => implode(' · ', array_filter([$l->convenio->folio ?? null, $l->convenio->variedad->nombre ?? null])),
                    'description' => $this->montoLiquidacion($l->monto_final, $l->moneda)." - {$inicio} al {$fin}",
                    'enterprise' => $empresas[$l->enterprise_id] ?? null,
                    'date' => $l->enviada_at?->toISOString(),
                    'step' => $l->paso_actual,
                    'steps_total' => $l->aprobaciones->where('ronda', $l->ronda)->count(),
                    'step_description' => $paso?->description,
                    'amount' => (float) $l->monto_final,
                    'currency' => $l->moneda,
                    'period_start' => $l->periodo_inicio?->format('Y-m-d'),
                    'period_end' => $l->periodo_fin?->format('Y-m-d'),
                    // Lo que su regla le permite: el front esconde «Aprobar» o «Rechazar» según el caso.
                    'can_approve' => $this->accionesLiquidaciones[$user->id][$l->id]['aprobar'] ?? false,
                    'can_reject' => $this->accionesLiquidaciones[$user->id][$l->id]['rechazar'] ?? false,
                ];
            })
            ->values()
            ->all();
    }

    private function montoLiquidacion($monto, ?string $moneda): string
    {
        return '$'.number_format((float) $monto, 2).' '.$moneda;
    }

    /**
     * Cuerpo común de aprobar y rechazar: la liquidación debe existir y ser visible para el usuario (si no, 404 como
     * en el detalle); `step` opcional es el paso que el cliente tenía a la vista y, si ya cambió, el servicio da 422.
     * Sin `step` se toma el paso vigente al recibir la petición. Los demás fallos de regla los da el servicio (422).
     */
    private function decidirLiquidacion(Request $request, int $id, $user, callable $decidir, callable $mensaje, callable $avisoPara): JsonResponse
    {
        $liquidacion = Liquidacion::find($id);
        if (! $liquidacion || ! $this->puedeVerLiquidacion($user, $liquidacion)) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado'], 404);
        }

        $datos = $request->validate(['step' => ['nullable', 'integer', 'min:1']]);
        $esperado = isset($datos['step'])
            ? (int) $datos['step']
            : (int) (CadenaLiquidacion::pasoActual($liquidacion)?->step_order ?? 0);

        $liquidacion = DB::transaction(fn () => $decidir($liquidacion, $esperado), 3);

        // La empresa ya se consultó al comprobar la visibilidad; si faltara, solo se omiten el evento y el aviso.
        $empresa = $this->empresaDeLiquidacion($liquidacion);
        if ($empresa !== null) {
            DespuesDeDecidirLiquidacion::ejecutar($liquidacion, $empresa, $avisoPara($liquidacion));
        }

        return response()->json([
            'success' => true,
            'message' => $mensaje($liquidacion),
            'data' => app(PresentadorLiquidacion::class)->resumen($liquidacion),
        ]);
    }

    private function approveLiquidacion(Request $request, int $id, $user): JsonResponse
    {
        $comentario = $request->validate(['comment' => ['nullable', 'string', 'max:500']])['comment'] ?? null;

        return $this->decidirLiquidacion(
            $request, $id, $user,
            fn (Liquidacion $l, int $paso) => FlujoLiquidacion::aprobar($l, $user, $comentario, $paso),
            fn (Liquidacion $l) => $l->status === 'aprobada'
                ? 'Liquidación aprobada exitosamente'
                : 'Aprobaste tu paso; la liquidación pasó al siguiente aprobador',
            fn (Liquidacion $l) => $l->status === 'en_revision' ? AvisosAbastecimiento::pasoEnEspera(...) : null,
        );
    }

    private function rejectLiquidacion(Request $request, int $id, $user, string $reason): JsonResponse
    {
        return $this->decidirLiquidacion(
            $request, $id, $user,
            fn (Liquidacion $l, int $paso) => FlujoLiquidacion::rechazar($l, $user, $reason, $paso),
            fn () => 'Liquidación rechazada y regresada a borrador',
            fn () => AvisosAbastecimiento::rechazada(...),
        );
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

    /**
     * OC pendientes que este empleado puede autorizar (misma regla que el
     * endpoint de la OC: AprobadorOrdenCompra).
     */
    private function purchaseOrdersFor(Employee $employee)
    {
        $user = $employee->user;
        if (! $user) {
            return collect();
        }
        $aprobador = $this->aprobadorOc();

        return PurchaseOrder::where('status', 'pending')
            ->where('created_by', '!=', $employee->user_id)
            ->where(fn ($q) => $q->whereIn('enterprise_id', $aprobador->empresasQuePuedeAprobar($employee))->orWhereNull('enterprise_id'))
            ->with(['supplier:id,business_name,trade_name', 'createdByUser:id,name', 'createdByUser.employee'])
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get()
            ->filter(fn ($oc) => $aprobador->puedeAprobar($user, $oc))
            ->values();
    }

    private function countPendingPurchaseOrders(Employee $employee, string $scope): int
    {
        return $this->purchaseOrdersFor($employee)->count();
    }

    private function getPendingPurchaseOrders(Employee $employee, string $scope): array
    {
        return $this->purchaseOrdersFor($employee)
            ->take(50)
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'purchase_order',
                'title' => $item->order_number ?? "OC-{$item->id}",
                'subtitle' => $item->supplier?->trade_name ?: ($item->supplier?->business_name ?? 'Sin proveedor'),
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

    // ===== Recetas (BOM) =====

    /**
     * A diferencia de vacaciones/incidencias/OC/movimientos, las recetas no
     * tienen employee_id ni "creador" — es un catálogo compartido de la
     * empresa. El único alcance que aplica es 'enterprise' (mismo criterio
     * que el caso 'enterprise' de applyScopeFilterByCreator(): no se filtra
     * más porque ya está acotado por enterprise_id); $scope se recibe para
     * respetar la firma común de countPendingItems()/getPendingItems() pero
     * no cambia el resultado.
     */
    private function getRecipeQuery(Employee $employee, string $scope)
    {
        return Recipe::where('status', 'pending_approval')
            ->where('enterprise_id', $employee->enterprise_id);
    }

    private function countPendingRecipes(Employee $employee, string $scope): int
    {
        return $this->getRecipeQuery($employee, $scope)->count();
    }

    private function getPendingRecipes(Employee $employee, string $scope): array
    {
        return $this->getRecipeQuery($employee, $scope)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'recipe',
                'title' => $item->name,
                'subtitle' => $item->code,
                'description' => $item->description ?: 'Sin descripción',
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

        // Vacaciones: un «usuario específico» de la cadena puede no ser empleado; el servicio valida si le toca el paso.
        // Liquidaciones de abastecimiento: igual, quien decide puede ser un usuario sin empleado; el servicio valida el paso.
        if (! $employee && ! in_array($type, ['vacation_request', 'supply_settlement'], true)) {
            return response()->json(['success' => false, 'message' => 'No tiene empleado vinculado'], 403);
        }

        return match ($type) {
            'vacation_request' => $this->approveVacation($id, $user),
            'supply_settlement' => $this->approveLiquidacion($request, $id, $user),
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

        if (! $employee && ! in_array($type, ['vacation_request', 'supply_settlement'], true)) {
            return response()->json(['success' => false, 'message' => 'No tiene empleado vinculado'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        return match ($type) {
            'vacation_request' => $this->rejectVacation($id, $user, $validated['rejection_reason']),
            'supply_settlement' => $this->rejectLiquidacion($request, $id, $user, $validated['rejection_reason']),
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
                'approvals.decider:id,name',
                'approvals.decidedByEmployee:id,first_name,last_name,second_last_name',
            ])->find($id),
            'supply_settlement' => Liquidacion::find($id),
            'incident' => EmployeeIncident::with([
                'employee:id,first_name,last_name,employee_number,department_id,position_id,photo',
                'employee.department:id,name',
                'employee.position:id,name',
                'incidentType:id,name,category,description',
            ])->find($id),
            'purchase_order' => PurchaseOrder::with([
                'supplier:id,business_name,trade_name,rfc,phone,email',
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

        // Vacaciones: el detalle trae la cadena con el comentario del visto bueno de RH, que es para quien revisa.
        // Una solicitud que no le toca responde igual que una inexistente.
        // Liquidaciones: solo quien decide su paso actual o ya decidió uno; para los demás no existe (mismo 404).
        if (! $item
            || ($type === 'vacation_request' && ! $this->puedeVerVacacion($request->user(), $item))
            || ($type === 'supply_settlement' && ! $this->puedeVerLiquidacion($request->user(), $item))) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado'], 404);
        }

        $detail = match ($type) {
            'vacation_request' => $this->formatVacationDetail($item),
            'supply_settlement' => $this->formatLiquidacionDetail($item, $request->user()),
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
            ->with(['supplier:id,business_name,trade_name', 'createdByUser:id,name'])
            ->orderBy('approved_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => 'purchase_order',
                'type_label' => 'Orden de Compra',
                'title' => $item->order_number ?? "OC-{$item->id}",
                'subtitle' => $item->supplier?->trade_name ?: ($item->supplier?->business_name ?? 'Sin proveedor'),
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
        $vacation = VacationRequest::find($id);
        if (! $vacation) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        $vacation = app(VacationRequestService::class)->aprobarPaso($vacation, $user);
        $vacation->append(['status_label', 'status_color']);

        return response()->json([
            'success' => true,
            'message' => $vacation->status === 'approved'
                ? 'Solicitud aprobada exitosamente'
                : 'Aprobaste tu paso; la solicitud pasó al siguiente aprobador',
            'data' => $vacation,
        ]);
    }

    private function rejectVacation(int $id, $user, string $reason): JsonResponse
    {
        $vacation = VacationRequest::find($id);
        if (! $vacation) {
            return response()->json(['success' => false, 'message' => 'Solicitud no encontrada'], 404);
        }

        $vacation = app(VacationRequestService::class)->rechazar($vacation, $user, $reason);
        $vacation->append(['status_label', 'status_color']);

        return response()->json(['success' => true, 'message' => 'Solicitud rechazada', 'data' => $vacation]);
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

        $aprobador = app(\App\Services\Compras\AprobadorOrdenCompra::class);
        if (! $aprobador->puedeAprobar($user, $order)) {
            return response()->json(['success' => false, 'message' => 'No eres aprobador de esta orden de compra'], 403);
        }

        $order->approve($user->id);
        app(\App\Services\Compras\AvisosCompras::class)->ordenResuelta($order, true);

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

        $aprobador = app(\App\Services\Compras\AprobadorOrdenCompra::class);
        if (! $aprobador->puedeAprobar($user, $order)) {
            return response()->json(['success' => false, 'message' => 'No eres aprobador de esta orden de compra'], 403);
        }

        $order->reject($user->id, $reason);
        app(\App\Services\Compras\AvisosCompras::class)->ordenResuelta($order, false);

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
            // Misma regla que el portal: el respaldo de RH solo sale si decidió o si es el que espera.
            'approvals' => PresentadorPortalVacaciones::pasosVisibles($item)->map(fn ($paso) => [
                'step_order' => $paso->step_order,
                'description' => $paso->description,
                'decision' => $paso->decision,
                'decided_by' => $paso->nombreDeQuienDecidio(),
                'comment' => $paso->comment,
                'is_rh_review' => (bool) $paso->is_rh_review,
                'decided_at' => $paso->decided_at?->toISOString(),
            ])->values()->all(),
            'rejection_reason' => $item->rejection_reason,
            'created_at' => $item->created_at?->toISOString(),
        ];
    }

    /** Detalle de una liquidación para quien la revisa: renglones, totales, convenio, productor y la cadena de la ronda vigente. */
    private function formatLiquidacionDetail(Liquidacion $item, $user): array
    {
        $empresa = Enterprise::findOrFail($item->enterprise_id);
        $ficha = app(PresentadorLiquidacion::class)->completa($item, $empresa, $user);
        $moneda = $item->moneda;
        $dinero = fn ($monto) => $this->montoLiquidacion($monto, $moneda);

        $details = [
            ['label' => 'Productor', 'value' => $ficha['productor']['nombre'] ?? null],
            ['label' => 'Convenio', 'value' => $ficha['convenio']['folio'].($ficha['convenio']['variedad'] ? ' · '.$ficha['convenio']['variedad']['nombre'] : '')],
            ['label' => 'Periodo', 'value' => $item->periodo_inicio?->format('d/m/Y').' al '.$item->periodo_fin?->format('d/m/Y')],
            ['label' => 'Recepciones', 'value' => count($ficha['detalles'])],
            ['label' => 'Monto bruto', 'value' => $dinero($item->bruto)],
            ['label' => 'Descuento por rezaga', 'value' => $dinero($item->descuento)],
            ['label' => 'Monto calculado', 'value' => $dinero($item->monto_calculado)],
        ];
        if ($item->monto_ajustado !== null) {
            $details[] = ['label' => 'Monto ajustado', 'value' => $dinero($item->monto_ajustado)];
            $details[] = ['label' => 'Motivo del ajuste', 'value' => $item->motivo_ajuste ?: 'No especificado'];
        }
        $details[] = ['label' => 'Monto final', 'value' => $dinero($item->monto_final)];
        $details[] = ['label' => 'Enviada a revisión', 'value' => $item->enviada_at?->format('d/m/Y H:i')];

        return [
            'id' => $item->id,
            'type' => 'supply_settlement',
            'type_label' => 'Liquidación de abastecimiento',
            'folio' => $item->folio,
            'status' => $item->status,
            'enterprise' => $empresa->name,
            'productor' => $ficha['productor'],
            'convenio' => $ficha['convenio'],
            'details' => $details,
            'totals' => [
                'moneda' => $moneda,
                'cantidad' => $ficha['cantidad'],
                'kilos' => $ficha['kilos'],
                'bruto' => $ficha['bruto'],
                'rezaga_real_pct' => $ficha['rezaga_real_pct'],
                'rezaga_aceptada_pct' => $ficha['rezaga_aceptada_pct'],
                'descuento' => $ficha['descuento'],
                'monto_calculado' => $ficha['monto_calculado'],
                'monto_ajustado' => $ficha['monto_ajustado'],
                'motivo_ajuste' => $ficha['motivo_ajuste'],
                'monto_final' => $ficha['monto_final'],
            ],
            'detalles' => $ficha['detalles'],
            'current_step' => $item->paso_actual,
            'approvals' => collect($ficha['aprobaciones'])->map(fn (array $paso) => [
                'step_order' => $paso['step_order'],
                'description' => $paso['description'],
                'decision' => $paso['decision'],
                'decided_by' => $paso['decided_by_name'],
                'comment' => $paso['comment'],
                'decided_at' => $paso['decided_at'],
            ])->values()->all(),
            'notes' => $item->notas,
            'rejection_reason' => $item->rechazo_motivo,
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
                ['label' => 'Proveedor', 'value' => $item->supplier?->trade_name ?: ($item->supplier?->business_name ?? 'Sin proveedor')],
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

        // OC: un corporativo puede autorizar las de otras empresas (AprobadorOrdenCompra).
        if ($process->code === ApprovalProcess::PURCHASE_ORDERS) {
            return $this->aprobadorOc()->empresasQuePuedeAprobar($employee) !== [];
        }

        // Vacaciones: cadena por paso (jefe directo, jefe de departamento, usuario específico, corporativos y paso de RH).
        if ($process->code === ApprovalProcess::VACATION_REQUESTS) {
            return $process->canBeApprovedBy($employee, $enterpriseId)
                || $employee->subordinates()->where('status', Employee::STATUS_ACTIVE)->exists()
                || $employee->managedDepartments()->exists()
                || $this->esUsuarioEspecificoDeVacaciones($process, $user)
                || $this->aprobadorVac()->esRh($user)
                // Un corporativo puede aprobar por una regla de puesto de cualquier empresa (comprobación barata, sin recorrer solicitudes).
                || ($employee->position_id !== null
                    && $process->activeSteps()
                        ->where('approver_type', 'position')
                        ->where('position_id', $employee->position_id)
                        ->exists());
        }

        return $process->canBeApprovedBy($employee, $enterpriseId);
    }

    private function aprobadorOc(): AprobadorOrdenCompra
    {
        return $this->aprobadorOc ??= app(AprobadorOrdenCompra::class);
    }

    private function aprobadorVac(): AprobadorVacaciones
    {
        return $this->aprobadorVac ??= app(AprobadorVacaciones::class);
    }

    private function cadenaVac(): CadenaAprobacionVacaciones
    {
        return $this->cadenaVac ??= app(CadenaAprobacionVacaciones::class);
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

    /**
     * IDs de empresa (SplendidFarms u otras) donde el usuario tiene permiso
     * de escritura en el submódulo 'revision-asistencia' de Personal. A
     * diferencia de los procesos basados en ApprovalProcess (jerarquía
     * empleado/departamento), este acceso es por permiso de submódulo puro
     * — mismo patrón ya usado para transferencias de inventario
     * (hasTransferApprovalPermission), generalizado a "puede pertenecer a
     * varias empresas" porque el módulo de Personal SF usa
     * User::activeEnterprises(), no un Employee único por usuario.
     */
    private function getFieldCheckReviewableEnterpriseIds($user): array
    {
        if (! Schema::hasTable('user_submodule_access')) {
            return [];
        }

        $submoduleRows = DB::table('submodules')
            ->join('modules', 'submodules.module_id', '=', 'modules.id')
            ->join('applications', 'modules.application_id', '=', 'applications.id')
            ->where('applications.slug', 'administration')
            ->where('modules.slug', 'personal')
            ->where('submodules.slug', 'revision-asistencia')
            ->pluck('applications.enterprise_id', 'submodules.id');

        if ($submoduleRows->isEmpty()) {
            return [];
        }

        $submoduleIds = $submoduleRows->keys();

        $accessibleSubmoduleIds = DB::table('user_submodule_access')
            ->where('user_id', $user->id)
            ->whereIn('submodule_id', $submoduleIds)
            ->where('is_active', true)
            ->pluck('submodule_id');

        if ($accessibleSubmoduleIds->isEmpty()) {
            return [];
        }

        // Sin filas en user_submodule_permissions para estos submódulos: se
        // trata como acceso permitido (mismo comportamiento que
        // hasTransferApprovalPermission cuando $permissionRows está vacío) —
        // evita bloquear el carve-out antes de que alguien configure tipos
        // de permiso explícitos vía /admin/permission-types.
        if (! Schema::hasTable('user_submodule_permissions') || ! Schema::hasTable('submodule_permission_types')) {
            return $accessibleSubmoduleIds->map(fn ($id) => (int) $submoduleRows[$id])->unique()->values()->all();
        }

        // Seleccionar solo columnas que realmente existen: is_granted/granted y
        // slug/key son nombres alternativos vistos en distintas migraciones de
        // este mismo esquema (ver 2025_12_05_000004 y _000005), y seleccionar
        // una columna inexistente rompe la consulta en MySQL/SQLite sin
        // importar cuántas filas matcheen — a diferencia de hasTransferApprovalPermission,
        // aquí se arma la lista de columnas dinámicamente para no reintroducir
        // ese mismo problema.
        $grantedColumn = Schema::hasColumn('user_submodule_permissions', 'is_granted') ? 'is_granted' : 'granted';
        $slugColumn = Schema::hasColumn('submodule_permission_types', 'slug') ? 'slug' : 'key';

        $permissionRows = DB::table('user_submodule_permissions as usp')
            ->leftJoin('submodule_permission_types as spt', 'usp.permission_type_id', '=', 'spt.id')
            ->where('usp.user_id', $user->id)
            ->whereIn('usp.submodule_id', $accessibleSubmoduleIds)
            ->get(['usp.submodule_id', "usp.{$grantedColumn} as is_granted", "spt.{$slugColumn} as slug"]);

        $acceptedSlugs = ['ver', 'view', 'editar', 'edit', 'aprobar', 'approve'];
        $enterpriseIds = [];

        foreach ($accessibleSubmoduleIds as $submoduleId) {
            $rowsForSubmodule = $permissionRows->where('submodule_id', $submoduleId);

            if ($rowsForSubmodule->isEmpty()) {
                $enterpriseIds[] = (int) $submoduleRows[$submoduleId];
                continue;
            }

            foreach ($rowsForSubmodule as $row) {
                $isGranted = (bool) $row->is_granted;
                $slug = strtolower((string) ($row->slug ?? ''));

                if ($isGranted && $slug !== '' && in_array($slug, $acceptedSlugs, true)) {
                    $enterpriseIds[] = (int) $submoduleRows[$submoduleId];
                    break;
                }
            }
        }

        return array_values(array_unique($enterpriseIds));
    }

    /**
     * Entrada sintética para el dropdown global "Por Aprobar", o null si el
     * usuario no tiene permiso en ninguna empresa. No es un ApprovalProcess
     * (decisión de diseño: este acceso es por permiso, no por jerarquía) así
     * que se construye a mano con la misma forma que las entradas normales.
     */
    private function getFieldCheckReviewProcessEntry(Request $request, $user): ?array
    {
        $enterpriseIds = $this->getFieldCheckReviewableEnterpriseIds($user);
        if (empty($enterpriseIds)) {
            return null;
        }

        $count = SfFieldCheck::whereIn('enterprise_id', $enterpriseIds)
            ->whereIn('verification_status', [
                SfFieldCheck::STATUS_LOW_CONFIDENCE,
                SfFieldCheck::STATUS_MISMATCH,
                SfFieldCheck::STATUS_NO_TEMPLATE,
            ])
            ->count();

        return [
            'process_id' => null,
            'code' => 'field_check_review',
            'name' => 'Revisión de asistencia biométrica',
            'module' => 'administration',
            'description' => 'Chequeos de campo que no se verificaron automáticamente',
            'pending_count' => $count,
            'scope' => 'enterprise',
            'route' => '/administration/personal/revision-asistencia',
        ];
    }

    /**
     * IDs de empresa donde el usuario tiene permiso de escritura en el
     * submódulo 'revision-checador' de RH > Asistencia. Mismo patrón que
     * getFieldCheckReviewableEnterpriseIds (campo, Splendid Farms) —
     * acceso por permiso de submódulo, no por jerarquía ApprovalProcess.
     */
    private function getTimeClockReviewableEnterpriseIds($user): array
    {
        if (! Schema::hasTable('user_submodule_access')) {
            return [];
        }

        $submoduleRows = DB::table('submodules')
            ->join('modules', 'submodules.module_id', '=', 'modules.id')
            ->join('applications', 'modules.application_id', '=', 'applications.id')
            ->where('applications.slug', 'rh')
            ->where('modules.slug', 'asistencia')
            ->where('submodules.slug', 'revision-checador')
            ->pluck('applications.enterprise_id', 'submodules.id');

        if ($submoduleRows->isEmpty()) {
            return [];
        }

        $submoduleIds = $submoduleRows->keys();

        $accessibleSubmoduleIds = DB::table('user_submodule_access')
            ->where('user_id', $user->id)
            ->whereIn('submodule_id', $submoduleIds)
            ->where('is_active', true)
            ->pluck('submodule_id');

        if ($accessibleSubmoduleIds->isEmpty()) {
            return [];
        }

        if (! Schema::hasTable('user_submodule_permissions') || ! Schema::hasTable('submodule_permission_types')) {
            // Gate de permiso superado: RH es una app corporativa
            // multi-empresa (applications.slug='rh' vive bajo un solo
            // enterprise_id, pero los empleados de RH abarcan varias
            // empresas reales) — a diferencia del gate mismo (arriba, sin
            // tocar), lo que se devuelve aquí ya NO es "la empresa dueña
            // del submódulo" sino todas las empresas donde el usuario
            // tiene acceso activo real.
            return \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('enterprise_id')
                ->unique()
                ->values()
                ->all();
        }

        $grantedColumn = Schema::hasColumn('user_submodule_permissions', 'is_granted') ? 'is_granted' : 'granted';
        $slugColumn = Schema::hasColumn('submodule_permission_types', 'slug') ? 'slug' : 'key';

        $permissionRows = DB::table('user_submodule_permissions as usp')
            ->leftJoin('submodule_permission_types as spt', 'usp.permission_type_id', '=', 'spt.id')
            ->where('usp.user_id', $user->id)
            ->whereIn('usp.submodule_id', $accessibleSubmoduleIds)
            ->get(['usp.submodule_id', "usp.{$grantedColumn} as is_granted", "spt.{$slugColumn} as slug"]);

        $acceptedSlugs = ['ver', 'view', 'editar', 'edit', 'aprobar', 'approve'];
        $enterpriseIds = [];

        foreach ($accessibleSubmoduleIds as $submoduleId) {
            $rowsForSubmodule = $permissionRows->where('submodule_id', $submoduleId);

            if ($rowsForSubmodule->isEmpty()) {
                $enterpriseIds[] = (int) $submoduleRows[$submoduleId];
                continue;
            }

            foreach ($rowsForSubmodule as $row) {
                $isGranted = (bool) $row->is_granted;
                $slug = strtolower((string) ($row->slug ?? ''));

                if ($isGranted && $slug !== '' && in_array($slug, $acceptedSlugs, true)) {
                    $enterpriseIds[] = (int) $submoduleRows[$submoduleId];
                    break;
                }
            }
        }

        if (empty($enterpriseIds)) {
            return [];
        }

        // Gate de permiso superado (ver comentario en la rama de arriba):
        // se devuelven todas las empresas del usuario, no solo la empresa
        // dueña del submódulo RH.
        return \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('enterprise_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Entrada sintética para el dropdown global "Por Aprobar", o null si el
     * usuario no tiene permiso en ninguna empresa.
     */
    private function getTimeClockReviewProcessEntry(Request $request, $user): ?array
    {
        $enterpriseIds = $this->getTimeClockReviewableEnterpriseIds($user);
        if (empty($enterpriseIds)) {
            return null;
        }

        $count = TimeClockCheck::whereHas('employee', fn ($q) => $q->whereIn('enterprise_id', $enterpriseIds))
            ->whereIn('verification_status', [
                TimeClockCheck::STATUS_LOW_CONFIDENCE,
                TimeClockCheck::STATUS_NO_TEMPLATE,
            ])
            ->count();

        return [
            'process_id' => null,
            'code' => 'time_clock_check_review',
            'name' => 'Revisión de checador biométrico',
            'module' => 'rh',
            'description' => 'Chequeos de asistencia que no se verificaron automáticamente',
            'pending_count' => $count,
            'scope' => 'enterprise',
            'route' => '/rh/asistencia/revision-checador',
        ];
    }
}
