<?php

namespace App\Http\Controllers\Api\SplendidFarms\Administration;

use App\Http\Controllers\Controller;
use App\Services\Reportes\RezagaRecepcionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes > Empaque > Recepciones vs. Embarques: Excel de rezaga por folio
 * de recepción (ver RezagaRecepcionesService).
 */
class ReporteRezagaRecepcionesController extends Controller
{
    public function __construct(private readonly RezagaRecepcionesService $servicio) {}

    /**
     * Empaques (plantas) con recepciones en la temporada, para el selector.
     */
    public function empaques(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|integer|exists:temporadas,id',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->servicio->empaquesDisponibles((int) $validated['temporada_id']),
        ]);
    }

    public function descargar(Request $request): BinaryFileResponse|JsonResponse
    {
        $validated = $request->validate([
            'temporada_id' => 'required|integer|exists:temporadas,id',
            'empaques' => 'nullable|array',
            'empaques.*' => 'integer|distinct|exists:entities,id',
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde',
        ]);

        $datos = $this->servicio->obtenerDatos(
            array_map('intval', $validated['empaques'] ?? []),
            $validated['desde'] ?? null,
            $validated['hasta'] ?? null,
            (int) $validated['temporada_id'],
        );

        if (empty($datos['empaques'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'No hay recepciones con esos filtros.',
            ], 422);
        }

        $ruta = $this->servicio->generarExcel($datos);

        return response()
            ->download($ruta, $this->servicio->nombreArchivo(), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }
}
