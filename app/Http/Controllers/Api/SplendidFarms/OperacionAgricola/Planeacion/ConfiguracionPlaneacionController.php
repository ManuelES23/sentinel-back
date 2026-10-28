<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ConfiguracionPlaneacion;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\RecetaCombinacion;
use App\Models\Planeacion\RendimientoVariedad;
use App\Models\Planeacion\TipoEmpaque;
use App\Models\Recipe;
use App\Models\Temporada;
use App\Models\Variedad;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configuración del Programa semanal: catálogos (bodegas, presentaciones y
 * tipos de empaque), grupos de balance, receta por combinación, rangos de
 * rendimiento y cajas por carga de referencia.
 *
 * Las escrituras de configuración no se anotan en el historial del programa
 * (programa_cambios) ni emiten el evento de tiempo real: no son datos de una
 * semana.
 */
class ConfiguracionPlaneacionController extends Controller
{
    use PermisoProgramaSemanal;

    private const MENSAJE_EN_USO = 'Está en uso; desactívalo';

    /**
     * Catálogos editables por la ruta {catalogo}: modelo, nombre en singular
     * para los mensajes y tablas/columnas que lo citan (si alguna lo cita, no
     * se puede borrar).
     */
    private const CATALOGOS = [
        'bodegas' => [
            'modelo' => BodegaDestino::class,
            'usos' => [['programa_necesidades', 'bodega_id'], ['programa_viajes', 'bodega_id']],
        ],
        'presentaciones' => [
            'modelo' => Presentacion::class,
            'usos' => [['programa_necesidades', 'presentacion_id'], ['plan_recetas_combinacion', 'presentacion_id']],
        ],
        'tipos-empaque' => [
            'modelo' => TipoEmpaque::class,
            'usos' => [['programa_necesidades', 'tipo_empaque_id'], ['plan_recetas_combinacion', 'tipo_empaque_id']],
        ],
    ];

    /** Toda la configuración en una sola lectura. */
    public function show(): JsonResponse
    {
        $this->exigirPermiso('view');

        return response()->json([
            'success' => true,
            'data' => [
                'bodegas' => BodegaDestino::orderBy('orden')->orderBy('id')->get()
                    ->map(fn ($m) => $this->catalogo($m))->all(),
                'presentaciones' => Presentacion::orderBy('cultivo_id')->orderBy('orden')->orderBy('id')->get()
                    ->map(fn ($m) => $this->catalogo($m) + ['cultivo_id' => $m->cultivo_id])->all(),
                'tipos_empaque' => TipoEmpaque::orderBy('orden')->orderBy('id')->get()
                    ->map(fn ($m) => $this->catalogo($m))->all(),
                'grupos' => $this->gruposDe(null),
                'recetas_combinacion' => $this->filasRecetas(),
                'recetas_empaque' => Recipe::where('recipe_type', 'empaque')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])
                    ->all(),
                'rendimientos' => $this->rendimientos(),
                'cajas_por_carga' => (int) ConfiguracionPlaneacion::valor('cajas_por_carga', 1040),
            ],
        ]);
    }

    public function store(Request $request, string $catalogo): JsonResponse
    {
        $this->exigirPermiso('configurar');
        $modelo = $this->modeloDe($catalogo);

        $datos = $request->validate($this->reglas($catalogo, true));
        $datos['nombre'] = trim($datos['nombre']);
        $datos['orden'] ??= ((int) $modelo::max('orden')) + 1;
        $datos['is_active'] ??= true;
        $this->exigirNombreLibre($catalogo, $datos['nombre'], $datos['cultivo_id'] ?? null, null);

        $fila = $modelo::create($datos);

        return response()->json([
            'success' => true,
            'message' => 'Elemento creado.',
            'data' => $this->formatear($catalogo, $fila),
        ], 201);
    }

    public function update(Request $request, string $catalogo, int $id): JsonResponse
    {
        $this->exigirPermiso('configurar');
        $fila = $this->modeloDe($catalogo)::findOrFail($id);

        $datos = $request->validate($this->reglas($catalogo, false));
        if (isset($datos['nombre'])) {
            $datos['nombre'] = trim($datos['nombre']);
        }
        $this->exigirNombreLibre(
            $catalogo,
            $datos['nombre'] ?? $fila->nombre,
            $datos['cultivo_id'] ?? $fila->cultivo_id ?? null,
            $fila->id,
        );

        $fila->update($datos);

        return response()->json([
            'success' => true,
            'message' => 'Elemento actualizado.',
            'data' => $this->formatear($catalogo, $fila->fresh()),
        ]);
    }

    public function destroy(string $catalogo, int $id): JsonResponse
    {
        $this->exigirPermiso('configurar');
        $fila = $this->modeloDe($catalogo)::findOrFail($id);

        foreach (self::CATALOGOS[$catalogo]['usos'] as [$tabla, $columna]) {
            if (DB::table($tabla)->where($columna, $fila->id)->exists()) {
                return response()->json(['success' => false, 'message' => self::MENSAJE_EN_USO], 422);
            }
        }

        $fila->delete();

        return response()->json(['success' => true, 'message' => 'Elemento eliminado.']);
    }

    /** Reemplaza los grupos de balance de un cultivo (conserva los ids que lleguen). */
    public function grupos(Request $request): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate([
            'cultivo_id' => ['required', 'integer', Rule::exists('cultivos', 'id')],
            'grupos' => ['present', 'array'],
            'grupos.*.id' => ['nullable', 'integer'],
            'grupos.*.nombre' => ['required', 'string', 'max:255'],
            'grupos.*.variedad_ids' => ['required', 'array', 'min:1'],
            'grupos.*.variedad_ids.*' => ['integer'],
        ]);
        $cultivoId = (int) $datos['cultivo_id'];
        $grupos = $datos['grupos'];

        // Una variedad va en un solo grupo (también lo exige el índice único de la base).
        $todas = [];
        foreach ($grupos as $g) {
            foreach ($g['variedad_ids'] as $variedadId) {
                $todas[] = (int) $variedadId;
            }
        }
        if (count($todas) !== count(array_unique($todas))) {
            throw ValidationException::withMessages(['grupos' => 'Una variedad no puede estar en dos grupos.']);
        }
        if (Variedad::whereIn('id', $todas)->where('cultivo_id', $cultivoId)->count() !== count($todas)) {
            throw ValidationException::withMessages(['grupos' => 'Hay variedades que no son de este cultivo.']);
        }

        $idsEnviados = array_values(array_filter(array_map(fn ($g) => $g['id'] ?? null, $grupos)));
        if (count($idsEnviados) !== count(array_unique($idsEnviados))) {
            throw ValidationException::withMessages(['grupos' => 'Un grupo viene repetido.']);
        }
        if (GrupoBalance::where('cultivo_id', $cultivoId)->whereIn('id', $idsEnviados)->count() !== count($idsEnviados)) {
            throw ValidationException::withMessages(['grupos' => 'Hay grupos que no son de este cultivo.']);
        }

        DB::transaction(function () use ($cultivoId, $grupos, $idsEnviados) {
            $actuales = GrupoBalance::where('cultivo_id', $cultivoId)->pluck('id')->all();

            // Se vacían todas las membresías primero para poder mover variedades entre grupos.
            DB::table('plan_grupo_variedad')->whereIn('grupo_id', $actuales)->delete();
            GrupoBalance::where('cultivo_id', $cultivoId)->whereNotIn('id', $idsEnviados)->delete();

            foreach (array_values($grupos) as $orden => $g) {
                $grupo = ! empty($g['id'])
                    ? tap(GrupoBalance::findOrFail($g['id']))->update(['nombre' => trim($g['nombre']), 'orden' => $orden])
                    : GrupoBalance::create(['cultivo_id' => $cultivoId, 'nombre' => trim($g['nombre']), 'orden' => $orden]);

                $grupo->variedades()->attach(array_map('intval', $g['variedad_ids']));
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Grupos guardados.',
            'data' => $this->gruposDe($cultivoId),
        ]);
    }

    /** Reemplaza todas las recetas por combinación. */
    public function guardarRecetasCombinacion(Request $request): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate([
            'filas' => ['present', 'array'],
            'filas.*.presentacion_id' => ['required', 'integer', Rule::exists('plan_presentaciones', 'id')],
            'filas.*.tipo_empaque_id' => ['required', 'integer', Rule::exists('plan_tipos_empaque', 'id')],
            'filas.*.variedad_id' => ['nullable', 'integer', Rule::exists('variedades', 'id')],
            'filas.*.recipe_id' => [
                'required', 'integer',
                Rule::exists('recipes', 'id')->where('recipe_type', 'empaque')->whereNull('deleted_at'),
            ],
        ]);

        // El índice único de la base no frena los duplicados con variedad nula.
        $vistas = [];
        foreach ($datos['filas'] as $i => $fila) {
            $llave = $fila['presentacion_id'].'-'.$fila['tipo_empaque_id'].'-'.($fila['variedad_id'] ?? '*');
            if (isset($vistas[$llave])) {
                throw ValidationException::withMessages([
                    "filas.$i" => 'Esa combinación de presentación, empaque y variedad ya tiene receta.',
                ]);
            }
            $vistas[$llave] = true;
        }

        DB::transaction(function () use ($datos) {
            RecetaCombinacion::query()->delete();
            foreach ($datos['filas'] as $fila) {
                RecetaCombinacion::create([
                    'presentacion_id' => $fila['presentacion_id'],
                    'tipo_empaque_id' => $fila['tipo_empaque_id'],
                    'variedad_id' => $fila['variedad_id'] ?? null,
                    'recipe_id' => $fila['recipe_id'],
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Recetas guardadas.',
            'data' => $this->filasRecetas(),
        ]);
    }

    /** Guarda (crea o actualiza) los rangos de rendimiento de las variedades de una temporada. */
    public function guardarRendimientos(Request $request): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate([
            'temporada_id' => ['required', 'integer', Rule::exists('temporadas', 'id')],
            'filas' => ['present', 'array'],
            'filas.*.variedad_id' => ['required', 'integer', 'distinct', Rule::exists('variedades', 'id')],
            'filas.*.minimo' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'filas.*.maximo' => ['required', 'numeric', 'max:999999.99'],
        ]);

        foreach ($datos['filas'] as $i => $fila) {
            if ((float) $fila['minimo'] > (float) $fila['maximo']) {
                throw ValidationException::withMessages([
                    "filas.$i.maximo" => 'El máximo no puede ser menor que el mínimo.',
                ]);
            }
        }

        $temporada = Temporada::findOrFail($datos['temporada_id']);
        $ids = array_map(fn ($f) => (int) $f['variedad_id'], $datos['filas']);
        if (Variedad::whereIn('id', $ids)->where('cultivo_id', $temporada->cultivo_id)->count() !== count($ids)) {
            throw ValidationException::withMessages(['filas' => 'Hay variedades que no son del cultivo de la temporada.']);
        }

        DB::transaction(function () use ($temporada, $datos) {
            foreach ($datos['filas'] as $fila) {
                RendimientoVariedad::updateOrCreate(
                    ['temporada_id' => $temporada->id, 'variedad_id' => $fila['variedad_id']],
                    ['minimo' => $fila['minimo'], 'maximo' => $fila['maximo']],
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Rendimientos guardados.',
            'data' => $this->rendimientos($temporada->id),
        ]);
    }

    /** Copia los rangos de una temporada a otra (los de la misma variedad se sobrescriben). */
    public function copiarRendimientos(Request $request): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate([
            'desde_temporada_id' => ['required', 'integer', Rule::exists('temporadas', 'id')],
            'hacia_temporada_id' => ['required', 'integer', 'different:desde_temporada_id', Rule::exists('temporadas', 'id')],
        ]);

        $destino = Temporada::findOrFail($datos['hacia_temporada_id']);
        $variedadesDestino = Variedad::where('cultivo_id', $destino->cultivo_id)->pluck('id');

        DB::transaction(function () use ($datos, $destino, $variedadesDestino) {
            $origen = RendimientoVariedad::where('temporada_id', $datos['desde_temporada_id'])
                ->whereIn('variedad_id', $variedadesDestino)
                ->get();

            foreach ($origen as $fila) {
                RendimientoVariedad::updateOrCreate(
                    ['temporada_id' => $destino->id, 'variedad_id' => $fila->variedad_id],
                    ['minimo' => $fila->minimo, 'maximo' => $fila->maximo],
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Rendimientos copiados.',
            'data' => $this->rendimientos($destino->id),
        ]);
    }

    public function cajasPorCarga(Request $request): JsonResponse
    {
        $this->exigirPermiso('configurar');

        $datos = $request->validate(['valor' => ['required', 'integer', 'min:1', 'max:5000']]);

        ConfiguracionPlaneacion::updateOrCreate(
            ['clave' => 'cajas_por_carga'],
            ['valor' => (string) (int) $datos['valor']],
        );

        return response()->json([
            'success' => true,
            'message' => 'Cajas por carga guardadas.',
            'data' => ['cajas_por_carga' => (int) $datos['valor']],
        ]);
    }

    // ── Apoyos ──────────────────────────────────────────────────

    /** @return class-string<Model> */
    private function modeloDe(string $catalogo): string
    {
        abort_unless(isset(self::CATALOGOS[$catalogo]), 404);

        return self::CATALOGOS[$catalogo]['modelo'];
    }

    private function reglas(string $catalogo, bool $creando): array
    {
        $obligatorio = $creando ? 'required' : 'sometimes';
        $reglas = [
            'nombre' => [$obligatorio, 'string', 'max:255'],
            'orden' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($catalogo === 'presentaciones') {
            $reglas['cultivo_id'] = [$obligatorio, 'integer', Rule::exists('cultivos', 'id')];
        }

        return $reglas;
    }

    /** El nombre es único en el catálogo (en presentaciones, dentro del cultivo). */
    private function exigirNombreLibre(string $catalogo, string $nombre, ?int $cultivoId, ?int $ignorarId): void
    {
        $existe = $this->modeloDe($catalogo)::query()
            ->where('nombre', $nombre)
            ->when($catalogo === 'presentaciones', fn ($q) => $q->where('cultivo_id', $cultivoId))
            ->when($ignorarId, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['nombre' => 'Ya existe un elemento con ese nombre.']);
        }
    }

    private function formatear(string $catalogo, Model $fila): array
    {
        return $this->catalogo($fila) + ($catalogo === 'presentaciones' ? ['cultivo_id' => $fila->cultivo_id] : []);
    }

    private function catalogo(Model $m): array
    {
        return ['id' => $m->id, 'nombre' => $m->nombre, 'orden' => $m->orden, 'is_active' => $m->is_active];
    }

    /** Grupos de balance (de un cultivo o de todos) con los ids de sus variedades. */
    private function gruposDe(?int $cultivoId): array
    {
        return GrupoBalance::with('variedades:id')
            ->when($cultivoId, fn ($q) => $q->where('cultivo_id', $cultivoId))
            ->orderBy('cultivo_id')->orderBy('orden')->orderBy('id')
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'cultivo_id' => $g->cultivo_id,
                'nombre' => $g->nombre,
                'orden' => $g->orden,
                'variedad_ids' => $g->variedades->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            ])->all();
    }

    /** Recetas por combinación con el nombre de la receta. */
    private function filasRecetas(): array
    {
        return RecetaCombinacion::with('receta:id,name')->orderBy('id')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'presentacion_id' => $c->presentacion_id,
                'tipo_empaque_id' => $c->tipo_empaque_id,
                'variedad_id' => $c->variedad_id,
                'recipe_id' => $c->recipe_id,
                'recipe_name' => $c->receta?->name,
            ])->all();
    }

    private function rendimientos(?int $temporadaId = null): array
    {
        return RendimientoVariedad::query()
            ->when($temporadaId, fn ($q) => $q->where('temporada_id', $temporadaId))
            ->orderBy('temporada_id')->orderBy('variedad_id')
            ->get()
            ->map(fn ($r) => [
                'temporada_id' => $r->temporada_id,
                'variedad_id' => $r->variedad_id,
                'minimo' => (float) $r->minimo,
                'maximo' => (float) $r->maximo,
            ])->all();
    }
}
