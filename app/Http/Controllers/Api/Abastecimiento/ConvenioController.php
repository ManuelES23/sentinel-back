<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\Variedad;
use App\Models\Enterprise;
use App\Services\Abastecimiento\CalibresConvenio;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\IndicadoresConvenio;
use App\Services\Abastecimiento\MetasConvenio;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\ReglasConvenio;
use App\Services\Abastecimiento\SaldoProductor;
use App\Services\Abastecimiento\Semanas;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        self::cargarCalibres($convenios);

        $hoy = Semanas::hoy();
        $lineas = IndicadoresConvenio::lineas($convenios);

        $data = $convenios->map(function (Convenio $c) use ($hoy, $lineas) {
            $indicadores = IndicadoresConvenio::de($c, $hoy, $lineas->get($c->id, collect()));
            unset($indicadores['semanas']);

            return $this->datosBase($c) + [
                'calibres' => CalibresConvenio::presentar($c),
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
        self::cargarCalibres(new EloquentCollection([$convenio]));

        $verDinero = $this->ctx->puede($request->user(), $empresa, 'liquidaciones', 'view');

        $recientes = Recepcion::where('convenio_id', $convenio->id)
            ->with('tipoCarga:id,nombre')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->limit(10)
            ->get();
        // Renglones por calibre de esas recepciones (lista vacía en un convenio clásico, sin consultar).
        RecepcionController::cargarRenglones($recientes, $convenio);

        $data = $this->armar($convenio) + [
            'productor' => $convenio->productor,
            'variedad' => $convenio->variedad,
            'precio_principal' => $this->precioPrincipal($convenio),
            // Plan 2: dinero solo con `liquidaciones.view` (null = sin permiso; [] = sin liquidaciones). `por_pagar` sale de
            // SaldoProductor (aprobadas y pagadas menos lo aplicado de ESTE convenio).
            'indicadores' => IndicadoresConvenio::de($convenio) + ['por_pagar' => $verDinero ? SaldoProductor::deConvenio($convenio)['por_pagar'] : null],
            'liquidaciones' => ! $verDinero ? null : Liquidacion::where('convenio_id', $convenio->id)
                ->orderByDesc('id')
                ->get(['id', 'folio', 'status', 'periodo_inicio', 'periodo_fin', 'monto_final', 'moneda'])
                ->map(fn (Liquidacion $l) => [
                    'id' => $l->id,
                    'folio' => $l->folio,
                    'status' => $l->status,
                    'periodo_inicio' => $l->periodo_inicio->format('Y-m-d'),
                    'periodo_fin' => $l->periodo_fin->format('Y-m-d'),
                    'monto_final' => round((float) $l->monto_final, 2),
                    'moneda' => $l->moneda,
                ])->values()->all(),
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
                'renglones' => RecepcionController::presentarRenglones($convenio, $r),
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

            if ($datos['usa_calibres']) {
                CalibresConvenio::guardar($convenio, $datos['calibres']);
            }
            $this->guardarPrecios($convenio, $datos['precios']);
            $this->guardarMetas($convenio, $datos['metas'] ?? []);

            return $convenio;
        }, 3);

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

        $datos = $this->validar($request, $empresa, $convenio);

        // Con recepciones vivas, lo ya capturado no se puede dejar huérfano.
        $errores = $this->erroresConRecepciones($convenio, $datos);
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        // El volumen se revisa sobre el estado final: lo enviado sobre lo guardado.
        // Sin `metas` en la petición se conservan las guardadas y se revisan contra las fechas nuevas.
        $metasEnviadas = array_key_exists('metas', $datos);
        $final = $datos + ['volumen_total' => $convenio->volumen_total];
        $idsCalibres = array_map(fn (array $c) => (int) $c['calibre_id'], $datos['calibres']);
        if (! $metasEnviadas) {
            // Solo cuentan las metas del modo final: sin calibre en un clásico; en uno por calibre, las de los
            // calibres que se quedan. Las demás (otro modo o calibre quitado) se borran al guardar.
            $final['metas'] = CalibresConvenio::metasDelModoFinal($convenio, $datos['usa_calibres'], $idsCalibres);
        }
        ReglasConvenio::validarVolumen($final, ! $metasEnviadas);

        $convenio = DB::transaction(function () use ($empresa, $id, $datos, $metasEnviadas, $idsCalibres) {
            // Se relee con bloqueo exclusivo y se repite la revisión: una recepción confirmada entre la lectura de
            // arriba y esta escritura no debe quedar huérfana (calibre quitado o sin precio vigente). El alta de
            // recepciones toma `sharedLock` del convenio, así que una espera a la otra. Lo de arriba solo adelanta
            // el mismo 422 sin abrir la transacción.
            $convenio = Convenio::deEmpresa($empresa->id)->lockForUpdate()->findOrFail($id);
            if (in_array($convenio->status, ['finalizado', 'cancelado'], true)) {
                throw ValidationException::withMessages(['status' => 'Un convenio finalizado o cancelado no se edita.']);
            }
            $errores = $this->erroresConRecepciones($convenio, $datos);
            if ($errores !== []) {
                throw ValidationException::withMessages($errores);
            }

            $convenio->update($this->columnas($datos));

            // Los calibres y los precios se reemplazan por la lista enviada (sin calibres, se borran). Las metas
            // se reemplazan solo si vienen; en volumen total no existen renglones, así que pasar a total las borra.
            CalibresConvenio::guardar($convenio, $datos['calibres']);
            $this->guardarPrecios($convenio, $datos['precios']);
            if ($datos['modo_volumen'] === 'total') {
                $this->guardarMetas($convenio, []);
            } elseif ($metasEnviadas) {
                $this->guardarMetas($convenio, $datos['metas']);
            } else {
                // Conserva las guardadas del modo final; se van las del otro modo y las de calibres quitados.
                CalibresConvenio::borrarMetasFueraDelModo($convenio, $datos['usa_calibres'], $idsCalibres);
            }

            return $convenio;
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
                if ($convenio->usa_calibres) {
                    CalibresConvenio::validarActivacion($convenio);
                } elseif (MetasConvenio::pactado($convenio) <= 0) {
                    throw ValidationException::withMessages(['status' => 'Define el volumen pactado antes de activar el convenio.']);
                }
            }

            if ($destino === 'cancelado' && $convenio->recepciones()->exists()) {
                throw ValidationException::withMessages(['status' => 'Tiene recepciones: finalízalo en lugar de cancelarlo.']);
            }
            // Una liquidación (también la de carga inicial, aunque no tenga recepciones) es deuda con el productor:
            // cancelar el convenio la dejaría contando en el saldo sin convenio vivo. Finalizar y suspender siguen igual.
            if ($destino === 'cancelado' && $this->tieneLiquidacionesVigentes($convenio)) {
                throw ValidationException::withMessages(['status' => 'Tiene liquidaciones: cancélalas primero o finaliza el convenio en lugar de cancelarlo.']);
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
    private function validar(Request $request, Enterprise $empresa, ?Convenio $convenio = null): array
    {
        $viva = fn (string $tabla) => Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');

        // Con calibres la misma semana se repite (una meta por calibre); el par lo revisa ReglasConvenio.
        // Sin la llave (front viejo en caché) se conserva el valor guardado: en un convenio por calibre falla
        // cerrado (422 `calibres`) en lugar de volverlo clásico en silencio. En el alta, por omisión false.
        $usaCalibres = $request->has('usa_calibres')
            ? $request->boolean('usa_calibres')
            : (bool) ($convenio?->usa_calibres ?? false);

        $datos = $request->validate([
            'usa_calibres' => ['sometimes', 'boolean'],
            'calibres' => ['nullable', 'array'],
            'calibres.*.calibre_id' => ['required', 'integer', 'distinct'],
            'calibres.*.volumen_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
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
            'metas.*.semana' => array_merge(['required', 'date_format:Y-m-d'], $usaCalibres ? [] : ['distinct']),
            'metas.*.cantidad' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'precios' => ['required', 'array', 'min:1'],
            'precios.*.tipo_carga_id' => ['required', 'integer', $viva('abs_tipos_carga')],
            'precios.*.precio' => ['required', 'numeric', 'gt:0', 'max:99999999.9999'],
            'precios.*.porcentaje_productor' => ['nullable', 'numeric', 'between:0,100'],
            'precios.*.vigencia_inicio' => ['required', 'date_format:Y-m-d'],
            'precios.*.es_principal' => ['nullable', 'boolean'],
            'precios.*.notas' => ['nullable', 'string', 'max:500'],
            // Opcionales al final: `validated()` arma cada renglón en el orden de las reglas, y una regla de un
            // campo opcional antes de las obligatorias reordenaría los renglones (el índice N de los errores).
            'metas.*.calibre_id' => ['nullable', 'integer'],
            'precios.*.calibre_id' => ['nullable', 'integer'],
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

        $datos['usa_calibres'] = $usaCalibres;
        $calibres = array_values($datos['calibres'] ?? []);

        if (! $usaCalibres) {
            if ($calibres !== []) {
                throw ValidationException::withMessages(['calibres' => 'Este convenio no es por calibre.']);
            }
            $conCalibre = [];
            foreach (array_values($datos['metas'] ?? []) as $i => $meta) {
                if (isset($meta['calibre_id']) && $meta['calibre_id'] !== '') {
                    $conCalibre["metas.{$i}.calibre_id"] = 'Este convenio no es por calibre.';
                }
            }
            if ($conCalibre !== []) {
                throw ValidationException::withMessages($conCalibre);
            }
        }

        // Sin calibres en un convenio por calibre (p. ej. un front viejo) el error va en `calibres`, antes que los
        // de cada precio sin calibre (mismo mensaje que ReglasConvenio::validarCalibres).
        if ($usaCalibres && $calibres === []) {
            throw ValidationException::withMessages(['calibres' => 'Elige al menos un calibre.']);
        }

        ReglasConvenio::validarPrecios($datos['precios'], $datos['modalidad'], $usaCalibres);
        $datos['precios'] = ReglasConvenio::normalizarPrecios($datos['precios'], $datos['modalidad']);

        if ($usaCalibres) {
            // Permitidos: los activos de la variedad más los que el convenio ya pactaba (aunque se hayan desactivado).
            $permitidos = CalibresConvenio::permitidos((int) $datos['variedad_id'], $convenio);
            ReglasConvenio::validarCalibres($calibres, $permitidos, $datos['precios']);

            // El volumen vive en cada calibre; el del convenio no se usa.
            $datos['calibres'] = CalibresConvenio::normalizar($calibres, $datos['modo_volumen']);
            $datos['volumen_total'] = null;
        } else {
            $datos['calibres'] = [];
        }

        return $datos;
    }

    /**
     * Con recepciones vivas: la unidad, la moneda, el productor, la variedad y la temporada no cambian
     * (reinterpretarían lo capturado); las fechas deben seguir cubriendo todas las recepciones y la lista
     * de precios debe dejar un precio vigente para cada tipo de carga y fecha ya recibidos. En un convenio por
     * calibre eso se mira por renglón (tipo, calibre, fecha) y un calibre con renglones no se quita.
     *
     * @return array<string, string>
     */
    private function erroresConRecepciones(Convenio $convenio, array $datos): array
    {
        $conRecepciones = $convenio->recepciones()->exists();
        $conLiquidaciones = $this->tieneLiquidacionesVigentes($convenio);
        if (! $conRecepciones && ! $conLiquidaciones) {
            return [];
        }

        // Con recepciones o con liquidaciones no canceladas (aunque sean de carga inicial, sin recepciones) lo ya
        // capturado o liquidado no se reinterpreta: cambiar moneda, productor o temporada movería deuda ya aprobada.
        $errores = [];
        $sinCambio = $conRecepciones
            ? 'No se puede cambiar: el convenio ya tiene recepciones.'
            : 'No se puede cambiar: el convenio ya tiene liquidaciones.';
        foreach (['unidad', 'moneda', 'productor_id', 'variedad_id', 'temporada_id'] as $campo) {
            if ((string) $datos[$campo] !== (string) $convenio->{$campo}) {
                $errores[$campo] = $sinCambio;
            }
        }
        // Pasar a calibres (o dejarlos) reinterpretaría lo recibido o liquidado. Lo demás de los calibres con
        // recepciones (cobertura por calibre, no quitar uno usado) se revisa contra los renglones, más abajo.
        if ((bool) $datos['usa_calibres'] !== (bool) $convenio->usa_calibres) {
            $errores['usa_calibres'] = $sinCambio;
        }

        // Fechas y precios solo se cuidan contra recepciones.
        if (! $conRecepciones) {
            return $errores;
        }

        $fueraDeFechas = 'No se puede: hay recepciones fuera de las nuevas fechas del convenio.';
        $primera = Carbon::parse($convenio->recepciones()->min('fecha'))->format('Y-m-d');
        $ultima = Carbon::parse($convenio->recepciones()->max('fecha'))->format('Y-m-d');
        if ($datos['fecha_inicio'] > $primera) {
            $errores['fecha_inicio'] = $fueraDeFechas;
        }
        if ($datos['fecha_fin'] < $ultima) {
            $errores['fecha_fin'] = $fueraDeFechas;
        }

        $nuevos = collect($datos['precios'])->map(fn (array $p) => new ConvenioPrecio($p));

        if ($convenio->usa_calibres) {
            // Por calibre (C7/C14) se mira lo recibido en los renglones. Si además cambia `usa_calibres`, ese
            // error ya basta: no se suman los de calibres contra una lista de otro modo.
            if ((bool) $datos['usa_calibres']) {
                $errores += CalibresConvenio::erroresConRecepciones($convenio, $datos['calibres'], $nuevos);
            }

            return $errores;
        }

        // Cada par (tipo de carga, fecha) ya recibido necesita precio vigente con la lista que se va a guardar.
        $sinPrecio = $convenio->recepciones()->select('tipo_carga_id', 'fecha')->distinct()->get()
            ->contains(fn ($r) => PrecioVigente::enColeccion(
                $nuevos,
                (int) $r->tipo_carga_id,
                Carbon::parse($r->fecha)->format('Y-m-d'),
            ) === null);
        if ($sinPrecio) {
            $errores['precios'] = CalibresConvenio::SIN_PRECIO_VIGENTE;
        }

        return $errores;
    }

    /** ¿Tiene liquidaciones que no estén canceladas (borrador, en revisión, aprobada o pagada)? */
    private function tieneLiquidacionesVigentes(Convenio $convenio): bool
    {
        return $convenio->liquidaciones()->where('status', '!=', 'cancelada')->exists();
    }

    private function columnas(array $datos): array
    {
        return collect($datos)->only([
            'temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad', 'unidad', 'moneda',
            'porcentaje_rezaga', 'fecha_inicio', 'fecha_fin', 'modo_volumen', 'usa_calibres', 'volumen_total', 'notas',
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
                ->when(
                    ($fila['calibre_id'] ?? null) === null,
                    fn ($q) => $q->whereNull('calibre_id'),
                    fn ($q) => $q->where('calibre_id', $fila['calibre_id']),
                )
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
                'calibre_id' => isset($meta['calibre_id']) && $meta['calibre_id'] !== '' ? (int) $meta['calibre_id'] : null,
                'semana' => $meta['semana'],
                'cantidad' => $meta['cantidad'],
            ]);
        }
    }

    /** Calibres de los convenios por calibre en carga ansiosa (ver CalibresConvenio::cargar). Requiere `precios` cargados. */
    public static function cargarCalibres(EloquentCollection $convenios): void
    {
        CalibresConvenio::cargar($convenios);
    }

    /** Respuesta de alta, edición y estatus: el convenio con su precio principal e indicadores (sin semanas). */
    private function presentar(Convenio $c): array
    {
        $c->load(['precios.tipoCarga:id,nombre', 'metas']);
        $c->unsetRelation('calibres');
        self::cargarCalibres(new EloquentCollection([$c]));

        $indicadores = IndicadoresConvenio::de($c);
        unset($indicadores['semanas']);

        return $this->armar($c) + [
            'precio_principal' => $this->precioPrincipal($c),
            'indicadores' => $indicadores,
        ];
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
            'usa_calibres' => (bool) $c->usa_calibres,
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
            ->sortBy([['es_principal', 'desc'], ['tipo_carga_id', 'asc'], ['calibre_id', 'asc'], ['vigencia_inicio', 'asc']])
            ->map(fn (ConvenioPrecio $p) => [
                'id' => $p->id,
                'tipo_carga_id' => $p->tipo_carga_id,
                'tipo_carga' => $p->tipoCarga ? ['id' => $p->tipoCarga->id, 'nombre' => $p->tipoCarga->nombre] : null,
                // Sin calibre (convenio clásico) no se consulta nada: la relación con llave nula es null.
                'calibre_id' => $p->calibre_id,
                'calibre' => $p->calibre_id !== null && $p->calibre ? ['id' => $p->calibre->id, 'nombre' => $p->calibre->nombre] : null,
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
            'calibres' => CalibresConvenio::presentar($c),
            'precios' => self::presentarPrecios($c->precios),
            'metas' => $c->metas
                ->sortBy([
                    fn ($a, $b) => $a->semana->format('Y-m-d') <=> $b->semana->format('Y-m-d'),
                    fn ($a, $b) => ($a->calibre_id ?? 0) <=> ($b->calibre_id ?? 0),
                ])
                ->map(fn (ConvenioMeta $m) => [
                    'semana' => $m->semana->format('Y-m-d'),
                    'cantidad' => $m->cantidad,
                    'calibre_id' => $m->calibre_id,
                ])->values()->all(),
        ];
    }

    /**
     * Precio principal vigente al inicio del convenio, resuelto sobre los precios ya cargados.
     * En un convenio por calibre no hay un solo precio principal (uno por calibre): null.
     */
    private function precioPrincipal(Convenio $c): ?array
    {
        if ($c->usa_calibres) {
            return null;
        }
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
