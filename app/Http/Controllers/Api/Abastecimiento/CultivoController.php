<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\Variedad;
use App\Models\CRM\CrmProductoCalibre;
use App\Services\Abastecimiento\EnlaceProductosCrm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de cultivos y sus variedades (submódulo `variedades`). Los nombres
 * son únicos por empresa (cultivos) y por cultivo (variedades) sin distinguir
 * mayúsculas; los índices únicos incluyen filas borradas, por eso un alta con el
 * nombre de una fila borrada la restaura en lugar de insertar.
 */
class CultivoController extends AbastecimientoController
{
    private const SUBMODULO = 'variedades';

    private const MENSAJE_EN_USO = 'Ya se usó en un convenio: desactívalo en lugar de borrarlo.';

    private const MENSAJE_BORRADO = 'Ese nombre pertenece a un registro eliminado: créalo desde el alta para recuperarlo o usa otro nombre.';

    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $soloActivos = $request->boolean('activos');

        $cultivos = Cultivo::deEmpresa($empresa->id)
            ->when($soloActivos, fn ($q) => $q->where('is_active', true))
            ->with(['variedades' => function ($q) use ($soloActivos) {
                $q->when($soloActivos, fn ($v) => $v->where('is_active', true))
                    ->orderBy('nombre')
                    ->with(['producto:id,nombre,commodity', 'calibres' => function ($c) use ($soloActivos) {
                        $c->when($soloActivos, fn ($k) => $k->where('activo', true))
                            ->orderBy('orden')->orderBy('nombre');
                    }]);
            }])
            ->orderBy('nombre')
            ->get();

        $cultivosUsados = Convenio::where('enterprise_id', $empresa->id)->distinct()->pluck('cultivo_id')
            ->merge(Temporada::where('enterprise_id', $empresa->id)->distinct()->pluck('cultivo_id'))
            ->flip();
        $variedadesUsadas = Convenio::where('enterprise_id', $empresa->id)->distinct()->pluck('variedad_id')->flip();

        return response()->json([
            'success' => true,
            'data' => $cultivos->map(fn (Cultivo $c) => $this->presentar(
                $c,
                $cultivosUsados->has($c->id),
                fn (Variedad $v) => $variedadesUsadas->has($v->id),
            ))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $request->validate([
            'nombre' => 'required|string|max:100',
            'variedades' => 'sometimes|array|max:100',
            'variedades.*' => 'required|string|max:100',
        ]);

        $cultivo = DB::transaction(function () use ($empresa, $datos) {
            $cultivo = $this->crearORestaurar(
                Cultivo::class,
                ['enterprise_id' => $empresa->id],
                $datos['nombre'],
                'Ya existe un cultivo con ese nombre.',
            );
            foreach ($this->nombresUnicos($datos['variedades'] ?? []) as $nombre) {
                $this->crearORestaurar(
                    Variedad::class,
                    ['cultivo_id' => $cultivo->id],
                    $nombre,
                    'Ya existe una variedad con ese nombre en el cultivo.',
                );
            }

            return $cultivo->load(['variedades' => fn ($q) => $q->orderBy('nombre')]);
        });

        $data = $this->presentar($cultivo, false, fn () => false);
        $this->difundir('cultivo', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Cultivo creado.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $cultivo = Cultivo::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $request->validate([
            'nombre' => 'required|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $this->exigirNombreLibre(
            Cultivo::class, ['enterprise_id' => $empresa->id], $datos['nombre'], $cultivo->id,
            'Ya existe un cultivo con ese nombre.',
        );
        $cultivo->update($datos);

        $cultivo->load(['variedades' => fn ($q) => $q->orderBy('nombre')]);
        $data = $this->presentar($cultivo, $this->cultivoEnUso($cultivo), fn (Variedad $v) => $this->variedadEnUso($v));
        $this->difundir('cultivo', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Cultivo actualizado.', 'data' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $cultivo = Cultivo::deEmpresa($empresa->id)->findOrFail($id);

        if ($this->cultivoEnUso($cultivo)) {
            throw ValidationException::withMessages(['id' => self::MENSAJE_EN_USO]);
        }

        DB::transaction(function () use ($cultivo) {
            // Los calibres son del catálogo de Splendid Farms: no se tocan.
            $cultivo->variedades()->get()->each->delete();
            $cultivo->delete();
        });

        $this->difundir('cultivo', 'deleted', ['id' => $cultivo->id], $empresa);

        return response()->json(['success' => true, 'message' => 'Cultivo eliminado.', 'data' => ['id' => $cultivo->id]]);
    }

    public function storeVariedad(Request $request, int $cultivoId): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $cultivo = Cultivo::deEmpresa($empresa->id)->findOrFail($cultivoId);
        $datos = $request->validate(['nombre' => 'required|string|max:100']);

        $variedad = DB::transaction(function () use ($cultivo, $datos, $empresa) {
            $variedad = $this->crearORestaurar(
                Variedad::class,
                ['cultivo_id' => $cultivo->id],
                $datos['nombre'],
                'Ya existe una variedad con ese nombre en el cultivo.',
            );
            // Sin liga todavía: se propone el producto del CRM con el nombre de la variedad.
            if (! $variedad->producto_id && ($producto = EnlaceProductosCrm::proponer($variedad, $empresa->id))) {
                $variedad->update(['producto_id' => $producto->id]);
            }

            return $variedad;
        });

        $data = $this->presentarVariedad($variedad, false);
        $this->difundir('cultivo', 'updated', ['id' => $cultivo->id, 'variedad' => $data], $empresa);

        return response()->json(['success' => true, 'message' => 'Variedad creada.', 'data' => $data], 201);
    }

    public function updateVariedad(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $variedad = Variedad::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $request->validate([
            'nombre' => 'required|string|max:100',
            'is_active' => 'sometimes|boolean',
            'producto_id' => ['sometimes', 'nullable', 'integer', Rule::exists('crm_productos', 'id')->where('empresa_id', $empresa->id)],
        ], ['producto_id.exists' => 'Ese producto del CRM no existe en esta empresa.']);

        $this->exigirNombreLibre(
            Variedad::class, ['cultivo_id' => $variedad->cultivo_id], $datos['nombre'], $variedad->id,
            'Ya existe una variedad con ese nombre en el cultivo.',
        );
        $this->exigirCambioDeLigaPermitido($variedad, $datos);
        $variedad->update($datos);

        $data = $this->presentarVariedad($variedad, $this->variedadEnUso($variedad));
        $this->difundir('cultivo', 'updated', ['id' => $variedad->cultivo_id, 'variedad' => $data], $empresa);

        return response()->json(['success' => true, 'message' => 'Variedad actualizada.', 'data' => $data]);
    }

    public function destroyVariedad(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $variedad = Variedad::deEmpresa($empresa->id)->findOrFail($id);

        if ($this->variedadEnUso($variedad)) {
            throw ValidationException::withMessages(['id' => self::MENSAJE_EN_USO]);
        }

        $variedad->delete();
        $this->difundir('cultivo', 'updated', ['id' => $variedad->cultivo_id, 'variedad_eliminada' => $variedad->id], $empresa);

        return response()->json(['success' => true, 'message' => 'Variedad eliminada.', 'data' => ['id' => $variedad->id]]);
    }

    /** GET productos-crm — productos del CRM de la empresa para elegir la liga (los calibres salen de ahí). */
    public function productosCrm(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');

        return response()->json(['success' => true, 'message' => 'OK', 'data' => EnlaceProductosCrm::opciones($empresa->id)]);
    }

    /**
     * Cambiar el producto del CRM cambia los calibres disponibles: no se permite si algún convenio de esta
     * variedad ya pactó calibres (sus precios, metas y recepciones apuntan a calibres del producto anterior).
     */
    private function exigirCambioDeLigaPermitido(Variedad $variedad, array $datos): void
    {
        if (! array_key_exists('producto_id', $datos) || (int) $datos['producto_id'] === (int) $variedad->producto_id) {
            return;
        }

        $enUso = ConvenioCalibre::whereHas('convenio', fn ($c) => $c->where('variedad_id', $variedad->id))->exists();
        if ($enUso) {
            throw ValidationException::withMessages([
                'producto_id' => 'Esta variedad ya tiene convenios por calibre: no se puede cambiar su producto del CRM.',
            ]);
        }
    }

    private function presentarCalibre(CrmProductoCalibre $calibre, bool $enUso): array
    {
        return [
            'id' => $calibre->id,
            'producto_id' => $calibre->producto_id,
            'nombre' => $calibre->nombre,
            'orden' => (int) $calibre->orden,
            'is_active' => (bool) $calibre->activo,
            'en_uso' => $enUso,
        ];
    }

    private function cultivoEnUso(Cultivo $cultivo): bool
    {
        return Convenio::where('cultivo_id', $cultivo->id)->exists()
            || Temporada::where('cultivo_id', $cultivo->id)->exists();
    }

    private function variedadEnUso(Variedad $variedad): bool
    {
        return Convenio::where('variedad_id', $variedad->id)->exists();
    }

    /** @param  callable(Variedad): bool  $variedadEnUso */
    private function presentar(Cultivo $cultivo, bool $enUso, callable $variedadEnUso): array
    {
        return [
            'id' => $cultivo->id,
            'nombre' => $cultivo->nombre,
            'is_active' => (bool) $cultivo->is_active,
            'en_uso' => $enUso,
            'variedades' => $cultivo->variedades
                ->map(fn (Variedad $v) => $this->presentarVariedad($v, (bool) $variedadEnUso($v)))
                ->values()
                ->all(),
        ];
    }

    private function presentarVariedad(Variedad $variedad, bool $enUso): array
    {
        $variedad->loadMissing(['producto:id,nombre,commodity', 'calibres' => fn ($q) => $q->orderBy('orden')->orderBy('nombre')]);
        $calibresEnUso = ConvenioCalibre::whereIn('calibre_id', $variedad->calibres->pluck('id'))
            ->distinct()->pluck('calibre_id')->flip();

        return [
            'id' => $variedad->id,
            'cultivo_id' => $variedad->cultivo_id,
            'nombre' => $variedad->nombre,
            'is_active' => (bool) $variedad->is_active,
            'en_uso' => $enUso,
            'producto_id' => $variedad->producto_id,
            'producto' => $variedad->producto ? [
                'id' => $variedad->producto->id,
                'nombre' => $variedad->producto->nombre,
                'commodity' => $variedad->producto->commodity,
            ] : null,
            'calibres' => $variedad->calibres
                ->map(fn (CrmProductoCalibre $c) => $this->presentarCalibre($c, $calibresEnUso->has($c->id)))
                ->values()
                ->all(),
        ];
    }

    /** Nombres sin repetir (sin distinguir mayúsculas), conservando el primero. */
    private function nombresUnicos(array $nombres): array
    {
        $vistos = [];
        foreach ($nombres as $nombre) {
            $vistos[mb_strtolower(trim($nombre))] ??= trim($nombre);
        }

        return array_values($vistos);
    }

    /**
     * Alta con restauración (Ruling 9): una fila viva con ese nombre → 422; una
     * borrada → se restaura, queda activa y toma el nombre nuevo; si no, se inserta.
     * `$alcance` es la columna que acota la unicidad (empresa, cultivo o variedad);
     * `$extra` son columnas que solo se fijan al insertar (p. ej. `orden`).
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelo
     */
    private function crearORestaurar(string $modelo, array $alcance, string $nombre, string $mensajeDuplicado, array $extra = [])
    {
        $existente = $modelo::withTrashed()
            ->where($alcance)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->first();

        if ($existente && ! $existente->trashed()) {
            throw ValidationException::withMessages(['nombre' => $mensajeDuplicado]);
        }

        if ($existente) {
            $existente->restore();
            $existente->update(['nombre' => $nombre, 'is_active' => true]);

            return $existente;
        }

        return $modelo::create(array_merge($alcance, $extra, ['nombre' => $nombre, 'is_active' => true]));
    }

    /** Al renombrar: ni otra fila viva ni una borrada (el índice único la cuenta) pueden tener ya el nombre. */
    private function exigirNombreLibre(string $modelo, array $alcance, string $nombre, int $propioId, string $mensajeDuplicado): void
    {
        $otro = $modelo::withTrashed()
            ->where($alcance)
            ->where('id', '!=', $propioId)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])
            ->first();

        if ($otro) {
            throw ValidationException::withMessages([
                'nombre' => $otro->trashed() ? self::MENSAJE_BORRADO : $mensajeDuplicado,
            ]);
        }
    }
}
