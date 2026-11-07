<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Temporada;
use App\Services\Abastecimiento\ExcelTablero;
use App\Services\Abastecimiento\Semanas;
use App\Services\Abastecimiento\TableroAbastecimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Tablero de Abastecimiento de una temporada (submódulo `tablero`). Solo lectura. */
class TableroController extends AbastecimientoController
{
    private const SUBMODULO = 'tablero';

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $this->filtros($request, $empresa->id);

        $data = TableroAbastecimiento::armar($empresa, (int) $filtros['temporada_id'], $filtros['moneda'] ?? null);

        return response()->json(['success' => true, 'message' => 'Tablero de abastecimiento.', 'data' => $data]);
    }

    /**
     * Descarga el tablero en Excel (mismas cifras que `index`, en la moneda efectiva). Usa el permiso `tablero.view`;
     * la columna «Por pagar» solo sale con `liquidaciones.view`.
     */
    public function exportar(Request $request, ExcelTablero $excel): BinaryFileResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['required', 'integer'],
            'moneda' => ['nullable', Rule::in(Convenio::MONEDAS)],
        ]);
        // Una temporada que no es de la empresa responde 404 (el front no la ofrece: no hay nada que corregir en el formulario).
        $temporada = Temporada::deEmpresa($empresa->id)->findOrFail((int) $filtros['temporada_id']);

        $data = TableroAbastecimiento::armar($empresa, $temporada->id, $filtros['moneda'] ?? null);
        $verDinero = $this->ctx->puede($request->user(), $empresa, 'liquidaciones', 'view');
        $ruta = $excel->generar($empresa, $data, $verDinero, $temporada->nombre);

        $nombre = sprintf('Abastecimiento_Tablero_%s_%s.xlsx', $this->nombreSeguro($temporada->nombre, (string) $temporada->id), Semanas::hoy()->toDateString());

        return response()
            ->download($ruta, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend(true);
    }

    /** @return array{temporada_id: int|string, moneda?: string|null} */
    private function filtros(Request $request, int $empresaId): array
    {
        return $request->validate([
            'temporada_id' => ['required', 'integer', Rule::exists('abs_temporadas', 'id')->where('enterprise_id', $empresaId)->whereNull('deleted_at')],
            'moneda' => ['nullable', Rule::in(Convenio::MONEDAS)],
        ]);
    }

    /** Parte del nombre del archivo: solo letras, números, guion y guion bajo (sin acentos ni separadores de ruta). */
    private function nombreSeguro(string $texto, string $respaldo): string
    {
        $limpio = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::ascii($texto)), '_-');

        return $limpio === '' ? $respaldo : Str::limit($limpio, 40, '');
    }
}
