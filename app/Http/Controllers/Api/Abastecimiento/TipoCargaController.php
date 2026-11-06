<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\TipoCarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de tipos de carga (submódulo `tipos-carga`): nombre único por empresa
 * sin distinguir mayúsculas; un alta con el nombre de una fila borrada la restaura.
 */
class TipoCargaController extends AbastecimientoController
{
    private const SUBMODULO = 'tipos-carga';

    private const MENSAJE_DUPLICADO = 'Ya existe un tipo de carga con ese nombre.';

    private const MENSAJE_BORRADO = 'Ese nombre pertenece a un registro eliminado: créalo desde el alta para recuperarlo o usa otro nombre.';

    private const MENSAJE_EN_USO = 'Ya se usó en un convenio: desactívalo en lugar de borrarlo.';

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');

        $tipos = TipoCarga::deEmpresa($empresa->id)
            ->when($request->boolean('activos'), fn ($q) => $q->where('is_active', true))
            ->orderBy('nombre')
            ->get();

        $usados = ConvenioPrecio::whereHas('convenio', fn ($c) => $c->where('enterprise_id', $empresa->id))
            ->distinct()->pluck('tipo_carga_id')
            ->merge(Recepcion::where('enterprise_id', $empresa->id)->distinct()->pluck('tipo_carga_id'))
            ->flip();

        return response()->json([
            'success' => true,
            'data' => $tipos->map(fn (TipoCarga $t) => $this->presentar($t, $usados->has($t->id)))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $request->validate([
            'nombre' => 'required|string|max:100',
            'peso_kg' => 'nullable|numeric|min:0|max:9999999.999',
        ]);

        $tipo = DB::transaction(function () use ($empresa, $datos) {
            $existente = TipoCarga::withTrashed()
                ->where('enterprise_id', $empresa->id)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($datos['nombre'])])
                ->first();

            if ($existente && ! $existente->trashed()) {
                throw ValidationException::withMessages(['nombre' => self::MENSAJE_DUPLICADO]);
            }

            $valores = [
                'nombre' => $datos['nombre'],
                'peso_kg' => $datos['peso_kg'] ?? null,
                'is_active' => true,
            ];

            if ($existente) {
                $existente->restore();
                $existente->update($valores);

                return $existente;
            }

            return TipoCarga::create($valores + ['enterprise_id' => $empresa->id]);
        });

        $data = $this->presentar($tipo->refresh(), false);
        $this->difundir('tipo_carga', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Tipo de carga creado.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $tipo = TipoCarga::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $request->validate([
            'nombre' => 'required|string|max:100',
            'peso_kg' => 'nullable|numeric|min:0|max:9999999.999',
            'is_active' => 'sometimes|boolean',
        ]);

        $otro = TipoCarga::withTrashed()
            ->where('enterprise_id', $empresa->id)
            ->where('id', '!=', $tipo->id)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($datos['nombre'])])
            ->first();
        if ($otro) {
            throw ValidationException::withMessages([
                'nombre' => $otro->trashed() ? self::MENSAJE_BORRADO : self::MENSAJE_DUPLICADO,
            ]);
        }

        // Sin la clave el peso se conserva; con null se borra.
        if (! $request->has('peso_kg')) {
            unset($datos['peso_kg']);
        }
        $tipo->update($datos);

        $data = $this->presentar($tipo->refresh(), $this->enUso($tipo));
        $this->difundir('tipo_carga', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Tipo de carga actualizado.', 'data' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $tipo = TipoCarga::deEmpresa($empresa->id)->findOrFail($id);

        if ($this->enUso($tipo)) {
            throw ValidationException::withMessages(['id' => self::MENSAJE_EN_USO]);
        }

        $tipo->delete();
        $this->difundir('tipo_carga', 'deleted', ['id' => $tipo->id], $empresa);

        return response()->json(['success' => true, 'message' => 'Tipo de carga eliminado.', 'data' => ['id' => $tipo->id]]);
    }

    private function enUso(TipoCarga $tipo): bool
    {
        return ConvenioPrecio::where('tipo_carga_id', $tipo->id)->whereHas('convenio')->exists()
            || Recepcion::where('tipo_carga_id', $tipo->id)->exists();
    }

    private function presentar(TipoCarga $tipo, bool $enUso): array
    {
        return [
            'id' => $tipo->id,
            'nombre' => $tipo->nombre,
            'peso_kg' => $tipo->peso_kg,
            'is_active' => (bool) $tipo->is_active,
            'en_uso' => $enUso,
        ];
    }
}
