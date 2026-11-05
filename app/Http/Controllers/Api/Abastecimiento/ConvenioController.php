<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\Variedad;
use App\Models\Enterprise;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\IndicadoresConvenio;
use App\Services\Abastecimiento\MetasConvenio;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\ReglasConvenio;
use App\Services\Abastecimiento\Semanas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Convenios de abastecimiento con sus precios por tipo de carga y vigencia,
 * su volumen pactado (total o por semana) y su estatus (submódulo `convenios`).
 */
class ConvenioController extends AbastecimientoController
{
    private const SUBMODULO = 'convenios';

    /** Transiciones permitidas del estatus; lo que no está aquí se rechaza. */
    private const TRANSICIONES = [
        'borrador' => ['activo', 'cancelado'],
        'activo' => ['suspendido', 'finalizado', 'cancelado'],
        'suspendido' => ['activo', 'finalizado', 'cancelado'],
        'finalizado' => [],
        'cancelado' => [],
    ];

    /**
     * Convenios de una temporada con sus indicadores (sin el detalle por semana).
     * Número de consultas constante: relaciones en carga ansiosa y las
     * recepciones de todos los convenios en una sola consulta agrupada.
     */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['required', 'integer', Rule::exists('abs_temporadas', 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at')],
            'status' => ['nullable', Rule::in(Convenio::STATUSES)],
            'estado' => ['nullable', Rule::in(IndicadoresConvenio::ESTADOS)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $convenios = Convenio::deEmpresa($empresa->id)
            ->where('temporada_id', $filtros['temporada_id'])
            ->when(! empty($filtros['status']), fn ($q) => $q->where('status', $filtros['status']))
            ->when(! empty($filtros['search']), function ($q) use ($filtros) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filtros['search'])).'%';
                $q->where(function ($q) use ($like) {
                    $q->whereRaw("abs_convenios.folio LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('productor', fn ($p) => $p->whereRaw("nombre LIKE ? ESCAPE '!'", [$like]))
                        ->orWhereHas('variedad', fn ($v) => $v->whereRaw("nombre LIKE ? ESCAPE '!'", [$like]));
                });
            })
            ->with(['productor:id,nombre,tipo', 'variedad:id,nombre', 'precios.tipoCarga:id,nombre', 'metas'])
            ->orderBy('fecha_inicio')->orderBy('id')
            ->get();

        $hoy = Semanas::hoy();
        $lineas = IndicadoresConvenio::lineas($convenios);

        $data = $convenios->map(function (Convenio $c) use ($hoy, $lineas) {
            $indicadores = IndicadoresConvenio::de($c, $hoy, $lineas->get($c->id, collect()));
            unset($indicadores['semanas']);

            return $this->datosBase($c) + [
                'productor' => $c->productor,
                'variedad' => $c->variedad,
                'precio_principal' => $this->precioPrincipal($c),
                'indicadores' => $indicadores,
            ];
        })->when(
            ! empty($filtros['estado']),
            fn ($datos) => $datos->filter(fn (array $d) => $d['indicadores']['estado'] === $filtros['estado']),
        )->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Ficha del convenio: precios, metas, indicadores con el detalle por semana y las 10 recepciones más recientes. */
    public function show(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $convenio = Convenio::deEmpresa($empresa->id)
            ->with(['productor:id,nombre,tipo', 'variedad:id,nombre', 'precios.tipoCarga:id,nombre', 'metas'])
            ->findOrFail($id);

        $recientes = Recepcion::where('convenio_id', $convenio->id)
            ->with('tipoCarga:id,nombre')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit(10)
            ->get();

        $data = $this->armar($convenio) + [
            'productor' => $convenio->productor,
            'variedad' => $convenio->variedad,
            'precio_principal' => $this->precioPrincipal($convenio),
            'indicadores' => IndicadoresConvenio::de($convenio),
            'recepciones_total' => Recepcion::where('convenio_id', $convenio->id)->count(),
            'recepciones' => $recientes->map(fn (Recepcion $r) => [
                'id' => $r->id,
                'folio' => $r->folio,
                'fecha' => $r->fecha->format('Y-m-d'),
                'tipo_carga_id' => $r->tipo_carga_id,
                'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
                'cantidad' => $r->cantidad,
                'kilos' => $r->kilos,
                'rezaga_kg' => $r->rezaga_kg,
                'ticket_proveedor' => $r->ticket_proveedor,
                'liquidada' => $r->estaLiquidada(),
            ])->values()->all(),
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }
    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa);
        ReglasConvenio::validarVolumen($datos);

        $convenio = DB::transaction(function () use ($request, $empresa, $datos) {
            $convenio = Convenio::create($this->columnas($datos) + [
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'CV'),
                'status' => 'borrador',
                'created_by' => $request->user()?->id,
            ]);

            $this->guardarPrecios($convenio, $datos['precios']);
            $this->guardarMetas($convenio, $datos['metas'] ?? []);

            return $convenio;
        });

        $data = $this->presentar($convenio);
        $this->difundir('convenio', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Convenio creado.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $convenio = Convenio::deEmpresa($empresa->id)->findOrFail($id);

        if (in_array($convenio->status, ['finalizado', 'cancelado'], true)) {
            throw ValidationException::withMessages(['status' => 'Un convenio finalizado o cancelado no se edita.']);
        }

        $datos = $this->validar($request, $empresa);

        // La unidad y la moneda reinterpretan las cantidades y montos ya capturados: con recepciones no cambian.
        $cambios = array_filter([
            'unidad' => $datos['unidad'] !== $convenio->unidad,
            'moneda' => $datos['moneda'] !== $convenio->moneda,
        ]);
        if ($cambios !== [] && $convenio->recepciones()->exists()) {
            throw ValidationException::withMessages(array_map(
                fn () => 'No se puede cambiar: el convenio ya tiene recepciones.',
                $cambios,
            ));
        }

        // El volumen se revisa sobre el estado final: lo enviado sobre lo guardado.
        // Sin `metas` en la petición se conservan las guardadas y se revisan contra las fechas nuevas.
        $metasEnviadas = array_key_exists('metas', $datos);
        $final = $datos + ['volumen_total' => $convenio->volumen_total];
        if (! $metasEnviadas) {
            $final['metas'] = $convenio->metas->map(fn (ConvenioMeta $m) => [
                'semana' => $m->semana->format('Y-m-d'),
                'cantidad' => $m->cantidad,
            ])->all();
        }
        ReglasConvenio::validarVolumen($final, ! $metasEnviadas);

        DB::transaction(function () use ($convenio, $datos, $metasEnviadas) {
            $convenio->update($this->columnas($datos));

            // Los precios se reemplazan por la lista enviada. Las metas se reemplazan solo si
            // vienen; en volumen total no existen renglones, así que pasar a total las borra.
            $this->guardarPrecios($convenio, $datos['precios']);
            if ($datos['modo_volumen'] === 'total') {
                $this->guardarMetas($convenio, []);
            } elseif ($metasEnviadas) {
                $this->guardarMetas($convenio, $datos['metas']);
            }
        });

        $data = $this->presentar($convenio->refresh());
        $this->difundir('convenio', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Convenio actualizado.', 'data' => $data]);
    }

    /** Cambia el estatus del convenio con la máquina de estados de TRANSICIONES. */
    public function estado(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $destino = $request->validate(['status' => ['required', Rule::in(Convenio::STATUSES)]])['status'];

        $convenio = DB::transaction(function () use ($empresa, $id, $destino) {
            $convenio = Convenio::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);

            if (! in_array($destino, self::TRANSICIONES[$convenio->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "No se puede pasar un convenio de {$convenio->status} a {$destino}.",
                ]);
            }

            if ($destino === 'activo' && $convenio->status === 'borrador') {
                if (! $convenio->precios()->exists()) {
                    throw ValidationException::withMessages(['status' => 'Agrega al menos un precio antes de activar el convenio.']);
                }
                if (MetasConvenio::pactado($convenio) <= 0) {
                    throw ValidationException::withMessages(['status' => 'Define el volumen pactado antes de activar el convenio.']);
                }
            }

            if ($destino === 'cancelado' && $convenio->recepciones()->exists()) {
                throw ValidationException::withMessages(['status' => 'Tiene recepciones: finalízalo en lugar de cancelarlo.']);
            }

            $convenio->update(['status' => $destino]);

            return $convenio;
        });

        $data = $this->presentar($convenio);
        $this->difundir('convenio', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Estatus del convenio actualizado.', 'data' => $data]);
    }

    /** Convenios no cancelados del mismo productor y variedad cuyas fechas se tocan con el rango. */
    public function encimados(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $datos = $request->validate([
            'productor_id' => ['required', 'integer'],
            'variedad_id' => ['required', 'integer'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'excluir_id' => ['nullable', 'integer'],
        ]);

        $convenios = Convenio::deEmpresa($empresa->id)
            ->where('productor_id', $datos['productor_id'])
            ->where('variedad_id', $datos['variedad_id'])
            ->where('status', '!=', 'cancelado')
            ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])
            ->when(! empty($datos['excluir_id']), fn ($q) => $q->where('id', '!=', $datos['excluir_id']))
            ->orderBy('fecha_inicio')->orderBy('id')
            ->get(['id', 'folio', 'fecha_inicio', 'fecha_fin', 'status']);

        return response()->json([
            'success' => true,
            'data' => $convenios->map(fn (Convenio $c) => [
                'id' => $c->id,
                'folio' => $c->folio,
                'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
                'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
                'status' => $c->status,
            ])->values(),
        ]);
    }

    /** Valida el cuerpo y deja `cultivo_id` (el de la temporada) y los precios normalizados. */
    private function validar(Request $request, Enterprise $empresa): array
    {
        $viva = fn (string $tabla) => Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');

        $datos = $request->validate([
            'temporada_id' => ['required', 'integer', $viva('abs_temporadas')],
            'productor_id' => ['required', 'integer', $viva('abs_productores')],
            'variedad_id' => ['required', 'integer'],
            'modalidad' => ['required', Rule::in(Convenio::MODALIDADES)],
            'unidad' => ['required', Rule::in(Convenio::UNIDADES)],
            'moneda' => ['required', Rule::in(Convenio::MONEDAS)],
            'porcentaje_rezaga' => ['required', 'numeric', 'between:0,100'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'modo_volumen' => ['required', Rule::in(Convenio::MODOS_VOLUMEN)],
            'volumen_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'metas' => ['nullable', 'array'],
            'metas.*.semana' => ['required', 'date_format:Y-m-d', 'distinct'],
            'metas.*.cantidad' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'precios' => ['required', 'array', 'min:1'],
            'precios.*.tipo_carga_id' => ['required', 'integer', $viva('abs_tipos_carga')],
            'precios.*.precio' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'precios.*.porcentaje_productor' => ['nullable', 'numeric', 'between:0,100'],
            'precios.*.vigencia_inicio' => ['required', 'date_format:Y-m-d'],
            'precios.*.es_principal' => ['nullable', 'boolean'],
            'precios.*.notas' => ['nullable', 'string', 'max:500'],
        ]);

        // El cultivo es el de la temporada y la variedad debe ser de ese cultivo.
        $temporada = Temporada::deEmpresa($empresa->id)->findOrFail($datos['temporada_id']);
        $variedadValida = Variedad::where('cultivo_id', $temporada->cultivo_id)->whereKey($datos['variedad_id'])->exists();
        if (! $variedadValida) {
            throw ValidationException::withMessages([
                'variedad_id' => 'La variedad no pertenece al cultivo de la temporada.',
            ]);
        }
        $datos['cultivo_id'] = $temporada->cultivo_id;

        ReglasConvenio::validarPrecios($datos['precios'], $datos['modalidad']);
        $datos['precios'] = ReglasConvenio::normalizarPrecios($datos['precios'], $datos['modalidad']);

        return $datos;
    }

    private function columnas(array $datos): array
    {
        return collect($datos)->only([
            'temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad', 'unidad', 'moneda',
            'porcentaje_rezaga', 'fecha_inicio', 'fecha_fin', 'modo_volumen', 'volumen_total', 'notas',
        ])->all();
    }

    /**
     * Deja exactamente la lista enviada: los renglones que no vienen se borran
     * (borrado lógico) y uno que choca con un renglón borrado del mismo tipo y
     * vigencia lo restaura en lugar de insertar (el índice único cuenta los borrados).
     */
    private function guardarPrecios(Convenio $convenio, array $filas): void
    {
        $conservados = [];

        foreach ($filas as $fila) {
            $precio = ConvenioPrecio::withTrashed()
                ->where('convenio_id', $convenio->id)
                ->where('tipo_carga_id', $fila['tipo_carga_id'])
                ->whereDate('vigencia_inicio', $fila['vigencia_inicio'])
                ->first();

            if ($precio === null) {
                $precio = ConvenioPrecio::create($fila + ['convenio_id' => $convenio->id]);
            } else {
                if ($precio->trashed()) {
                    $precio->restore();
                }
                $precio->update($fila);
            }
            $conservados[] = $precio->id;
        }

        ConvenioPrecio::where('convenio_id', $convenio->id)
            ->whereNotIn('id', $conservados)
            ->get()
            ->each->delete();
    }

    private function guardarMetas(Convenio $convenio, array $metas): void
    {
        $convenio->metas()->delete();

        foreach ($metas as $meta) {
            ConvenioMeta::create([
                'convenio_id' => $convenio->id,
                'semana' => $meta['semana'],
                'cantidad' => $meta['cantidad'],
            ]);
        }
    }

    private function presentar(Convenio $c): array
    {
        $c->load(['precios.tipoCarga:id,nombre', 'metas']);

        return $this->armar($c);
    }

    /** Columnas del convenio, sin relaciones (las de las respuestas de alta, edición y estatus). */
    private function datosBase(Convenio $c): array
    {
        return [
            'id' => $c->id,
            'folio' => $c->folio,
            'temporada_id' => $c->temporada_id,
            'productor_id' => $c->productor_id,
            'cultivo_id' => $c->cultivo_id,
            'variedad_id' => $c->variedad_id,
            'modalidad' => $c->modalidad,
            'unidad' => $c->unidad,
            'moneda' => $c->moneda,
            'porcentaje_rezaga' => $c->porcentaje_rezaga,
            'fecha_inicio' => $c->fecha_inicio?->format('Y-m-d'),
            'fecha_fin' => $c->fecha_fin?->format('Y-m-d'),
            'modo_volumen' => $c->modo_volumen,
            'volumen_total' => $c->volumen_total,
            'status' => $c->status,
            'notas' => $c->notas,
        ];
    }

    /**
     * Precios del convenio como los ven las pantallas: el tipo principal primero, luego por
     * tipo de carga y vigencia. Compartido con recepciones/semana. Requiere `tipoCarga` cargado.
     *
     * @param  \Illuminate\Support\Collection<int, ConvenioPrecio>  $precios
     */
    public static function presentarPrecios(\Illuminate\Support\Collection $precios): array
    {
        return $precios
            ->sortBy([['es_principal', 'desc'], ['tipo_carga_id', 'asc'], ['vigencia_inicio', 'asc']])
            ->map(fn (ConvenioPrecio $p) => [
                'id' => $p->id,
                'tipo_carga_id' => $p->tipo_carga_id,
                'tipo_carga' => $p->tipoCarga ? ['id' => $p->tipoCarga->id, 'nombre' => $p->tipoCarga->nombre] : null,
                'precio' => $p->precio,
                'porcentaje_productor' => $p->porcentaje_productor,
                'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
                'es_principal' => $p->es_principal,
                'notas' => $p->notas,
            ])->values()->all();
    }

    /** Convenio con sus precios y metas, que deben estar cargados. */
    private function armar(Convenio $c): array
    {
        return $this->datosBase($c) + [
            'precios' => self::presentarPrecios($c->precios),
            'metas' => $c->metas
                ->sortBy(fn (ConvenioMeta $m) => $m->semana->format('Y-m-d'))
                ->map(fn (ConvenioMeta $m) => [
                    'semana' => $m->semana->format('Y-m-d'),
                    'cantidad' => $m->cantidad,
                ])->values()->all(),
        ];
    }

    /** Precio principal vigente al inicio del convenio, resuelto sobre los precios ya cargados. */
    private function precioPrincipal(Convenio $c): ?array
    {
        $p = PrecioVigente::principalEnColeccion($c->precios, $c->fecha_inicio->format('Y-m-d'));

        return $p === null ? null : [
            'id' => $p->id,
            'tipo_carga_id' => $p->tipo_carga_id,
            'tipo_carga' => $p->tipoCarga ? ['id' => $p->tipoCarga->id, 'nombre' => $p->tipoCarga->nombre] : null,
            'precio' => $p->precio,
            'porcentaje_productor' => $p->porcentaje_productor,
            'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'),
        ];
    }
}
