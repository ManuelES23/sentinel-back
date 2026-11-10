<?php

namespace App\Http\Controllers\Api\CRM;

use App\Models\CRM\CrmLinea;
use App\Models\CRM\CrmMarca;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmSublinea;
use App\Support\CRM\NombreCatalogo;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de marcas, líneas y sublíneas de los productos del CRM.
 * Lectura abierta a la empresa; las escrituras exigen catalogos.productos (crear / editar).
 */
class CatalogoLineasController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    private const CON_PRODUCTOS = 'Tiene productos: desactívala en lugar de borrarla.';

    /** GET /crm/catalogo-lineas */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $marcas = CrmMarca::where('empresa_id', $empresaId)
            ->withCount('productos')
            ->orderBy('nombre')->get();

        $lineas = CrmLinea::where('empresa_id', $empresaId)
            ->withCount('productos')
            ->with(['sublineas' => fn ($q) => $q
                ->withCount('productos')
                ->orderBy('nombre')])
            ->orderBy('nombre')->get();

        return $this->jsonSuccess([
            'marcas' => $marcas->map(fn ($m) => $this->fila($m))->values(),
            'lineas' => $lineas->map(fn ($l) => $this->fila($l) + [
                'sublineas' => $l->sublineas->map(fn ($s) => $this->fila($s))->values(),
            ])->values(),
        ]);
    }

    // ── Marcas ────────────────────────────────────────────────────────────────

    public function storeMarca(Request $request): JsonResponse
    {
        $empresaId = $this->permitir($request, 'crear');
        $nombre = $this->nombreValido($request, true);
        $this->nombreLibre(CrmMarca::where('empresa_id', $empresaId), $nombre);

        $marca = CrmMarca::create(['empresa_id' => $empresaId, 'nombre' => $nombre, 'activo' => true]);

        return $this->jsonSuccess($this->fila($marca, 0), 'Marca creada correctamente', 201);
    }

    public function updateMarca(Request $request, CrmMarca $marca): JsonResponse
    {
        $empresaId = $this->permitir($request, 'editar');
        $this->deLaEmpresa($marca->empresa_id, $empresaId, 'Marca no encontrada');

        return $this->actualizar($request, $marca, CrmMarca::where('empresa_id', $empresaId), 'Marca actualizada correctamente');
    }

    public function destroyMarca(Request $request, CrmMarca $marca): JsonResponse
    {
        $empresaId = $this->permitir($request, 'editar');
        $this->deLaEmpresa($marca->empresa_id, $empresaId, 'Marca no encontrada');

        return $this->borrar($marca, CrmProducto::where('empresa_id', $empresaId)->where('marca_id', $marca->id), 'Marca eliminada correctamente');
    }

    // ── Líneas ────────────────────────────────────────────────────────────────

    public function storeLinea(Request $request): JsonResponse
    {
        $empresaId = $this->permitir($request, 'crear');
        $nombre = $this->nombreValido($request, true);
        $this->nombreLibre(CrmLinea::where('empresa_id', $empresaId), $nombre);

        $linea = CrmLinea::create(['empresa_id' => $empresaId, 'nombre' => $nombre, 'activo' => true]);

        return $this->jsonSuccess($this->fila($linea, 0) + ['sublineas' => []], 'Línea creada correctamente', 201);
    }

    public function updateLinea(Request $request, CrmLinea $linea): JsonResponse
    {
        $empresaId = $this->permitir($request, 'editar');
        $this->deLaEmpresa($linea->empresa_id, $empresaId, 'Línea no encontrada');

        return $this->actualizar($request, $linea, CrmLinea::where('empresa_id', $empresaId), 'Línea actualizada correctamente');
    }

    public function destroyLinea(Request $request, CrmLinea $linea): JsonResponse
    {
        $empresaId = $this->permitir($request, 'editar');
        $this->deLaEmpresa($linea->empresa_id, $empresaId, 'Línea no encontrada');

        $productos = CrmProducto::where('empresa_id', $empresaId)->where(function ($q) use ($linea) {
            $q->where('linea_id', $linea->id)
                ->orWhereIn('sublinea_id', $linea->sublineas()->select('id'));
        });

        return $this->borrar($linea, $productos, 'Línea eliminada correctamente', fn () => $linea->sublineas()->delete());
    }

    // ── Sublíneas ─────────────────────────────────────────────────────────────

    public function storeSublinea(Request $request, CrmLinea $linea): JsonResponse
    {
        $empresaId = $this->permitir($request, 'crear');
        $this->deLaEmpresa($linea->empresa_id, $empresaId, 'Línea no encontrada');
        $nombre = $this->nombreValido($request, true);
        $this->nombreLibre(CrmSublinea::where('linea_id', $linea->id), $nombre);

        $sub = $linea->sublineas()->create(['nombre' => $nombre, 'activo' => true]);

        return $this->jsonSuccess($this->fila($sub, 0), 'Sublínea creada correctamente', 201);
    }

    public function updateSublinea(Request $request, CrmSublinea $sublinea): JsonResponse
    {
        $empresaId = $this->permitir($request, 'editar');
        $this->deLaEmpresa($sublinea->linea->empresa_id, $empresaId, 'Sublínea no encontrada');

        return $this->actualizar($request, $sublinea, CrmSublinea::where('linea_id', $sublinea->linea_id), 'Sublínea actualizada correctamente');
    }

    public function destroySublinea(Request $request, CrmSublinea $sublinea): JsonResponse
    {
        $empresaId = $this->permitir($request, 'editar');
        $this->deLaEmpresa($sublinea->linea->empresa_id, $empresaId, 'Sublínea no encontrada');

        return $this->borrar($sublinea, CrmProducto::where('empresa_id', $empresaId)->where('sublinea_id', $sublinea->id), 'Sublínea eliminada correctamente');
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Exige el permiso y devuelve la empresa del request. */
    private function permitir(Request $request, string $permiso): int
    {
        $empresaId = $this->getEmpresaId($request);
        $this->exigirPermisoSubmodulo(
            $empresaId, 'catalogos', 'productos', $permiso,
            $permiso === 'crear' ? 'No tienes permiso para crear en el catálogo de líneas.' : 'No tienes permiso para editar el catálogo de líneas.',
        );

        return (int) $empresaId;
    }

    private function deLaEmpresa(int $duenoId, int $empresaId, string $mensaje): void
    {
        abort_unless($duenoId === $empresaId, 404, $mensaje);
    }

    private function nombreValido(Request $request, bool $requerido): string
    {
        $dato = $request->validate([
            'nombre' => ($requerido ? 'required' : 'sometimes|required').'|string|max:150',
        ]);

        return NombreCatalogo::limpio($dato['nombre']);
    }

    /** El nombre no puede repetirse en su ámbito (por nombre_clave). */
    private function nombreLibre($consulta, string $nombre, ?int $ignorarId = null): void
    {
        $existe = $consulta->where('nombre_clave', NombreCatalogo::clave($nombre))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un registro con ese nombre.']);
        }
    }

    /** Renombra y/o activa-desactiva. */
    private function actualizar(Request $request, $modelo, $ambito, string $mensaje): JsonResponse
    {
        $request->validate([
            'nombre' => 'sometimes|required|string|max:150',
            'activo' => 'sometimes|boolean',
        ]);

        $cambios = [];
        if ($request->has('nombre')) {
            $cambios['nombre'] = NombreCatalogo::limpio($request->input('nombre'));
            $this->nombreLibre($ambito, $cambios['nombre'], $modelo->id);
        }
        if ($request->has('activo')) {
            $cambios['activo'] = $request->boolean('activo');
        }

        $modelo->update($cambios);

        return $this->jsonSuccess($this->fila($modelo), $mensaje);
    }

    /** Borra si no tiene productos. */
    private function borrar($modelo, $productos, string $mensaje, ?callable $antes = null): JsonResponse
    {
        if ($productos->exists()) {
            return response()->json(['success' => false, 'message' => self::CON_PRODUCTOS], 422);
        }

        DB::transaction(function () use ($modelo, $antes) {
            if ($antes) {
                $antes();
            }
            $modelo->delete();
        });

        return $this->jsonSuccess(null, $mensaje);
    }

    /** Forma de salida común: id, nombre, activo y conteo de productos. */
    private function fila($modelo, ?int $productos = null): array
    {
        return [
            'id'        => $modelo->id,
            'nombre'    => $modelo->nombre,
            'activo'    => (bool) $modelo->activo,
            'productos' => $productos ?? (int) ($modelo->productos_count ?? 0),
        ];
    }
}
