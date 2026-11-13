<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\RecepcionCalibre;
use App\Models\Enterprise;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\ImportadorRecepciones;
use App\Services\Abastecimiento\IndicadoresConvenio;
use App\Services\Abastecimiento\LectorRecepciones;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\ReglasRecepcion;
use App\Services\Abastecimiento\ReglasRenglonesRecepcion;
use App\Services\Abastecimiento\Semanas;
use App\Services\Abastecimiento\ValidadorImportacionRecepciones;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
        'convenio:id,folio,productor_id,variedad_id,unidad,moneda,usa_calibres',
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
        self::cargarRenglones($pagina->getCollection());
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
        // Calibres pactados y el de cada precio, solo para los convenios por calibre (con borrados).
        ConvenioController::cargarCalibres($convenios);

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
                'usa_calibres' => (bool) $c->usa_calibres,
                'calibres' => $this->calibresDeFila($c),
            ];
        })->values();

        $recepciones = Recepcion::deEmpresa($empresa->id)
            ->whereIn('convenio_id', $this->convenioIdsDeTemporada($empresa, (int) $datos['temporada_id']))
            ->whereDate('fecha', '>=', $rango['lunes'])->whereDate('fecha', '<=', $rango['domingo'])
            ->with(self::CARGA)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();
        self::cargarRenglones($recepciones);
        $recepciones = $recepciones->map(fn (Recepcion $r) => $this->presentar($r))->values();

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

    /**
     * Da hasta 120 s a las importaciones grandes. Si el límite ya es ilimitado (consola, pruebas) no se toca:
     * set_time_limit(120) lo fijaría para el resto del proceso y mataría una suite larga.
     */
    private function ampliarTiempo(): void
    {
        $actual = (int) ini_get('max_execution_time');
        if ($actual !== 0 && $actual < 120) {
            set_time_limit(120);
        }
    }

    /** Lee y valida el Excel renglón por renglón sin guardar nada. */
    public function vistaPreviaImportacion(Request $request, LectorRecepciones $lector, ValidadorImportacionRecepciones $validador): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'importar');
        $this->validarArchivo($request);
        $this->ampliarTiempo(); // hasta 2 000 renglones

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
        $this->ampliarTiempo(); // hasta 2 000 renglones: validar, crear y registrar la bitácora de cada uno

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
            ReglasRecepcion::validar($convenio, $datos, null, $this->preciosPorCalibre($convenio));

            $recepcion = Recepcion::create($this->cabecera($convenio, $datos) + [
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'RC'),
                'created_by' => $request->user()?->id,
            ]);
            $this->reemplazarRenglones($convenio, $recepcion, $datos);

            return $recepcion;
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
            ReglasRecepcion::exigirEditable($recepcion);
            $this->exigirMismoTipoDeConvenio($recepcion, $convenio);
            // Se valida el estado final; el convenio debe estar activo solo si la recepción cambia de convenio.
            ReglasRecepcion::validar($convenio, $datos, $recepcion, $this->preciosPorCalibre($convenio));

            $recepcion->update($this->cabecera($convenio, $datos));
            $this->reemplazarRenglones($convenio, $recepcion, $datos);

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

            $recepcion->load(self::CARGA);
            self::cargarRenglones(new EloquentCollection([$recepcion]));
            $data = $this->presentar($recepcion);
            $recepcion->delete();

            return $data;
        });

        $this->difundir('recepcion', 'deleted', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Recepción eliminada.', 'data' => ['id' => $id]]);
    }

    /**
     * Cuerpo de alta y edición con los tipos y las llaves de la empresa ya validados. El cuerpo
     * clásico no cambia; el de un convenio por calibre trae `renglones` (lista de objetos) y
     * entonces la cantidad de primer nivel deja de ser obligatoria: la regla de negocio decide.
     * Los renglones se validan como lista de objetos ANTES de las reglas de negocio, que no
     * aceptan otra forma.
     */
    private function validar(Request $request, Enterprise $empresa): array
    {
        $viva = fn (string $tabla) => Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');
        $porRenglones = $request->exists('renglones');

        $datos = $request->validate([
            'convenio_id' => ['required', 'integer', $viva('abs_convenios')],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'tipo_carga_id' => ['required', 'integer', $viva('abs_tipos_carga')],
            'cantidad' => [$porRenglones ? 'nullable' : 'required', 'numeric', 'max:999999999999'],
            'kilos' => ['nullable', 'numeric', 'max:999999999999.99'],
            'rezaga_kg' => ['nullable', 'numeric', 'max:999999999999.99'],
            'ticket_proveedor' => ['nullable', 'string', 'max:60'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'renglones' => ['sometimes', 'nullable', 'array', 'max:100'],
            'renglones.*' => ['array'],
            'renglones.*.calibre_id' => ['required', 'integer'],
            'renglones.*.cantidad' => ['nullable', 'numeric', 'max:999999999999'],
            'renglones.*.kilos' => ['nullable', 'numeric', 'max:999999999999.99'],
            'renglones.*.rezaga_kg' => ['nullable', 'numeric', 'max:999999999999.99'],
        ], [
            'cantidad.required' => 'Captura la cantidad.',
            'cantidad.numeric' => 'La cantidad debe ser un número.',
            'kilos.numeric' => 'Los kilos deben ser un número.',
            'rezaga_kg.numeric' => 'La rezaga debe ser un número.',
            'fecha.required' => 'Captura la fecha.',
            'fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'renglones.array' => 'Captura la recepción por calibre.',
            'renglones.max' => 'Una recepción admite hasta 100 calibres.',
            'renglones.*.array' => 'El renglón no es válido.',
            'renglones.*.calibre_id.required' => 'Elige el calibre.',
            'renglones.*.calibre_id.integer' => 'El calibre no es de este convenio.',
            'renglones.*.cantidad.numeric' => 'La cantidad debe ser un número entero mayor a cero.',
            'renglones.*.kilos.numeric' => 'Los kilos deben ser un número.',
            'renglones.*.rezaga_kg.numeric' => 'La rezaga debe ser un número.',
        ]);

        return $datos + ['cantidad' => null, 'kilos' => null, 'rezaga_kg' => null, 'ticket_proveedor' => null, 'notas' => null];
    }

    /**
     * Columnas de la cabecera. En un convenio por calibre cantidad, kilos y rezaga son la suma
     * de los renglones (lo que traiga el cuerpo en el primer nivel se ignora).
     */
    private function cabecera(Convenio $convenio, array $datos): array
    {
        $cabecera = Arr::except($datos, ['renglones']);

        if (! $convenio->usa_calibres) {
            return $cabecera;
        }

        return ReglasRenglonesRecepcion::totalesCabecera(array_values($datos['renglones'] ?? [])) + $cabecera;
    }

    /**
     * Reemplaza los renglones de una recepción por calibre (borra los anteriores y crea los del
     * cuerpo) dentro de la transacción del alta o la edición: reenviar el mismo cuerpo deja el
     * mismo estado. En un convenio clásico no hace nada.
     */
    private function reemplazarRenglones(Convenio $convenio, Recepcion $recepcion, array $datos): void
    {
        if (! $convenio->usa_calibres) {
            return;
        }

        $vacio = fn ($v) => $v === null || $v === '' ? null : $v;
        $recepcion->renglones()->delete();
        foreach (array_values($datos['renglones'] ?? []) as $r) {
            $recepcion->renglones()->create([
                'calibre_id' => (int) $r['calibre_id'],
                'cantidad' => $r['cantidad'],
                'kilos' => $vacio($r['kilos'] ?? null),
                'rezaga_kg' => $vacio($r['rezaga_kg'] ?? null),
            ]);
        }
        $recepcion->unsetRelation('renglones');
    }

    /** Una recepción no pasa de un convenio clásico a uno por calibre ni al revés. */
    private function exigirMismoTipoDeConvenio(Recepcion $recepcion, Convenio $destino): void
    {
        if ((int) $recepcion->convenio_id === (int) $destino->id) {
            return;
        }

        $origenPorCalibre = (bool) Convenio::withTrashed()->whereKey($recepcion->convenio_id)->value('usa_calibres');
        if ($origenPorCalibre !== (bool) $destino->usa_calibres) {
            throw ValidationException::withMessages([
                'convenio_id' => 'No se puede pasar una recepción entre un convenio clásico y uno por calibre.',
            ]);
        }
    }

    /**
     * En un convenio por calibre, sus precios leídos UNA vez para validar todos los renglones
     * (sin una consulta por renglón). En un clásico, null: la regla consulta como siempre.
     */
    private function preciosPorCalibre(Convenio $convenio): ?PrecioVigente
    {
        return $convenio->usa_calibres
            ? self::preciosCargados(ConvenioPrecio::where('convenio_id', $convenio->id)->get())
            : null;
    }

    /**
     * PrecioVigente que resuelve sobre precios ya cargados (los de un solo convenio), sin consultas.
     *
     * @param  Collection<int, ConvenioPrecio>  $precios
     */
    private static function preciosCargados(Collection $precios): PrecioVigente
    {
        return new class($precios) extends PrecioVigente
        {
            public function __construct(private Collection $cargados) {}

            protected function precios(Convenio $convenio): Collection
            {
                return $this->cargados;
            }
        };
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
        self::cargarRenglones(new EloquentCollection([$recepcion]));
        $convenio = Convenio::with(['precios', 'metas'])->findOrFail($recepcion->convenio_id);

        return $this->presentar($recepcion) + [
            'semana' => $this->filaSemana(IndicadoresConvenio::de($convenio), Semanas::lunesDe($recepcion->fecha)),
        ];
    }

    /**
     * Recepción con `convenio` (y sus `precios`), `tipoCarga`, `creador` y `renglones` (ver
     * cargarRenglones) cargados; no consulta nada. En una recepción por calibre `precio` y
     * `porcentaje_productor` de cabecera son null y el importe es la suma de sus renglones.
     */
    private function presentar(Recepcion $r): array
    {
        $c = $r->convenio;
        $porCalibre = (bool) $c->usa_calibres;
        $precio = $porCalibre ? null : PrecioVigente::enColeccion($c->precios, $r->tipo_carga_id, $r->fecha->format('Y-m-d'));

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
                'usa_calibres' => $porCalibre,
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
            'importe_estimado' => $porCalibre
                ? ReglasRecepcion::importeRenglones($c, $r, self::preciosCargados($c->precios))
                : ReglasRecepcion::importeEstimado($c, $r, $precio),
            'liquidacion_id' => $r->liquidacion_id,
            'liquidada' => $r->estaLiquidada(),
            'created_by' => $r->created_by,
            'autor' => $r->creador ? ['id' => $r->creador->id, 'name' => $r->creador->name] : null,
            'renglones' => self::presentarRenglones($c, $r),
        ];
    }

    /**
     * Carga en bloque los renglones (con el nombre de su calibre, aun borrado) de las recepciones
     * de convenios por calibre; a las clásicas les deja la relación vacía sin consultar. Con
     * `$convenio` todas son de ese convenio; si no, cada una debe traer su `convenio` cargado.
     *
     * @param  EloquentCollection<int, Recepcion>  $recepciones
     */
    public static function cargarRenglones(EloquentCollection $recepciones, ?Convenio $convenio = null): void
    {
        $porCalibre = new EloquentCollection();
        foreach ($recepciones as $r) {
            if (($convenio ?? $r->convenio)?->usa_calibres) {
                $porCalibre->push($r);
            } else {
                $r->setRelation('renglones', new EloquentCollection());
            }
        }

        if ($porCalibre->isNotEmpty()) {
            $porCalibre->load(['renglones.calibre' => fn ($q) => $q->select('id', 'nombre')]);
        }
    }

    /**
     * Renglones de la recepción con el precio vigente de su calibre (tipo de carga y fecha de la
     * recepción) y su importe, resueltos sobre los precios ya cargados del convenio. Lista vacía
     * en una recepción clásica. Requiere `renglones.calibre` cargado (cargarRenglones).
     */
    public static function presentarRenglones(Convenio $c, Recepcion $r): array
    {
        $fecha = $r->fecha->format('Y-m-d');

        return $r->renglones->map(function (RecepcionCalibre $g) use ($c, $r, $fecha) {
            $precio = PrecioVigente::enColeccion($c->precios, (int) $r->tipo_carga_id, $fecha, (int) $g->calibre_id);
            $cantidad = (float) ($c->unidad === 'kg' ? $g->kilos : $g->cantidad);
            $porcentaje = $precio?->porcentaje_productor === null ? 100.0 : (float) $precio->porcentaje_productor;

            return [
                'id' => $g->id,
                'calibre_id' => $g->calibre_id,
                'calibre' => $g->calibre ? ['id' => $g->calibre->id, 'nombre' => $g->calibre->nombre] : null,
                'cantidad' => (float) $g->cantidad,
                'kilos' => $g->kilos === null ? null : (float) $g->kilos,
                'rezaga_kg' => $g->rezaga_kg === null ? null : (float) $g->rezaga_kg,
                'precio' => $precio === null ? null : (float) $precio->precio,
                'porcentaje_productor' => $precio?->porcentaje_productor === null ? null : (float) $precio->porcentaje_productor,
                // Misma fórmula que ReglasRenglonesRecepcion::importe, renglón por renglón.
                'importe_estimado' => $precio === null ? null : round($cantidad * (float) $precio->precio * $porcentaje / 100, 2),
            ];
        })->values()->all();
    }

    /**
     * Calibres pactados de un convenio por calibre para la rejilla de la semana: todos, por orden y
     * con nombre aunque el calibre esté inactivo o borrado. Requiere `calibres.calibre` (cargarCalibres).
     */
    private function calibresDeFila(Convenio $c): array
    {
        if (! $c->usa_calibres) {
            return [];
        }

        return $c->calibres
            ->sortBy([fn ($a, $b) => ($a->calibre?->orden ?? 0) <=> ($b->calibre?->orden ?? 0), ['calibre_id', 'asc']])
            ->map(fn ($fila) => [
                'calibre_id' => $fila->calibre_id,
                'nombre' => $fila->calibre?->nombre,
                'orden' => $fila->calibre?->orden,
            ])->values()->all();
    }
}
