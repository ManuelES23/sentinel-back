<?php

namespace App\Http\Controllers\Api\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionDetalle;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\Abastecimiento\AprobadorLiquidaciones;
use App\Services\Abastecimiento\AvisosAbastecimiento;
use App\Services\Abastecimiento\CadenaLiquidacion;
use App\Services\Abastecimiento\CalculadoraLiquidacion;
use App\Services\Abastecimiento\DespuesDeDecidirLiquidacion;
use App\Services\Abastecimiento\FlujoLiquidacion;
use App\Services\Abastecimiento\GeneradorFolio;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\PresentadorLiquidacion;
use App\Services\Abastecimiento\ReglasLiquidacion;
use App\Services\Abastecimiento\TransaccionDinero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Liquidaciones a productores (submódulo `liquidaciones`): borrador (recepciones disponibles, vista previa,
 * alta, edición, baja, listado y ficha) y flujo (enviar a revisión, aprobar, rechazar y cancelar). Las reglas
 * del flujo viven en `FlujoLiquidacion`; aquí solo se autoriza, se corre en transacción y, después de
 * confirmar, se difunde el cambio y se manda el aviso. La forma del JSON es de `PresentadorLiquidacion`.
 */
class LiquidacionController extends AbastecimientoController
{
    private const SUBMODULO = 'liquidaciones';

    private const TOPE_LISTADO = 300;

    /** Recepciones que el formulario puede ofrecer para un convenio y un periodo (más las del borrador que se edita). */
    public function recepcionesDisponibles(Request $request): JsonResponse
    {
        // Lectura de apoyo del formulario: la usa quien crea y quien corrige un borrador (`create` o `edit`).
        $empresa = $this->ctx->autorizarAlguno($request, self::SUBMODULO, ['create', 'edit']);
        $datos = $request->validate([
            'convenio_id' => ['required', 'integer', $this->viva($empresa, 'abs_convenios')],
            'periodo_inicio' => ['required', 'date_format:Y-m-d'],
            'periodo_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodo_inicio'],
            'liquidacion_id' => ['nullable', 'integer', $this->viva($empresa, 'abs_liquidaciones')],
        ]);

        $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->findOrFail($datos['convenio_id']);
        $recepciones = ReglasLiquidacion::recepcionesDisponibles(
            $convenio, $datos['periodo_inicio'], $datos['periodo_fin'], $datos['liquidacion_id'] ?? null,
        )->load('tipoCarga:id,nombre');

        $data = $recepciones->map(function (Recepcion $r) use ($convenio) {
            $precio = PrecioVigente::enColeccion($convenio->precios, (int) $r->tipo_carga_id, $r->fecha->format('Y-m-d'));

            return [
                'id' => $r->id,
                'folio' => $r->folio,
                'fecha' => $r->fecha->format('Y-m-d'),
                'tipo_carga_id' => $r->tipo_carga_id,
                'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
                'cantidad' => (float) $r->cantidad,
                'kilos' => $r->kilos === null ? null : (float) $r->kilos,
                'rezaga_kg' => $r->rezaga_kg === null ? null : (float) $r->rezaga_kg,
                'ticket_proveedor' => $r->ticket_proveedor,
                'precio' => $precio === null ? null : (float) $precio->precio,
                'liquidacion_id' => $r->liquidacion_id,
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Calcula la liquidación con la selección dada sin guardar nada. */
    public function previa(Request $request): JsonResponse
    {
        // Vista previa sin guardar: la usa quien crea y quien corrige un borrador (`create` o `edit`).
        $empresa = $this->ctx->autorizarAlguno($request, self::SUBMODULO, ['create', 'edit']);
        $datos = $this->validar($request, $empresa, true);

        $convenio = Convenio::deEmpresa($empresa->id)->with(['precios', 'variedad:id,nombre'])->findOrFail($datos['convenio_id']);
        $recepciones = ReglasLiquidacion::validarSeleccion(
            $convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin'], $datos['liquidacion_id'] ?? null,
        )->load('tipoCarga:id,nombre');
        $calculo = CalculadoraLiquidacion::calcular(
            $convenio, $recepciones,
            isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null,
            $datos['motivo_ajuste'] ?? null,
        );

        $porId = $recepciones->keyBy('id');
        $calculo['detalles'] = array_map(fn (array $d) => $this->detallePrevio($d, $porId[$d['recepcion_id']]), $calculo['detalles']);

        return response()->json(['success' => true, 'data' => $calculo + [
            'convenio_id' => $convenio->id,
            'moneda' => $convenio->moneda,
            'unidad' => $convenio->unidad,
            'periodo_inicio' => $datos['periodo_inicio'],
            'periodo_fin' => $datos['periodo_fin'],
        ]]);
    }

    /** Liquidaciones de la empresa, más recientes primero (tope de 300), sin canceladas salvo que se pidan. */
    public function index(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $filtros = $request->validate([
            'temporada_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(Liquidacion::STATUSES)],
            'productor_id' => ['nullable', 'integer'],
            'convenio_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $verCanceladas = $request->boolean('con_canceladas') || ($filtros['status'] ?? null) === 'cancelada';

        $liquidaciones = Liquidacion::deEmpresa($empresa->id)
            ->with($this->presentador()->cargaResumen())
            ->when(! $verCanceladas, fn ($q) => $q->where('status', '!=', 'cancelada'))
            ->when(! empty($filtros['status']), fn ($q) => $q->where('status', $filtros['status']))
            ->when(! empty($filtros['productor_id']), fn ($q) => $q->where('productor_id', $filtros['productor_id']))
            ->when(! empty($filtros['convenio_id']), fn ($q) => $q->where('convenio_id', $filtros['convenio_id']))
            ->when(! empty($filtros['temporada_id']), fn ($q) => $q->whereIn(
                'convenio_id',
                Convenio::deEmpresa($empresa->id)->where('temporada_id', $filtros['temporada_id'])->select('id'),
            ))
            ->when(! empty($filtros['search']), function ($q) use ($filtros) {
                // '!' como carácter de escape: funciona igual en MySQL y SQLite.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filtros['search'])).'%';
                $q->where(function ($q) use ($like) {
                    $q->whereRaw("abs_liquidaciones.folio LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('productor', fn ($p) => $p->whereRaw("abs_productores.nombre LIKE ? ESCAPE '!'", [$like]))
                        ->orWhereHas('convenio', fn ($c) => $c->whereRaw("abs_convenios.folio LIKE ? ESCAPE '!'", [$like]));
                });
            })
            ->orderByDesc('id')
            ->limit(self::TOPE_LISTADO)
            ->get()
            ->map(fn (Liquidacion $l) => $this->presentarResumen($l))
            ->values();

        return response()->json(['success' => true, 'data' => $liquidaciones]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $liquidacion = Liquidacion::deEmpresa($empresa->id)->findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'create');
        $datos = $this->validar($request, $empresa);

        $liquidacion = TransaccionDinero::ejecutar(function () use ($request, $empresa, $datos) {
            $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->findOrFail($datos['convenio_id']);
            TransaccionDinero::bloquearProductor($empresa->id, (int) $convenio->productor_id, true);
            $recepciones = ReglasLiquidacion::validarSeleccion($convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin']);
            $calculo = $this->calcular($convenio, $recepciones, $datos);

            $liquidacion = Liquidacion::create($this->columnas($convenio, $datos, $calculo) + [
                'enterprise_id' => $empresa->id,
                'folio' => app(GeneradorFolio::class)->siguiente($empresa, 'LQ'),
                'created_by' => $request->user()?->id,
                'intervinieron' => $request->user() ? [(int) $request->user()->id] : [],
            ]);
            $this->guardarDetalles($liquidacion, $calculo, $recepciones);

            return $liquidacion;
        }, 3);

        $this->difundir('liquidacion', 'created', PresentadorLiquidacion::evento($liquidacion), $empresa);

        return response()->json([
            'success' => true,
            'message' => 'Liquidación guardada como borrador.',
            'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user()),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        Liquidacion::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $this->validar($request, $empresa);

        $usuarioId = $request->user()?->id;

        $liquidacion = TransaccionDinero::ejecutar(function () use ($empresa, $id, $datos, $usuarioId) {
            // Productor(es) y luego la liquidación con bloqueo: un envío o una cancelación concurrente no debe
            // colarse entre la revisión y el cambio (si cambia de convenio, también el productor del convenio nuevo).
            $convenio = Convenio::deEmpresa($empresa->id)->with('precios')->findOrFail($datos['convenio_id']);
            $liquidacion = TransaccionDinero::bloquearLiquidacion($empresa->id, $id, [(int) $convenio->productor_id]);
            ReglasLiquidacion::exigirBorrador($liquidacion);
            $recepciones = ReglasLiquidacion::validarSeleccion($convenio, $datos['recepcion_ids'], $datos['periodo_inicio'], $datos['periodo_fin'], $liquidacion->id);
            $calculo = $this->calcular($convenio, $recepciones, $datos);

            // Se rehace todo el detalle: primero se sueltan las recepciones y luego se ligan las elegidas.
            ReglasLiquidacion::liberarRecepciones($liquidacion);
            LiquidacionDetalle::where('liquidacion_id', $liquidacion->id)->delete();
            // Quien edita (aunque el borrador sea de otro) queda excluido de decidirla.
            $liquidacion->registrarIntervencion($usuarioId === null ? null : (int) $usuarioId);
            $liquidacion->update($this->columnas($convenio, $datos, $calculo));
            $this->guardarDetalles($liquidacion, $calculo, $recepciones);

            return $liquidacion;
        }, 3);

        $this->difundir('liquidacion', 'updated', PresentadorLiquidacion::evento($liquidacion), $empresa);

        return response()->json([
            'success' => true,
            'message' => 'Liquidación actualizada.',
            'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        Liquidacion::deEmpresa($empresa->id)->findOrFail($id);

        $data = TransaccionDinero::ejecutar(function () use ($empresa, $id) {
            $liquidacion = TransaccionDinero::bloquearLiquidacion($empresa->id, $id);
            ReglasLiquidacion::exigirBorrador($liquidacion);

            $data = PresentadorLiquidacion::evento($liquidacion);
            ReglasLiquidacion::liberarRecepciones($liquidacion);
            LiquidacionDetalle::where('liquidacion_id', $liquidacion->id)->delete();
            $liquidacion->delete();

            return $data;
        }, 3);

        $this->difundir('liquidacion', 'deleted', $data, $empresa);

        return response()->json(['success' => true, 'message' => 'Liquidación eliminada.', 'data' => ['id' => $id]]);
    }

    // ---- Flujo de revisión ----

    /** Borrador → en revisión (`edit`). Avisa a quienes pueden decidir el primer paso. */
    public function enviar(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'edit');
        $base = Liquidacion::deEmpresa($empresa->id)->findOrFail($id);
        $usuario = $request->user();

        // El servicio bloquea productor y liquidación antes de leer nada.
        $liquidacion = TransaccionDinero::ejecutar(fn () => FlujoLiquidacion::enviar($base, $usuario), 3);

        return $this->responderFlujo($request, $empresa, $liquidacion, 'Liquidación enviada a revisión.', AvisosAbastecimiento::pasoEnEspera(...));
    }

    /**
     * Aprueba el paso actual. Quien decide en una cadena configurada solo necesita ver la pantalla (`view`) y que
     * le toque el paso; `paso` es el número que tenía a la vista (si ya cambió, 422).
     */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        [$empresa, $liquidacion, $datos] = $this->prepararDecision($request, $id, [
            'comentario' => ['nullable', 'string'],
        ], 'aprobar');
        $usuario = $request->user();

        // El servicio bloquea productor y liquidación y recién entonces lee el paso actual y quién decidió.
        $liquidacion = TransaccionDinero::ejecutar(
            fn () => FlujoLiquidacion::aprobar($liquidacion, $usuario, $datos['comentario'] ?? null, (int) $datos['paso']),
            3,
        );

        // Si quedó aprobada no hay a quién avisar; si pasó al siguiente paso, a quienes lo deciden.
        return $this->responderFlujo(
            $request, $empresa, $liquidacion,
            $liquidacion->status === 'aprobada' ? 'Liquidación aprobada.' : 'Paso aprobado.',
            $liquidacion->status === 'en_revision' ? AvisosAbastecimiento::pasoEnEspera(...) : null,
        );
    }

    /** Rechaza el paso actual: regresa a borrador con el motivo y avisa a quien la creó. */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        [$empresa, $liquidacion, $datos] = $this->prepararDecision($request, $id, [
            'motivo' => ['required', 'string'],
        ], 'rechazar', ['motivo.required' => 'Escribe el motivo del rechazo.']);
        $usuario = $request->user();

        $liquidacion = TransaccionDinero::ejecutar(
            fn () => FlujoLiquidacion::rechazar($liquidacion, $usuario, (string) $datos['motivo'], (int) $datos['paso']),
            3,
        );

        return $this->responderFlujo($request, $empresa, $liquidacion, 'Liquidación rechazada y regresada a borrador.', AvisosAbastecimiento::rechazada(...));
    }

    /** Cancela (`delete`): borrador, en revisión o aprobada sin abonos aplicados; libera las recepciones. */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'delete');
        $base = Liquidacion::deEmpresa($empresa->id)->findOrFail($id);
        $datos = $request->validate(
            ['motivo' => ['required', 'string']],
            ['motivo.required' => 'Escribe el motivo de la cancelación.'],
        );
        $usuario = $request->user();

        // El servicio bloquea productor y liquidación antes de revisar si tiene abonos aplicados.
        $liquidacion = TransaccionDinero::ejecutar(
            fn () => FlujoLiquidacion::cancelar($base, $usuario, (string) $datos['motivo']),
            3,
        );

        return $this->responderFlujo($request, $empresa, $liquidacion, 'Liquidación cancelada.');
    }

    /**
     * Pasos comunes de aprobar y rechazar: pantalla (`view`), liquidación de la empresa, cuerpo (con `paso`) y
     * que al usuario le toque el paso que tenía a la vista. Si el paso ya cambió o la liquidación ya no está en
     * revisión no se responde 403: el servicio da el 422 que explica qué pasó (doble clic, otro aprobador).
     *
     * @return array{0: Enterprise, 1: Liquidacion, 2: array}
     */
    private function prepararDecision(Request $request, int $id, array $reglas, string $accion, array $mensajes = []): array
    {
        $empresa = $this->ctx->autorizar($request, self::SUBMODULO, 'view');
        $liquidacion = Liquidacion::deEmpresa($empresa->id)->findOrFail($id);

        $datos = $request->validate(['paso' => ['required', 'integer', 'min:1']] + $reglas, $mensajes + [
            'paso.required' => 'Falta el paso que estás decidiendo; recarga la pantalla.',
            'paso.integer' => 'El paso no es válido; recarga la pantalla.',
            'paso.min' => 'El paso no es válido; recarga la pantalla.',
        ]);

        $paso = CadenaLiquidacion::pasoActual($liquidacion);
        if ($liquidacion->status === 'en_revision'
            && $paso !== null
            && (int) $paso->step_order === (int) $datos['paso']
            && ! AprobadorLiquidaciones::puedeDecidir($request->user(), $liquidacion, $paso, $accion)) {
            abort(response()->json(['status' => 'error', 'message' => 'No te toca decidir esta liquidación.'], 403));
        }

        return [$empresa, $liquidacion, $datos];
    }

    /**
     * Después del commit: difunde el cambio, manda el aviso que corresponde (nunca rompe la respuesta) y entrega
     * la ficha completa.
     */
    private function responderFlujo(Request $request, Enterprise $empresa, Liquidacion $liquidacion, string $mensaje, ?callable $aviso = null): JsonResponse
    {
        DespuesDeDecidirLiquidacion::ejecutar($liquidacion, $empresa, $aviso);

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => $this->presentarCompleta($liquidacion, $empresa, $request->user()),
        ]);
    }

    // ---- Cuerpo y cálculo ----

    /** Cuerpo de alta, edición y vista previa con tipos y llaves de la empresa ya validados. */
    private function validar(Request $request, Enterprise $empresa, bool $conLiquidacion = false): array
    {
        $reglas = [
            'convenio_id' => ['required', 'integer', $this->viva($empresa, 'abs_convenios')],
            'periodo_inicio' => ['required', 'date_format:Y-m-d'],
            'periodo_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:periodo_inicio'],
            'recepcion_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'recepcion_ids.*' => ['integer'],
            'monto_ajustado' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'motivo_ajuste' => ['nullable', 'string', 'max:2000'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ];
        if ($conLiquidacion) {
            $reglas['liquidacion_id'] = ['nullable', 'integer', $this->viva($empresa, 'abs_liquidaciones')];
        }

        return $request->validate($reglas, [
            'recepcion_ids.required' => 'Elige al menos una recepción.',
            'recepcion_ids.min' => 'Elige al menos una recepción.',
            'recepcion_ids.*.integer' => 'Las recepciones elegidas no son válidas.',
            'periodo_inicio.required' => 'Captura el inicio del periodo.',
            'periodo_fin.required' => 'Captura el fin del periodo.',
            'periodo_fin.after_or_equal' => 'El fin del periodo no puede ser anterior al inicio.',
            'monto_ajustado.numeric' => 'El monto ajustado debe ser un número.',
            'monto_ajustado.min' => 'El monto ajustado no puede ser negativo.',
        ]);
    }

    private function viva(Enterprise $empresa, string $tabla)
    {
        return Rule::exists($tabla, 'id')->where('enterprise_id', $empresa->id)->whereNull('deleted_at');
    }

    private function calcular(Convenio $convenio, Collection $recepciones, array $datos): array
    {
        return CalculadoraLiquidacion::calcular(
            $convenio, $recepciones,
            isset($datos['monto_ajustado']) ? (float) $datos['monto_ajustado'] : null,
            $datos['motivo_ajuste'] ?? null,
        );
    }

    /** Columnas de la liquidación a partir del convenio, el cuerpo y el cálculo. */
    private function columnas(Convenio $convenio, array $datos, array $calculo): array
    {
        return [
            'convenio_id' => $convenio->id,
            'productor_id' => $convenio->productor_id,
            'moneda' => $convenio->moneda,
            'periodo_inicio' => $datos['periodo_inicio'],
            'periodo_fin' => $datos['periodo_fin'],
            'cantidad' => $calculo['cantidad'],
            'kilos' => $calculo['kilos'],
            'bruto' => $calculo['bruto'],
            'rezaga_real_pct' => $calculo['rezaga_real_pct'],
            'rezaga_aceptada_pct' => $calculo['rezaga_aceptada_pct'],
            'descuento' => $calculo['descuento'],
            'monto_calculado' => $calculo['monto_calculado'],
            'monto_ajustado' => $calculo['monto_ajustado'],
            'motivo_ajuste' => $calculo['motivo_ajuste'],
            'monto_final' => $calculo['monto_final'],
            'notas' => $datos['notas'] ?? null,
        ];
    }

    /**
     * Escribe el detalle y liga las recepciones. Los renglones no llevan bitácora propia
     * (se reescriben completos en cada guardado): la deja la liquidación.
     */
    private function guardarDetalles(Liquidacion $liquidacion, array $calculo, Collection $recepciones): void
    {
        foreach ($calculo['detalles'] as $detalle) {
            LiquidacionDetalle::create($detalle + ['liquidacion_id' => $liquidacion->id]);
        }

        Recepcion::whereIn('id', $recepciones->pluck('id'))->update(['liquidacion_id' => $liquidacion->id]);
    }

    // ---- Presentación ----

    private function presentador(): PresentadorLiquidacion
    {
        return app(PresentadorLiquidacion::class);
    }

    private function presentarResumen(Liquidacion $l): array
    {
        return $this->presentador()->resumen($l);
    }

    private function presentarCompleta(Liquidacion $l, Enterprise $empresa, ?User $usuario): array
    {
        return $this->presentador()->completa($l, $empresa, $usuario);
    }

    /** Renglón de la vista previa: el cálculo más lo que la tabla muestra de la recepción. */
    private function detallePrevio(array $d, Recepcion $r): array
    {
        return $d + [
            'folio' => $r->folio,
            'fecha' => $r->fecha->format('Y-m-d'),
            'tipo_carga' => $r->tipoCarga ? ['id' => $r->tipoCarga->id, 'nombre' => $r->tipoCarga->nombre] : null,
        ];
    }
}
