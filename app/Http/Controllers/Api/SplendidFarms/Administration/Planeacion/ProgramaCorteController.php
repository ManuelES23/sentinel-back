<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rendimiento elegido para cada variedad de la semana (el corte se captura en
 * `HojaCorteController`). Exige `plan-corte.capturar` y avisa en tiempo real.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class ProgramaCorteController extends Controller
{
    use PermisoPlaneacion;

    private const MODOS_RENDIMIENTO = ['minimo', 'medio', 'maximo', 'exacto'];

    /** Rendimiento elegido por (temporada, variedad) para esta semana; crea o actualiza cada fila. */
    public function rendimientos(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('plan-corte', 'capturar');

        $datos = $request->validate([
            'filas' => ['required', 'array', 'min:1', 'max:200'],
            'filas.*.temporada_id' => ['required', 'integer'],
            'filas.*.variedad_id' => ['required', 'integer'],
            'filas.*.modo' => ['required', Rule::in(self::MODOS_RENDIMIENTO)],
            'filas.*.valor_exacto' => ['nullable', 'required_if:filas.*.modo,exacto', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
        ]);

        $temporadas = $servicio->temporadasDeSemana($programa)->keyBy('id');
        $vistas = [];
        foreach ($datos['filas'] as $i => $fila) {
            $temporada = $temporadas->get((int) $fila['temporada_id']);
            if (! $temporada) {
                throw ValidationException::withMessages(["filas.$i.temporada_id" => 'La temporada no está abierta en esta semana.']);
            }
            if ($servicio->cultivoDeVariedad((int) $fila['variedad_id']) !== (int) $temporada->cultivo_id) {
                throw ValidationException::withMessages(["filas.$i.variedad_id" => 'La variedad no es del cultivo de la temporada.']);
            }
            $llave = $fila['temporada_id'].'-'.$fila['variedad_id'];
            if (isset($vistas[$llave])) {
                throw ValidationException::withMessages(["filas.$i.variedad_id" => 'La variedad está repetida en la misma temporada.']);
            }
            $vistas[$llave] = true;
        }

        $guardadas = DB::transaction(function () use ($programa, $datos, $registro) {
            $salida = [];
            foreach ($datos['filas'] as $fila) {
                $llave = [
                    'programa_id' => $programa->id,
                    'temporada_id' => (int) $fila['temporada_id'],
                    'variedad_id' => (int) $fila['variedad_id'],
                ];
                // El valor exacto solo se guarda con el modo exacto.
                $valores = [
                    'modo' => $fila['modo'],
                    'valor_exacto' => $fila['modo'] === 'exacto' ? $fila['valor_exacto'] : null,
                ];

                $existente = ProgramaRendimiento::where($llave)->lockForUpdate()->first();
                $rendimiento = $existente ?? new ProgramaRendimiento($llave);
                $rendimiento->fill($valores);

                // Sin cambio de valor no hay escritura ni aviso.
                if ($existente && ! $rendimiento->isDirty()) {
                    $salida[] = $rendimiento;

                    continue;
                }

                $rendimiento->save();
                $registro->emitir($programa);
                $salida[] = $rendimiento;
            }

            return $salida;
        });

        return response()->json([
            'success' => true,
            'message' => 'Rendimientos guardados.',
            'data' => array_map(fn ($r) => $this->instantaneaRendimiento($r), $guardadas),
        ]);
    }

    // ── Salidas ─────────────────────────────────────────

    private function instantaneaRendimiento(ProgramaRendimiento $r): array
    {
        return [
            'temporada_id' => $r->temporada_id,
            'variedad_id' => $r->variedad_id,
            'modo' => $r->modo,
            'valor_exacto' => $r->valor_exacto !== null ? (float) $r->valor_exacto : null,
        ];
    }
}
