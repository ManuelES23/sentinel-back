<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\ImportadorRecepciones;
use App\Services\Abastecimiento\IndicadoresConvenio;
use App\Services\Abastecimiento\LectorRecepciones;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\ReglasRecepcion;
use App\Services\Abastecimiento\Semanas;
use App\Services\Abastecimiento\ValidadorImportacionRecepciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Recepciones de producto contra un convenio (submódulo `recepciones`): alta,
 * edición, baja, listado filtrado y vista por semana. Las reglas de negocio
 * viven en ReglasRecepcion; las metas y lo recibido por semana, en IndicadoresConvenio.
 */
class RecepcionController extends AbastecimientoController
{
    private const SUBMODULO = 'recepciones';

    private const POR_PAGINA = 50;

    /** Relaciones que necesita `presentar`; el convenio trae solo columnas mínimas. */
    private const CARGA = [
        'convenio:id,folio,productor_id,variedad_id,unidad,moneda',
        'convenio.productor:id,nombre,tipo',
        'convenio.variedad:id,nombre',
        'convenio.precios',
        'tipoCarga:id,nombre',
        'creador:id,name',
    ];

    /**
     * Recepciones de una temporada (más recientes primero, de 50 en 50). Un
     * número constante de consultas: los precios viajan con el convenio y el
     * precio vigente se resuelve en memoria con PrecioVigente::enColeccion.
     */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['required', 'integer', $this->temporadaDeEmpresa($empresa)],
            'lunes' => ['nullable', 'date_format:Y-m-d'],
            'convenio_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $this->exigirLunes($filtros['lunes'] ?? null);

        $query = Recepcion::deEmpresa($empresa->id)
            ->whereIn('convenio_id', $this->convenioIdsDeTemporada($empresa, (int) $filtros['temporada_id']))
            ->when(! empty($filtros['lunes']), function ($q) use ($filtros) {
                $semana = Semanas::rango($filtros['lunes']);
                $q->whereDate('fecha', '>=', $semana['lunes'])->whereDate('fecha', '<=', $semana['domingo']);
            })
            ->when(! empty($filtros['convenio_id']), fn ($q) => $q->where('convenio_id', $filtros['convenio_id']))
            ->when($request->boolean('con_rezaga'), fn ($q) => $q->where('rezaga_kg', '>', 0))
            ->when($request->boolean('sin_liquidar'), fn ($q) => $q->whereNull('liquidacion_id'))
            ->when(! empty($filtros['search']), function ($q) use ($filtros) {
                // '!' como carácter de escape: funciona igual en MySQL y SQLite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filtros['search'])).'%';
                $q->where(function ($q) use ($like) {
                    $q->whereRaw("abs_recepciones.folio LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("abs_recepciones.ticket_proveedor LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('convenio', function ($c) use ($like) {
                            $c->whereRaw("abs_convenios.folio LIKE ? ESCAPE '!'", [$like])
                                ->orWhereHas('productor', fn ($p) => $p->whereRaw("abs_productores.nombre LIKE ? ESCAPE '!'", [$like]));
                        });
                });
            })
            ->with(self::CARGA)
            ->orderByDesc('fecha')->orderByDesc('id');

        $pagina = $query->paginate(self::POR_PAGINA);
        $pagina->getCollection()->transform(fn (Recepcion $r) => $this->presentar($r));

        return response()->json(['success' => true, 'data' => $pagina]);
    }

    /**
     * Rejilla de una semana: un renglón por convenio activo que tiene esa semana
     * entre sus fechas (meta y recibido de la semana, cantidad por día de lunes a
     * sábado) y las recepciones de la semana. La cantidad va en la unidad del convenio.
     *
     * Alcance: `por_dia` cubre solo lunes a sábado, en la unidad del convenio (kilos si se paga
     * por kilo); `recibido_semana` sí incluye el domingo, así que puede superar la suma de
     * `por_dia` (las del domingo solo aparecen en `recepciones`). Un PUT que cambia fecha o
     * convenio devuelve únicamente la semana destino, no la de origen.
     */
    public function semana(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $datos = $request->validate([
            'temporada_id' => ['required', 'integer', $this->temporadaDeEmpresa($empresa)],
            'lunes' => ['required', 'date_format:Y-m-d'],
        ]);
        $this->exigirLunes($datos['lunes']);

        $rango = Semanas::rango($datos['lunes']);
        $dias = array_map(fn (int $i) => date('Y-m-d', strtotime($rango['lunes']." +{$i} day")), range(0, 5));

        $convenios = Convenio::deEmpresa($empresa->id)
            ->where('temporada_id', $datos['temporada_id'])
            ->where('status', 'activo')
            ->whereDate('fecha_inicio', '<=', $rango['domingo'])
            ->whereDate('fecha_fin', '>=', $rango['lunes'])
            ->with(['productor:id,nombre,tipo', 'variedad:id,nombre', 'precios.tipoCarga:id,nombre', 'metas'])
            ->orderBy('fecha_inicio')->orderBy('id')
            ->get();

        $hoy = Semanas::hoy();
        $lineas = IndicadoresConvenio::lineas($convenios);

        $filas = $convenios->map(function (Convenio $c) use ($lineas, $hoy, $dias, $rango) {
            $propias = $lineas->get($c->id, collect());
            $semana = $this->filaSemana(IndicadoresConvenio::de($c, $hoy, $propias), $rango['lunes']);

            $porDia = array_fill_keys($dias, 0.0);
            foreach ($propias as $linea) {
                if (array_key_exists($linea['fecha'], $porDia)) {
                    $porDia[$linea['fecha']] += $linea['cantidad'];
                }
            }

            return [
                'id' => $c->id,
                'folio' => $c->folio,
                'productor' => $c->productor,
                'variedad' => $c->variedad,
                'unidad' => $c->unidad,
                'moneda' => $c->moneda,
                'modalidad' => $c->modalidad,
                'status' => $c->status,
                'fecha_inicio' => $c->fecha_inicio->format('Y-m-d'),
                'fecha_fin' => $c->fecha_fin->format('Y-m-d'),
                'precios' => ConvenioController::presentarPrecios($c->precios),
                'meta_semana' => $semana['meta'],
                'recibido_semana' => $semana['recibido'],
                'por_dia' => array_map(fn (float $v) => round($v, 2), $porDia),
            ];
        })->values();

        $recepciones = Recepcion::deEmpresa($empresa->id)
            ->whereIn('convenio_id', $this->convenioIdsDeTemporada($empresa, (int) $datos['temporada_id']))
            ->whereDate('fecha', '>=', $rango['lunes'])->whereDate('fecha', '<=', $rango['domingo'])
            ->with(self::CARGA)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get()
            ->map(fn (Recepcion $r) => $this->presentar($r))
            ->values();

        return response()->json(['success' => true, 'data' => [
            'lunes' => $rango['lunes'],
            'numero' => Semanas::numero($rango['lunes']),
            'domingo' => $rango['domingo'],
            'dias' => $dias,
            'convenios' => $filas,
            'recepciones' => $recepciones,
        ]]);
    }

    /** Plantilla de Excel para importar recepciones (permiso `recepciones.importar`). */
    public function plantilla(Request $request, LectorRecepciones $lector): BinaryFileResponse
    {
        $this->ctx->autorizar($request, self::SUBMODULO, 'importar');

        return response()->download($lector->plantilla(), 'Plantilla_Recepciones.xlsx')->deleteFileAfterSend(true);
    }

    /** Lee y valida el Excel renglón por renglón sin guardar nada. */
    public function vistaPreviaImportacion(Request $request, LectorRecepciones $lector, ValidadorImportacionRecepciones $validador): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'importar');
        $this->validarArchivo($request);
        set_time_limit(120); // hasta 2 000 renglones

        $resultado = $validador->validar($empresa, $lector->leer($request->file('archivo')));

        return response()->json(['success' => true, 'data' => $resultado]);
    }

    /**
     * Importa el Excel (se vuelve a leer y validar completo; el front nunca reenvía renglones).
     * Todo o nada: con un solo renglón con error responde 422 con la lista y no guarda ni gasta folios.
     */
    public function importar(Request $request, LectorRecepciones $lector, ValidadorImportacionRecepciones $validador, ImportadorRecepciones $importador): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'importar');
        $this->validarArchivo($request);
        set_time_limit(120); // hasta 2 000 renglones: validar, crear y registrar la bitácora de cada uno

        $validado = $validador->validar($empresa, $lector->leer($request->file('archivo')));
        if ($validado['resumen']['con_error'] > 0) {
            $conError = array_values(array_filter($validado['filas'], fn (array $f) => $f['estado'] === 'error'));
            $n = count($conError);

            return response()->json([
                'success' => false,
                'message' => ($n === 1 ? '1 renglón tiene errores' : "{$n} renglones tienen errores").'; no se guardó ninguna recepción. Corrige el Excel y vuelve a subirlo.',
                'errors' => ['filas' => array_map(fn (array $f) => ['fila' => $f['fila'], 'errores' => $f['errores']], $conError)],
            ], 422);
        }

        $resultado = $importador->importar($empresa, $request->user(), $validado['filas']);

        // Solo los convenios y cuántas se importaron: sin tickets ni cantidades por renglón.
        $this->difundir('recepcion', 'imported', [
            'convenio_ids' => $resultado['convenio_ids'],
            'cantidad' => $resultado['importadas'],
        ], $empresa);

        $n = $resultado['importadas'];

        return response()->json([
            'success' => true,
            'message' => $n === 1 ? 'Se importó 1 recepción.' : "Se importaron {$n} recepciones.",
            'data' => [
                'importadas' => $n,
                'por_convenio' => $validado['resumen']['por_convenio'],
                'folios' => $resultado['folios'],
            ],
        ], 201);
    }

    private function validarArchivo(Request $request): void
    {
        $request->validate(
            ['archivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']],
            [
                'archivo.required' => 'Sube el archivo de Excel con las recepciones.',
                'archivo.file' => 'Sube el archivo de Excel con las recepciones.',
                'archivo.max' => 'El archivo no puede pesar más de 5 MB.',
                'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx, .xls) o un CSV.',
                'archivo.uploaded' => 'No se pudo subir el archivo (¿pesa más de 5 MB?).',
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa);

        $recepcion = DB::transaction(function () use ($request, $empresa, $datos) {
            // Lectura compartida: un cambio de estatus del convenio espera a que termine el alta.
            $convenio = Convenio::deEmpresa($empresa->id)->sharedLock()->findOrFail($datos['convenio_id']);
            ReglasRecepcion::validar($convenio, $datos);

            return Recepcion::create($datos + [
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'RC'),
                'created_by' => $request->user()?->id,
            ]);
        }, 3);

        $data = $this->presentarConSemana($recepcion);
        $this->difundir('recepcion', 'created', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Recepción registrada.', 'data' => $data], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        Recepcion::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $this->validar($request, $empresa);

        $recepcion = DB::transaction(function () use ($empresa, $id, $datos) {
            // Se relee con bloqueo: una liquidación concurrente no debe colarse entre la revisión y el cambio.
            $recepcion = Recepcion::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);
            $convenio = Convenio::deEmpresa($empresa->id)->sharedLock()->findOrFail($datos['convenio_id']);
            // Se valida el estado final; el convenio debe estar activo solo si la recepción cambia de convenio.
            ReglasRecepcion::validar($convenio, $datos, $recepcion);

            $recepcion->update($datos);

            return $recepcion;
        });

        $data = $this->presentarConSemana($recepcion);
        $this->difundir('recepcion', 'updated', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Recepción actualizada.', 'data' => $data]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $data = DB::transaction(function () use ($empresa, $id) {
            $recepcion = Recepcion::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);
            ReglasRecepcion::exigirEditable($recepcion);

            $data = $this->presentar($recepcion->load(self::CARGA));
            $recepcion->delete();

            return $data;
        });

        $this->difundir('recepcion', 'deleted', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Recepción eliminada.', 'data' => ['id' => $id]]);
    }

    /** Cuerpo de alta y edición con los tipos y las llaves de la empresa ya validados. */
    private function validar(Request $request, Enterprise $empresa): array
    {
        $viva = fn (string $tabla) => Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');

        $datos = $request->validate([
            'convenio_id' => ['required', 'integer', $viva('abs_convenios')],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'tipo_carga_id' => ['required', 'integer', $viva('abs_tipos_carga')],
            'cantidad' => ['required', 'numeric', 'max:999999999999'],
            'kilos' => ['nullable', 'numeric', 'max:999999999999.99'],
            'rezaga_kg' => ['nullable', 'numeric', 'max:999999999999.99'],
            'ticket_proveedor' => ['nullable', 'string', 'max:60'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ], [
            'cantidad.required' => 'Captura la cantidad.',
            'cantidad.numeric' => 'La cantidad debe ser un número.',
            'kilos.numeric' => 'Los kilos deben ser un número.',
            'rezaga_kg.numeric' => 'La rezaga debe ser un número.',
            'fecha.required' => 'Captura la fecha.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
        ]);

        return $datos + ['kilos' => null, 'rezaga_kg' => null, 'ticket_proveedor' => null, 'notas' => null];
    }

    private function temporadaDeEmpresa(Enterprise $empresa)
    {
        return Rule::exists('abs_temporadas', 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');
    }

    /** Subconsulta con los convenios (de cualquier estatus) de la temporada. */
    private function convenioIdsDeTemporada(Enterprise $empresa, int $temporadaId)
    {
        return Convenio::deEmpresa($empresa->id)->where('temporada_id', $temporadaId)->select('id');
    }

    private function exigirLunes(?string $lunes): void
    {
        if ($lunes !== null && Semanas::lunesDe($lunes) !== $lunes) {
            throw ValidationException::withMessages(['lunes' => 'La fecha debe ser un lunes.']);
        }
    }

    /** Meta y recibido del lunes dado dentro de los indicadores del convenio. */
    private function filaSemana(array $indicadores, string $lunes): array
    {
        foreach ($indicadores['semanas'] as $s) {
            if ($s['lunes'] === $lunes) {
                return ['lunes' => $lunes, 'numero' => $s['numero'], 'meta' => $s['meta'], 'recibido' => $s['recibido']];
            }
        }

        return ['lunes' => $lunes, 'numero' => Semanas::numero($lunes), 'meta' => 0.0, 'recibido' => 0.0];
    }

    /** La recepción presentada más `semana {lunes, numero, meta, recibido}` del convenio ya con ella. */
    private function presentarConSemana(Recepcion $recepcion): array
    {
        $recepcion->load(self::CARGA);
        $convenio = Convenio::with(['precios', 'metas'])->findOrFail($recepcion->convenio_id);

        return $this->presentar($recepcion) + [
            'semana' => $this->filaSemana(IndicadoresConvenio::de($convenio), Semanas::lunesDe($recepcion->fecha)),
        ];
    }

    /** Recepción con `convenio` (y sus `precios`), `tipoCarga` y `creador` cargados. */
    private function presentar(Recepcion $r): array
    {
        $c = $r->convenio;
        $precio = PrecioVigente::enColeccion($c->precios, $r->tipo_carga_id, $r->fecha->format('Y-m-d'));

        return [
            'id' => $r->id,
            'folio' => $r->folio,
            'convenio_id' => $r->convenio_id,
            'convenio' => [
                'id' => $c->id,
                'folio' => $c->folio,
                'productor_id' => $c->productor_id,
                'variedad_id' => $c->variedad_id,
                'unidad' => $c->unidad,
                'moneda' => $c->moneda,
                'productor' => $c->productor,
                'variedad' => $c->variedad,
            ],
            'moneda' => $c->moneda,
            'fecha' => $r->fecha->format('Y-m-d'),
            'tipo_carga_id' => $r->tipo_carga_id,
            'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
            'cantidad' => (float) $r->cantidad,
            'kilos' => $r->kilos === null ? null : (float) $r->kilos,
            'rezaga_kg' => $r->rezaga_kg === null ? null : (float) $r->rezaga_kg,
            'ticket_proveedor' => $r->ticket_proveedor,
            'notas' => $r->notas,
            'precio' => $precio === null ? null : (float) $precio->precio,
            'porcentaje_productor' => $precio?->porcentaje_productor === null ? null : (float) $precio->porcentaje_productor,
            'importe_estimado' => ReglasRecepcion::importeEstimado($c, $r, $precio),
            'liquidacion_id' => $r->liquidacion_id,
            'liquidada' => $r->estaLiquidada(),
            'created_by' => $r->created_by,
            'autor' => $r->creador ? ['id' => $r->creador->id, 'name' => $r->creador->name] : null,
        ];
    }
}
