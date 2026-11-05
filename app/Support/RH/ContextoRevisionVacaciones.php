<?php

namespace App\Support\RH;

use App\Models\Employee;
use App\Models\VacationBalance;
use App\Models\VacationHistoryEntry;
use App\Models\VacationRequest;
use App\Models\VacationRequestApproval;
use App\Services\RH\CadenaAprobacionVacaciones;
use App\Services\RH\WorkingDaysCalculator;
use App\Services\VacationCalculatorService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que necesita ver quien revisa una solicitud ajena (jefe o director, desde el portal): saldo, desglose de
 * días, historial por periodo vacacional, coincidencias del departamento, cuándo llegó y qué sigue (spec §5.5).
 *
 * Número de consultas acotado: una para las solicitudes del empleado (con los pasos rechazados), una para su
 * historial de RH (ambas agrupadas en PHP), una para las coincidencias y las constantes del saldo, del calculador
 * de días y de la cadena. Nada crece con el número de ítems del historial. El historial NUNCA incluye
 * `vacation_history.notes` (interna de RH).
 */
class ContextoRevisionVacaciones
{
    private const MAX_PERIODOS = 3;

    /** Cuántos días después de fecha_fin se busca el primer día que se descuenta («regresa»). */
    private const DIAS_PARA_REGRESAR = 14;

    private const MAX_COINCIDENCIAS = 20;

    public function __construct(private WorkingDaysCalculator $calculadora, private CadenaAprobacionVacaciones $cadena) {}

    /** @return array<string, mixed> solicitante_extra, saldo, desglose, historial, coinciden, llego_el y siguiente */
    public static function para(VacationRequest $solicitud): array
    {
        return app(self::class)->contexto($solicitud);
    }

    /**
     * `saldo: {despues, adelanto}` de cada solicitud de la lista, con 3 consultas agrupadas en total (no una por
     * solicitud). Mismo resultado que el saldo del detalle; sin fecha de ingreso, en ceros.
     *
     * @param  Collection<int, VacationRequest>  $solicitudes  con `employee` cargado
     * @return array<int, array{despues: int, adelanto: int}> por id de solicitud
     */
    public static function saldosDeLista(Collection $solicitudes): array
    {
        $empleados = $solicitudes->pluck('employee')->filter()->unique('id');
        $ids = $empleados->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $porEstado = VacationRequest::whereIn('employee_id', $ids)
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('employee_id, status, SUM(days_requested) as dias')
            ->groupBy('employee_id', 'status')
            ->get()
            ->groupBy('employee_id');
        $historial = VacationHistoryEntry::whereIn('employee_id', $ids)
            ->selectRaw('employee_id, SUM(days) as dias')->groupBy('employee_id')->pluck('dias', 'employee_id');
        $ajustes = VacationBalance::whereIn('employee_id', $ids)
            ->selectRaw('employee_id, SUM(adjustment_days) as dias')->groupBy('employee_id')->pluck('dias', 'employee_id');

        $despues = [];
        foreach ($empleados as $empleado) {
            if (! $empleado->hire_date) {
                $despues[$empleado->id] = 0;

                continue;
            }

            $estados = ($porEstado[$empleado->id] ?? collect())->pluck('dias', 'status');
            $acumulado = VacationCalculatorService::calculateTotalAccumulatedDays(Carbon::parse($empleado->hire_date))['total_accumulated_days'];
            $despues[$empleado->id] = $acumulado + (int) ($ajustes[$empleado->id] ?? 0)
                - (int) ($estados['approved'] ?? 0) - (int) ($historial[$empleado->id] ?? 0) - (int) ($estados['pending'] ?? 0);
        }

        return $solicitudes->mapWithKeys(fn (VacationRequest $solicitud) => [
            $solicitud->id => self::despuesYAdelanto($despues[$solicitud->employee_id] ?? 0, (int) $solicitud->days_requested),
        ])->all();
    }

    private static function despuesYAdelanto(int $despues, int $dias): array
    {
        return ['despues' => $despues, 'adelanto' => min($dias, max(0, -$despues))];
    }

    private function contexto(VacationRequest $solicitud): array
    {
        $empleado = $solicitud->employee;
        $base = PresentadorPortalVacaciones::saldo($empleado);
        $sinIngreso = $base['sin_fecha_ingreso'];
        $dias = (int) $solicitud->days_requested;
        $hoy = Carbon::today();

        // Las pendientes ya se descuentan del saldo actual: «después» es el saldo de hoy y «antes» le suma esta.
        $despues = $sinIngreso ? 0 : $base['disponibles'];

        $solicitudes = VacationRequest::where('employee_id', $empleado->id)
            ->where('id', '!=', $solicitud->id)
            ->where('status', '!=', 'cancelled')
            ->with([
                'approvals' => fn ($q) => $q->where('decision', VacationRequestApproval::REJECTED),
                'approvals.decider:id,name',
                'approvals.decidedByEmployee:id,first_name,last_name,second_last_name',
            ])
            ->get();
        $capturas = VacationHistoryEntry::where('employee_id', $empleado->id)->get();

        $grupos = $this->grupos($empleado, $solicitudes, $capturas);
        $ingreso = $sinIngreso || ! $empleado->hire_date ? null : Carbon::parse($empleado->hire_date)->startOfDay();
        $actual = $ingreso ? $this->indiceDePeriodo($ingreso, $hoy) : null;
        $periodoActual = $ingreso ? $this->periodo($ingreso, $actual) : null;

        return [
            'solicitante_extra' => [
                'fecha_ingreso' => $ingreso?->format('Y-m-d'),
                'antiguedad' => $base['antiguedad'],
            ],
            'saldo' => [
                'disponibles' => $sinIngreso ? 0 : $despues + $dias,
                'despues' => $despues,
                'le_corresponden_periodo' => $ingreso ? VacationCalculatorService::calculateEntitledDays($actual) : 0,
                'tomados_periodo' => $ingreso ? (int) ($grupos[$actual]['tomados'] ?? 0) : 0,
                'periodo' => $periodoActual ? ['inicio' => $periodoActual['inicio'], 'fin' => $periodoActual['fin']] : null,
                'otras_en_revision' => (int) $solicitudes->where('status', 'pending')->sum('days_requested'),
                'adelanto' => $sinIngreso ? 0 : self::despuesYAdelanto($despues, $dias)['adelanto'],
                'proximo_aniversario' => $sinIngreso ? null : [
                    'fecha' => $base['proximo_aniversario'],
                    'dias' => $base['dias_proximo_periodo'],
                ],
            ],
            'desglose' => $this->desglose($solicitud, $empleado),
            'historial' => $grupos->sortKeysDesc()->take(self::MAX_PERIODOS)->values()->all(),
            'coinciden' => $this->coinciden($solicitud, $empleado),
            'llego_el' => $this->llegoEl($solicitud),
            'siguiente' => $this->cadena->siguienteSiAprueba($solicitud)?->description,
        ];
    }

    /**
     * Agrupa por periodo vacacional (de aniversario a aniversario) según la fecha de inicio, no por vacation_year.
     * Una captura sin fechas con vacation_year = Y va al periodo que empieza con el aniversario del año Y (al
     * primero si el ingreso fue en Y). Sin fecha de ingreso: un solo grupo sin inicio ni fin.
     *
     * @return Collection<int, array{inicio: ?string, fin: ?string, le_corresponden: int, tomados: int, items: list<array>}>
     */
    private function grupos(Employee $empleado, Collection $solicitudes, Collection $capturas): Collection
    {
        $ingreso = $empleado->hire_date ? Carbon::parse($empleado->hire_date)->startOfDay() : null;
        $grupos = [];
        $ordenes = [];

        $colocar = function (int $indice, array $item, ?string $orden, int $tomados) use (&$grupos, &$ordenes, $ingreso): void {
            if (! isset($grupos[$indice])) {
                $periodo = $ingreso ? $this->periodo($ingreso, $indice) : ['inicio' => null, 'fin' => null];
                $grupos[$indice] = [
                    'inicio' => $periodo['inicio'],
                    'fin' => $periodo['fin'],
                    'le_corresponden' => $ingreso ? VacationCalculatorService::calculateEntitledDays($indice) : 0,
                    'tomados' => 0,
                    'items' => [],
                ];
            }
            $grupos[$indice]['tomados'] += $tomados;
            $grupos[$indice]['items'][] = $item;
            $ordenes[$indice][] = $orden ?? '';
        };

        foreach ($solicitudes as $s) {
            $inicio = $s->start_date;
            $colocar(
                $ingreso ? $this->indiceDePeriodo($ingreso, $inicio) : 0,
                [
                    'tipo' => 'solicitud',
                    'fecha_inicio' => $inicio->format('Y-m-d'),
                    'fecha_fin' => $s->end_date->format('Y-m-d'),
                    'dias' => (int) $s->days_requested,
                    'estado' => $s->status,
                    'motivo' => $s->reason,
                    'rechazo' => $s->status === 'rejected' ? $this->rechazo($s) : null,
                ],
                $inicio->format('Y-m-d'),
                $s->status === 'approved' ? (int) $s->days_requested : 0,
            );
        }

        foreach ($capturas as $c) {
            $indice = 0;
            if ($ingreso) {
                $indice = $c->start_date
                    ? $this->indiceDePeriodo($ingreso, $c->start_date)
                    : max(0, (int) $c->vacation_year - $ingreso->year);
            }
            $colocar(
                $indice,
                [
                    'tipo' => 'historial_rh',
                    'fecha_inicio' => $c->start_date?->format('Y-m-d'),
                    'fecha_fin' => $c->end_date?->format('Y-m-d'),
                    'dias' => (int) $c->days,
                ],
                $c->start_date?->format('Y-m-d'),
                (int) $c->days,
            );
        }

        // Dentro de cada periodo, lo más reciente primero (las capturas sin fecha al final).
        foreach ($grupos as $indice => &$grupo) {
            $claves = $ordenes[$indice];
            $posiciones = array_keys($grupo['items']);
            usort($posiciones, fn ($a, $b) => strcmp($claves[$b], $claves[$a]) ?: $b <=> $a);
            $grupo['items'] = array_map(fn ($p) => $grupo['items'][$p], $posiciones);
        }
        unset($grupo);

        return collect($grupos);
    }

    /** @return array{por: ?string, motivo: ?string} */
    private function rechazo(VacationRequest $solicitud): array
    {
        $paso = $solicitud->approvals->first();

        return [
            'por' => $paso?->nombreDeQuienDecidio() ?? $paso?->description,
            'motivo' => $solicitud->rejection_reason ?? $paso?->comment,
        ];
    }

    /** Índice del periodo vacacional que contiene la fecha: años cumplidos desde el ingreso (0 = antes del primer aniversario). */
    private function indiceDePeriodo(Carbon $ingreso, Carbon $fecha): int
    {
        $fecha = $fecha->copy()->startOfDay();
        $indice = max(0, $fecha->year - $ingreso->year);
        while ($indice > 0 && $ingreso->copy()->addYearsNoOverflow($indice)->gt($fecha)) {
            $indice--;
        }

        return $indice;
    }

    /** @return array{inicio: string, fin: string} del aniversario `indice` (inclusive) al día anterior del siguiente */
    private function periodo(Carbon $ingreso, int $indice): array
    {
        return [
            'inicio' => $ingreso->copy()->addYearsNoOverflow($indice)->format('Y-m-d'),
            'fin' => $ingreso->copy()->addYearsNoOverflow($indice + 1)->subDay()->format('Y-m-d'),
        ];
    }

    /** @return array{dias_naturales: int, dias_descontables: int, excluidos: list<array>, regresa: ?string} */
    private function desglose(VacationRequest $solicitud, Employee $empleado): array
    {
        $calculo = $this->calculadora->calcular($empleado, $solicitud->start_date, $solicitud->end_date);

        // Primer día después de fecha_fin que el calculador cuenta como descontable (hasta 14 días adelante).
        $despues = $this->calculadora->calcular(
            $empleado,
            $solicitud->end_date->copy()->addDay(),
            $solicitud->end_date->copy()->addDays(self::DIAS_PARA_REGRESAR),
        );

        return [
            'dias_naturales' => count($calculo['descontables']) + count($calculo['excluidos']),
            'dias_descontables' => count($calculo['descontables']),
            'excluidos' => $calculo['excluidos'],
            'regresa' => $despues['descontables'][0] ?? null,
        ];
    }

    /** @return list<array{nombre: string, fecha_inicio: string, fecha_fin: string, estado: string}> */
    private function coinciden(VacationRequest $solicitud, Employee $empleado): array
    {
        if (! $empleado->department_id) {
            return [];
        }

        return VacationRequest::query()
            ->join('employees', 'employees.id', '=', 'vacation_requests.employee_id')
            ->where('employees.department_id', $empleado->department_id)
            ->whereNull('employees.deleted_at')
            ->where('vacation_requests.employee_id', '!=', $empleado->id)
            ->whereIn('vacation_requests.status', ['approved', 'pending'])
            ->whereDate('vacation_requests.start_date', '<=', $solicitud->end_date->format('Y-m-d'))
            ->whereDate('vacation_requests.end_date', '>=', $solicitud->start_date->format('Y-m-d'))
            ->orderBy('vacation_requests.start_date')
            ->orderBy('vacation_requests.id')
            ->limit(self::MAX_COINCIDENCIAS)
            ->select('vacation_requests.start_date', 'vacation_requests.end_date', 'vacation_requests.status',
                'employees.first_name', 'employees.last_name', 'employees.second_last_name')
            ->get()
            ->map(fn (VacationRequest $otra) => [
                'nombre' => trim($otra->first_name.' '.$otra->last_name.' '.$otra->second_last_name),
                'fecha_inicio' => $otra->start_date->format('Y-m-d'),
                'fecha_fin' => $otra->end_date->format('Y-m-d'),
                'estado' => $otra->status,
            ])
            ->all();
    }

    /** Cuándo llegó a quien revisa: la decisión del último paso aprobado antes del actual, o la creación si es el primero. */
    private function llegoEl(VacationRequest $solicitud): ?string
    {
        $anterior = $solicitud->approvals
            ->filter(fn (VacationRequestApproval $paso) => $paso->decision === VacationRequestApproval::APPROVED
                && $paso->step_order < (int) $solicitud->current_step)
            ->sortByDesc('step_order')
            ->first();

        return ($anterior?->decided_at ?? $solicitud->created_at)?->toIso8601String();
    }
}
