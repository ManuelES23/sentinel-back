<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\ValidaLunes;
use App\Http\Controllers\Controller;
use App\Services\Planeacion\AvanceCosecha;
use App\Services\Planeacion\CatalogoPlaneacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Avance de cosecha (solo lectura): el plan de corte de la semana contra los
 * cierres de cosecha, con el atraso y lo que toca hoy. No crea el programa de
 * la semana.
 *
 * El catálogo se inyecta por método y no por constructor: guarda cálculos en
 * memoria y cada petición necesita su propia instancia.
 */
class AvanceCosechaController extends Controller
{
    use PermisoPlaneacion;
    use ValidaLunes;

    public function show(Request $request, CatalogoPlaneacion $catalogo): JsonResponse
    {
        $this->exigirPermiso('avance-cosecha', 'view');

        $lunes = $this->lunesValidado($request);
        $datos = $request->validate([
            'temporada_id' => ['nullable', 'integer', 'exists:temporadas,id'],
            'cultivo_id' => ['nullable', 'integer', 'exists:cultivos,id'],
        ]);

        $avance = new AvanceCosecha($catalogo);

        return response()->json([
            'success' => true,
            'data' => $avance->de(
                $lunes,
                isset($datos['temporada_id']) ? (int) $datos['temporada_id'] : null,
                isset($datos['cultivo_id']) ? (int) $datos['cultivo_id'] : null,
                Carbon::now('America/Mazatlan'),
            ),
        ]);
    }
}
