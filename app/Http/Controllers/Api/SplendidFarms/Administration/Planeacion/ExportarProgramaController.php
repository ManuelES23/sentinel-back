<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\Administration\Planeacion\Concerns\PermisoPlaneacion;
use App\Http\Controllers\Controller;
use App\Models\Cultivo;
use App\Services\Planeacion\ExportadorProgramaExcel;
use App\Services\Planeacion\ProgramaSemanalService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exportar la semana del Tablero semanal a Excel (solo lectura). Las cifras son
 * las de `GET planeacion/programa`: el exportador no calcula nada.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y cada petición necesita su propia instancia.
 */
class ExportarProgramaController extends Controller
{
    use PermisoPlaneacion;

    public function exportar(Request $request, ProgramaSemanalService $servicio, ExportadorProgramaExcel $exportador): BinaryFileResponse
    {
        $this->exigirPermiso('tablero-semanal', 'view');

        $datos = $request->validate([
            'lunes' => ['required', 'date_format:Y-m-d'],
            'cultivo_id' => ['nullable', 'integer', 'exists:cultivos,id'],
        ]);

        // Igual que el Tablero: abrir una semana nueva crea su programa; 422 si no es lunes.
        $programa = $servicio->programaDe($datos['lunes']);
        $semana = $servicio->semana($programa, []);

        $cultivoId = isset($datos['cultivo_id']) ? (int) $datos['cultivo_id'] : $exportador->cultivoPorDefecto($semana);
        $cultivoNombre = $cultivoId !== null ? Cultivo::whereKey($cultivoId)->value('nombre') : null;

        $ruta = $exportador->generar($semana, $cultivoId, $cultivoNombre);

        return response()
            ->download($ruta, $exportador->nombreArchivo($semana), ['Content-Type' => ExportadorProgramaExcel::TIPO_MIME])
            ->deleteFileAfterSend();
    }
}
