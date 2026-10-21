<?php

namespace App\Services\RH;

use App\Models\Employee;
use App\Models\User;
use App\Models\VacationHistoryEntry;
use App\Models\VacationRequest;
use App\Services\VacationCalculatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historial de vacaciones que captura RH (lo que llevaba en Excel). Sin cadena de
 * aprobación ni asistencia; los días cuentan como tomados en el saldo. Las reglas
 * viven aquí para que el formulario y la importación digan exactamente lo mismo.
 *
 * Datos: vacation_year, start_date y end_date (Y-m-d, las dos o ninguna), days, notes.
 */
class HistorialVacaciones
{
    public const MAX_DIAS = 120;

    /**
     * Errores de negocio por campo; vacío si se puede guardar.
     *
     * @param  array<int, array{start_date: string, end_date: string, fila?: int}>  $otrosDelLote  periodos ya aceptados en la misma importación
     * @return array<string, string>
     */
    public function errores(Employee $empleado, array $datos, ?int $ignorarId = null, array $otrosDelLote = []): array
    {
        $hoy = today()->format('Y-m-d');
        $ingreso = $empleado->hire_date?->format('Y-m-d');
        $anio = (int) ($datos['vacation_year'] ?? 0);
        $dias = (int) ($datos['days'] ?? 0);
        $inicio = $datos['start_date'] ?? null;
        $fin = $datos['end_date'] ?? null;
        $errores = [];

        if ($anio > (int) today()->year) {
            $errores['vacation_year'] = 'El año no puede ser futuro.';
        } elseif ($ingreso && $anio < (int) substr($ingreso, 0, 4)) {
            $errores['vacation_year'] = 'El año es anterior al ingreso del empleado ('.substr($ingreso, 0, 4).').';
        }

        if ($dias < 1 || $dias > self::MAX_DIAS) {
            $errores['days'] = 'Los días deben estar entre 1 y '.self::MAX_DIAS.'.';
        }

        if (($inicio === null) !== ($fin === null)) {
            $errores[$inicio === null ? 'start_date' : 'end_date'] = 'Escribe las dos fechas o ninguna.';

            return $errores;
        }

        if ($inicio === null) {
            return $errores;
        }

        if ($fin < $inicio) {
            $errores['end_date'] = 'La fecha final no puede ser anterior a la inicial.';
        } elseif ($fin > $hoy) {
            $errores['end_date'] = 'El historial no admite fechas futuras: captúralas como solicitud.';
        } elseif ($ingreso && $inicio < $ingreso) {
            $errores['start_date'] = 'La fecha es anterior al ingreso del empleado.';
        }

        if (isset($errores['start_date']) || isset($errores['end_date'])) {
            return $errores;
        }

        $encimada = VacationHistoryEntry::where('employee_id', $empleado->id)
            ->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))
            ->whereNotNull('start_date')
            ->whereDate('start_date', '<=', $fin)
            ->whereDate('end_date', '>=', $inicio)
            ->first();
        if ($encimada) {
            $errores['start_date'] = "Se encima con otro periodo del historial (del {$encimada->start_date->format('d/m/Y')} al {$encimada->end_date->format('d/m/Y')}).";

            return $errores;
        }

        $solicitud = VacationRequest::where('employee_id', $empleado->id)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('start_date', '<=', $fin)
            ->whereDate('end_date', '>=', $inicio)
            ->first();
        if ($solicitud) {
            $folio = 'VAC-'.str_pad((string) $solicitud->id, 6, '0', STR_PAD_LEFT);
            $errores['start_date'] = "Se encima con la solicitud {$folio} (del {$solicitud->start_date->format('d/m/Y')} al {$solicitud->end_date->format('d/m/Y')}).";

            return $errores;
        }

        foreach ($otrosDelLote as $otro) {
            if ($inicio <= $otro['end_date'] && $fin >= $otro['start_date']) {
                $errores['start_date'] = isset($otro['fila'])
                    ? "Se encima con la fila {$otro['fila']} del archivo."
                    : 'Se encima con otro periodo de la importación.';
                break;
            }
        }

        return $errores;
    }

    /** ¿Ya existe este mismo registro (mismas fechas, o mismo año y días sin fechas)? Así reimportar no duplica. */
    public function yaExiste(Employee $empleado, array $datos): bool
    {
        $consulta = VacationHistoryEntry::where('employee_id', $empleado->id);

        if (($datos['start_date'] ?? null) !== null) {
            return $consulta->whereDate('start_date', $datos['start_date'])->whereDate('end_date', $datos['end_date'])->exists();
        }

        return $consulta->whereNull('start_date')
            ->where('vacation_year', (int) $datos['vacation_year'])
            ->where('days', (int) $datos['days'])
            ->exists();
    }

    /**
     * Revisa un lote de la importación sin guardar: errores por fila (contando los
     * encimados dentro del mismo lote), las que ya existían y el saldo de cada
     * empleado antes y después.
     *
     * @param  array<int, array>  $filas  employee_id, vacation_year, start_date, end_date, days, notes y, opcional, fila (la del Excel)
     * @return array{filas: array, saldos: array}
     */
    public function revisarLote(array $filas): array
    {
        $empleados = Employee::whereIn('id', array_unique(array_map(fn ($f) => (int) $f['employee_id'], $filas)))->get()->keyBy('id');
        $aceptadas = [];
        $dias = [];
        $resultado = [];

        foreach (array_values($filas) as $indice => $fila) {
            $empleado = $empleados[(int) $fila['employee_id']];
            $base = ['indice' => $indice, 'fila' => $fila['fila'] ?? null];

            if ($this->yaExiste($empleado, $fila)) {
                $resultado[] = $base + ['ya_importada' => true, 'errores' => []];

                continue;
            }

            $errores = $this->errores($empleado, $fila, null, $aceptadas[$empleado->id] ?? []);
            if ($errores === []) {
                if (($fila['start_date'] ?? null) !== null) {
                    $aceptadas[$empleado->id][] = ['start_date' => $fila['start_date'], 'end_date' => $fila['end_date'], 'fila' => $fila['fila'] ?? null];
                }
                $dias[$empleado->id] = ($dias[$empleado->id] ?? 0) + (int) $fila['days'];
            }
            $resultado[] = $base + ['ya_importada' => false, 'errores' => $errores];
        }

        $saldos = $empleados->map(function (Employee $empleado) use ($dias) {
            $actual = VacationCalculatorService::diasDisponibles($empleado);
            $nuevos = $dias[$empleado->id] ?? 0;

            return [
                'employee_id' => $empleado->id,
                'nombre' => $empleado->full_name,
                'saldo_actual' => $actual,
                'dias_a_importar' => $nuevos,
                'saldo_despues' => $actual - $nuevos,
            ];
        })->values()->all();

        return ['filas' => $resultado, 'saldos' => $saldos];
    }

    /**
     * Guarda un lote de la importación: todo o nada. Las filas que ya existían se saltan.
     *
     * @return array{importadas: int, ya_importadas: int}
     */
    public function importarLote(array $filas, ?User $usuario, ?string $archivo): array
    {
        return DB::transaction(function () use ($filas, $usuario, $archivo) {
            // Bloquea a los empleados en orden de id: nadie más captura su historial a la mitad.
            Employee::whereIn('id', array_map(fn ($f) => (int) $f['employee_id'], $filas))->orderBy('id')->lockForUpdate()->get();
            $revision = $this->revisarLote($filas);

            $errores = [];
            foreach ($revision['filas'] as $resultado) {
                foreach ($resultado['errores'] as $campo => $mensaje) {
                    $errores["filas.{$resultado['indice']}.{$campo}"] = $mensaje;
                }
            }
            $this->exigirSinErrores($errores);

            $importadas = 0;
            foreach ($revision['filas'] as $resultado) {
                if ($resultado['ya_importada']) {
                    continue;
                }
                $fila = array_values($filas)[$resultado['indice']];
                VacationHistoryEntry::create($this->columnas($fila) + [
                    'employee_id' => (int) $fila['employee_id'],
                    'source' => VacationHistoryEntry::ORIGEN_IMPORTACION,
                    'import_file' => $archivo ? mb_substr($archivo, 0, 255) : null,
                    'created_by' => $usuario?->id,
                ]);
                $importadas++;
            }

            return ['importadas' => $importadas, 'ya_importadas' => count($revision['filas']) - $importadas];
        });
    }

    public function crear(Employee $empleado, array $datos, ?User $usuario, string $origen = VacationHistoryEntry::ORIGEN_MANUAL, ?string $archivo = null): VacationHistoryEntry
    {
        return DB::transaction(function () use ($empleado, $datos, $usuario, $origen, $archivo) {
            // Bloquea al empleado: dos altas simultáneas no pueden encimarse.
            Employee::whereKey($empleado->id)->lockForUpdate()->first();
            $this->exigirSinErrores($this->errores($empleado, $datos));

            return VacationHistoryEntry::create($this->columnas($datos) + [
                'employee_id' => $empleado->id,
                'source' => $origen,
                'import_file' => $archivo ? mb_substr($archivo, 0, 255) : null,
                'created_by' => $usuario?->id,
            ]);
        });
    }

    public function actualizar(VacationHistoryEntry $entrada, array $datos, ?User $usuario): VacationHistoryEntry
    {
        return DB::transaction(function () use ($entrada, $datos, $usuario) {
            $empleado = Employee::whereKey($entrada->employee_id)->lockForUpdate()->firstOrFail();
            $this->exigirSinErrores($this->errores($empleado, $datos, $entrada->id));
            $entrada->update($this->columnas($datos) + ['updated_by' => $usuario?->id]);

            return $entrada->fresh();
        });
    }

    public function borrar(VacationHistoryEntry $entrada): void
    {
        $entrada->delete();
    }

    private function columnas(array $datos): array
    {
        $nota = trim((string) ($datos['notes'] ?? ''));

        return [
            'vacation_year' => (int) $datos['vacation_year'],
            'start_date' => $datos['start_date'] ?? null,
            'end_date' => $datos['end_date'] ?? null,
            'days' => (int) $datos['days'],
            'notes' => $nota === '' ? null : mb_substr($nota, 0, 500),
        ];
    }

    private function exigirSinErrores(array $errores): void
    {
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }
}
