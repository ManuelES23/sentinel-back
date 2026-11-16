<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Variedad;
use App\Services\Abastecimiento\CalibresDeSplendidFarms;
use App\Services\Abastecimiento\ContextoAbastecimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Importa a una variedad de Abastecimiento los calibres de la variedad del mismo
 * nombre de Splendid Farms (submódulo `variedades`). Leer pide `view`; importar,
 * `create`, igual que dar de alta un calibre a mano.
 */
class CalibresSplendidFarmsController extends AbastecimientoController
{
    private const SUBMODULO = 'variedades';

    public function __construct(ContextoAbastecimiento $ctx, private CalibresDeSplendidFarms $origen)
    {
        parent::__construct($ctx);
    }

    /** GET variedades/{variedadId}/calibres/splendid-farms */
    public function disponibles(Request $request, int $variedadId): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $variedad = Variedad::deEmpresa($empresa->id)->findOrFail($variedadId);

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $this->origen->disponibles($variedad)]);
    }

    /** POST variedades/{variedadId}/calibres/importar  {nombres: string[]} */
    public function importar(Request $request, int $variedadId): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $variedad = Variedad::deEmpresa($empresa->id)->findOrFail($variedadId);
        $datos = $request->validate([
            'nombres' => 'required|array|min:1|max:200',
            'nombres.*' => 'required|string|max:60',
        ], [
            'nombres.required' => 'Elige al menos un calibre.',
            'nombres.min' => 'Elige al menos un calibre.',
        ]);

        $permitidos = collect($this->origen->nombresImportables($variedad))
            ->mapWithKeys(fn (string $n) => [mb_strtolower($n) => $n]);
        $nombres = [];
        foreach ($datos['nombres'] as $i => $nombre) {
            $canonico = $permitidos->get(mb_strtolower(trim($nombre)));
            if ($canonico === null) {
                throw ValidationException::withMessages([
                    "nombres.$i" => 'Ese calibre no está en el catálogo de Splendid Farms.',
                ]);
            }
            $nombres[mb_strtolower($canonico)] = $canonico;
        }

        $resultado = DB::transaction(fn () => $this->origen->importar($variedad, array_values($nombres)));
        $this->difundir('cultivo', 'updated', ['id' => $variedad->cultivo_id, 'variedad_id' => $variedad->id], $empresa);

        $mensaje = $resultado['creados'] === 1 ? '1 calibre importado.' : "{$resultado['creados']} calibres importados.";

        return response()->json(['success' => true, 'message' => $mensaje, 'data' => $resultado]);
    }
}
