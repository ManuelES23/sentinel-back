<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Temporada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Temporadas de abastecimiento de un cultivo (submódulo `temporadas`). El nombre
 * no es único en el esquema, así que no hay restauración de filas borradas.
 */
class TemporadaController extends AbastecimientoController
{
    private const SUBMODULO = 'temporadas';

    private const MENSAJE_EN_USO = 'Ya se usó en un convenio: desactívalo en lugar de borrarlo.';

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');

        $temporadas = Temporada::deEmpresa($empresa->id)
            ->with('cultivo:id,nombre')
            ->withCount('convenios')
            ->orderByRaw("CASE estado WHEN 'activa' THEN 0 WHEN 'planeada' THEN 1 ELSE 2 END")
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $temporadas->map(fn (Temporada $t) => $this->presentar($t))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa->id);

        $temporada = Temporada::create($datos + ['enterprise_id' => $empresa->id]);

        $data = $this->presentar($temporada->refresh()->load('cultivo:id,nombre')->loadCount('convenios'));
        $this->difundir('temporada', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Temporada creada.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $temporada = Temporada::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $this->validar($request, $empresa->id);

        // Los convenios de la temporada llevan su mismo cultivo: no se puede cambiar.
        if ((int) $datos['cultivo_id'] !== (int) $temporada->cultivo_id && $temporada->convenios()->exists()) {
            throw ValidationException::withMessages([
                'cultivo_id' => 'La temporada ya tiene convenios: no se puede cambiar su cultivo.',
            ]);
        }

        $temporada->update($datos);

        $data = $this->presentar($temporada->refresh()->load('cultivo:id,nombre')->loadCount('convenios'));
        $this->difundir('temporada', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Temporada actualizada.', 'data' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $temporada = Temporada::deEmpresa($empresa->id)->findOrFail($id);

        if ($temporada->convenios()->exists()) {
            throw ValidationException::withMessages(['id' => self::MENSAJE_EN_USO]);
        }

        $temporada->delete();
        $this->difundir('temporada', 'deleted', ['id' => $temporada->id], $empresa);

        return response()->json(['success' => true, 'message' => 'Temporada eliminada.', 'data' => ['id' => $temporada->id]]);
    }

    private function validar(Request $request, int $empresaId): array
    {
        return $request->validate([
            'cultivo_id' => [
                'required', 'integer',
                Rule::exists('abs_cultivos', 'id')->where('enterprise_id', $empresaId)->whereNull('deleted_at'),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'estado' => ['required', Rule::in(Temporada::ESTADOS)],
        ]);
    }

    private function presentar(Temporada $t): array
    {
        $convenios = (int) ($t->convenios_count ?? 0);

        return [
            'id' => $t->id,
            'cultivo_id' => $t->cultivo_id,
            'cultivo' => $t->cultivo ? ['id' => $t->cultivo->id, 'nombre' => $t->cultivo->nombre] : null,
            'nombre' => $t->nombre,
            'fecha_inicio' => $t->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $t->fecha_fin?->format('Y-m-d'),
            'estado' => $t->estado,
            'convenios_count' => $convenios,
            'en_uso' => $convenios > 0,
        ];
    }
}
