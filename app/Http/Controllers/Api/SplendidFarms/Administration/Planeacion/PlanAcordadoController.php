<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\ProgramaPlanAcordado;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\PlanAcordado;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Plan acordado en la junta de la semana: guarda una foto del plan de corte y
 * de embarques (`tablero-semanal.acordar`) y la devuelve. Acordar no bloquea la
 * edición del plan; puede repetirse y vale la foto más reciente. No toca
 * `programa_juntas`, `programa_cambios` ni `programas_semanales.junta_*`.
 */
class PlanAcordadoController extends Controller
{
    use PermisoPlaneacion;

    public function store(ProgramaSemanal $programa, PlanAcordado $planes, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('tablero-semanal', 'acordar');

        $foto = DB::transaction(function () use ($programa, $planes, $registro) {
            // El bloqueo de la fila del programa DEBE ser la primera sentencia de
            // la transacción (MySQL toma la «foto» en la primera lectura sin
            // bloqueo): las hojas de captura bloquean la misma fila, así que la
            // foto ve todo lo que una captura simultánea ya confirmó o espera a
            // que termine.
            $fila = ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->firstOrFail();

            $foto = $planes->acordar($fila, Auth::id());
            $registro->emitir($fila);

            return $foto;
        });

        return response()->json([
            'success' => true,
            'message' => 'Plan acordado.',
            'data' => $this->cabecera($foto) + [
                'cortes' => count($foto->cortes),
                'embarques' => count($foto->embarques),
            ],
        ], 201);
    }

    public function show(ProgramaSemanal $programa, PlanAcordado $planes): JsonResponse
    {
        $this->exigirAlguno([['tablero-semanal', 'view'], ['avance-cosecha', 'view'], ['avance-embarques', 'view']]);

        $foto = $planes->vigente($programa->id);

        return response()->json([
            'success' => true,
            'data' => $foto === null ? null : $this->cabecera($foto) + [
                'cortes' => $foto->cortes,
                'embarques' => $foto->embarques,
            ],
        ]);
    }

    /** @return array{id: int, por: ?array{id: int, nombre: string}, en: ?string} */
    private function cabecera(ProgramaPlanAcordado $foto): array
    {
        return [
            'id' => $foto->id,
            'por' => $foto->acordadoPor ? ['id' => (int) $foto->acordadoPor->id, 'nombre' => (string) $foto->acordadoPor->name] : null,
            'en' => $foto->created_at?->toIso8601String(),
        ];
    }
}
