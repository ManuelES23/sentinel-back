<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion;

use App\Http\Controllers\Api\SplendidFarms\OperacionAgricola\Planeacion\Concerns\PermisoProgramaSemanal;
use App\Http\Controllers\Controller;
use App\Models\CRM\CrmCliente;
use App\Models\Enterprise;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ProgramaSemanalService;
use App\Services\Planeacion\RegistroCambiosPrograma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Necesidades de ventas de la semana: un renglón por cliente, bodega y
 * presentación, con las cajas de cada día y una o más variedades aceptadas
 * del mismo grupo de balance. Todas las escrituras exigen `capturar-ventas`,
 * quedan en el historial del programa (si ya hay junta) y avisan en tiempo real.
 *
 * Los servicios se inyectan por método y no por constructor: guardan cálculos
 * en memoria y el controlador puede quedar vivo entre peticiones.
 */
class ProgramaNecesidadController extends Controller
{
    use PermisoProgramaSemanal;

    private const MAX_CAJAS = 999999;

    private const MENSAJE_GRUPO = 'Las variedades deben ser del mismo grupo de balance';

    /** Campos que, si llegan, hacen de la actualización una edición completa del renglón. */
    private const CAMPOS_COMPLETOS = ['bodega_id', 'cliente_id', 'cliente_nombre', 'presentacion_id', 'tipo_empaque_id', 'variedad_ids'];

    /** Crea un renglón de necesidad en la semana. */
    public function store(Request $request, ProgramaSemanal $programa, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-ventas');

        $datos = $this->validarCompleto($request, $servicio);

        $necesidad = DB::transaction(function () use ($programa, $datos, $registro) {
            $necesidad = ProgramaNecesidad::create($this->atributos($datos) + [
                'programa_id' => $programa->id,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $necesidad->variedades()->sync($datos['variedad_ids']);
            $registro->registrar($programa, 'necesidad', $necesidad->id, 'creado', null, $this->instantanea($necesidad));

            return $necesidad;
        });

        return response()->json([
            'success' => true,
            'message' => 'Necesidad guardada.',
            'data' => $this->payload($necesidad),
        ], 201);
    }

    /**
     * Cambia un renglón. Acepta los campos completos, solo las seis cajas, o
     * un solo día `{dia, cajas}` (la Hoja edita celda por celda).
     */
    public function update(Request $request, ProgramaNecesidad $necesidad, ProgramaSemanalService $servicio, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-ventas');

        $programa = $necesidad->programa()->firstOrFail();

        if ($request->has('dia')) {
            $datos = $request->validate([
                'dia' => ['required', 'integer', 'between:0,5'],
                'cajas' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CAJAS],
            ]);
            $modo = 'dia';
        } elseif ($request->hasAny(self::CAMPOS_COMPLETOS)) {
            $datos = $this->validarCompleto($request, $servicio);
            $modo = 'completo';
        } else {
            $datos = $request->validate($this->reglasCajas());
            $modo = 'cajas';
        }

        DB::transaction(function () use ($necesidad, $programa, $datos, $modo, $registro) {
            // Se relee con bloqueo: dos celdas editadas a la vez no se pisan.
            $actual = ProgramaNecesidad::whereKey($necesidad->id)->lockForUpdate()->firstOrFail();
            $antes = $this->instantanea($actual);

            if ($modo === 'dia') {
                $actual->{"cajas_{$datos['dia']}"} = (int) $datos['cajas'];
            } elseif ($modo === 'cajas') {
                $actual->fill($this->atributosCajas($datos['cajas']));
            } else {
                $actual->fill($this->atributos($datos));
            }

            if ($actual->isDirty()) {
                $actual->updated_by = Auth::id();
                $actual->save();
            }
            if ($modo === 'completo') {
                $actual->variedades()->sync($datos['variedad_ids']);
            }

            // Sin cambio de valor no hay historial ni aviso.
            $despues = $this->instantanea($actual);
            if ($antes != $despues) {
                $registro->registrar($programa, 'necesidad', $actual->id, 'actualizado', $antes, $despues);

                // Lo que llega a las semanas destino de sus «mover pedido» también pudo cambiar.
                $movimientos = ProgramaAcuerdo::where('necesidad_id', $actual->id)
                    ->where('tipo', 'mover_pedido')
                    ->where('activo', true)
                    ->get();
                $this->avisarDestinos($programa, $movimientos, $registro);
            }

            $necesidad->setRawAttributes($actual->getAttributes(), true);
        });

        return response()->json([
            'success' => true,
            'message' => 'Necesidad actualizada.',
            'data' => $this->payload($necesidad),
        ]);
    }

    public function destroy(ProgramaNecesidad $necesidad, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-ventas');

        $programa = $necesidad->programa()->firstOrFail();

        DB::transaction(function () use ($necesidad, $programa, $registro) {
            $antes = $this->instantanea($necesidad);

            // Sus «mover pedido» se borran en cascada: quedan en el historial
            // y se avisa a las semanas destino antes de borrar el renglón.
            $movimientos = ProgramaAcuerdo::where('necesidad_id', $necesidad->id)->orderBy('id')->get();
            foreach ($movimientos as $acuerdo) {
                $registro->registrar($programa, 'acuerdo', $acuerdo->id, 'borrado', $acuerdo->instantaneaHistorial(), null);
            }
            $this->avisarDestinos($programa, $movimientos, $registro);

            $necesidad->delete();
            $registro->registrar($programa, 'necesidad', $necesidad->id, 'borrado', $antes, null);
        });

        return response()->json(['success' => true, 'message' => 'Necesidad eliminada.']);
    }

    /**
     * Copia los renglones de la semana anterior (la que empieza 7 días antes)
     * con sus cajas y variedades. Solo si esta semana aún no tiene renglones.
     */
    public function copiarAnterior(ProgramaSemanal $programa, RegistroCambiosPrograma $registro): JsonResponse
    {
        $this->exigirPermiso('capturar-ventas');

        $copiadas = DB::transaction(function () use ($programa, $registro) {
            // Se bloquea el programa para que dos copias a la vez no dupliquen renglones.
            ProgramaSemanal::whereKey($programa->id)->lockForUpdate()->value('id');

            if (ProgramaNecesidad::where('programa_id', $programa->id)->exists()) {
                throw ValidationException::withMessages(['semana' => 'Esta semana ya tiene necesidades']);
            }

            $lunesAnterior = $programa->lunes->copy()->subDays(7)->toDateString();
            $anterior = ProgramaSemanal::whereDate('lunes', $lunesAnterior)->first();
            $origen = $anterior
                ? ProgramaNecesidad::with('variedades:id')->where('programa_id', $anterior->id)->orderBy('id')->get()
                : collect();
            if ($origen->isEmpty()) {
                throw ValidationException::withMessages(['semana' => 'La semana anterior no tiene necesidades']);
            }

            foreach ($origen as $o) {
                $copia = ProgramaNecesidad::create([
                    'programa_id' => $programa->id,
                    'bodega_id' => $o->bodega_id,
                    'cliente_id' => $o->cliente_id,
                    'cliente_nombre' => $o->cliente_nombre,
                    'cultivo_id' => $o->cultivo_id,
                    'presentacion_id' => $o->presentacion_id,
                    'tipo_empaque_id' => $o->tipo_empaque_id,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ] + $this->atributosCajas($o->cajasPorDia()));
                $copia->variedades()->sync($o->variedades->pluck('id')->all());
                $registro->registrar($programa, 'necesidad', $copia->id, 'creado', null, $this->instantanea($copia));
            }

            return $origen->count();
        });

        return response()->json([
            'success' => true,
            'message' => "Se copiaron {$copiadas} necesidades de la semana anterior.",
            'data' => ['copiadas' => $copiadas],
        ]);
    }

    // ── Validación ──────────────────────────────────────

    /** @return array<string, list<mixed>> */
    private function reglasCajas(): array
    {
        return [
            'cajas' => ['required', 'array', 'size:6'],
            'cajas.*' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CAJAS],
        ];
    }

    /**
     * Campos completos ya validados, con el nombre del cliente resuelto (el
     * del CRM si hay `cliente_id`) y las variedades comprobadas contra la
     * presentación (cultivo) y el grupo de balance.
     *
     * @return array{bodega_id: int, cliente_id: ?int, cliente_nombre: string, presentacion_id: int, cultivo_id: int, tipo_empaque_id: ?int, variedad_ids: list<int>, cajas: list<int>}
     */
    private function validarCompleto(Request $request, ProgramaSemanalService $servicio): array
    {
        // El nombre se guarda sin espacios sobrantes.
        if (is_string($request->input('cliente_nombre'))) {
            $nombre = trim($request->input('cliente_nombre'));
            $request->merge(['cliente_nombre' => $nombre === '' ? null : $nombre]);
        }

        $datos = $request->validate([
            'bodega_id' => ['required', 'integer', Rule::exists('plan_bodegas_destino', 'id')->where('is_active', true)],
            'cliente_id' => ['nullable', 'integer'],
            'cliente_nombre' => ['nullable', 'required_without:cliente_id', 'string', 'max:150'],
            'presentacion_id' => ['required', 'integer', Rule::exists('plan_presentaciones', 'id')->where('is_active', true)],
            'tipo_empaque_id' => ['nullable', 'integer', Rule::exists('plan_tipos_empaque', 'id')->where('is_active', true)],
            'variedad_ids' => ['required', 'array', 'min:1', 'max:50'],
            'variedad_ids.*' => ['required', 'integer', 'distinct'],
        ] + $this->reglasCajas());

        $nombre = $datos['cliente_nombre'] ?? null;
        $clienteId = isset($datos['cliente_id']) ? (int) $datos['cliente_id'] : null;
        if ($clienteId !== null) {
            $nombre = $this->nombreClienteSbp($clienteId);
        }

        $cultivoId = (int) Presentacion::whereKey($datos['presentacion_id'])->value('cultivo_id');
        $variedadIds = array_map('intval', $datos['variedad_ids']);
        $this->exigirMismoGrupo($variedadIds, $cultivoId, $servicio);

        return [
            'bodega_id' => (int) $datos['bodega_id'],
            'cliente_id' => $clienteId,
            'cliente_nombre' => $nombre,
            'presentacion_id' => (int) $datos['presentacion_id'],
            'cultivo_id' => $cultivoId,
            'tipo_empaque_id' => isset($datos['tipo_empaque_id']) ? (int) $datos['tipo_empaque_id'] : null,
            'variedad_ids' => $variedadIds,
            'cajas' => array_map('intval', array_values($datos['cajas'])),
        ];
    }

    /** Nombre del cliente del CRM de Splendid by Porvenir; 422 si no es de esa empresa. */
    private function nombreClienteSbp(int $clienteId): string
    {
        $empresaId = Enterprise::where('slug', 'splendidbyporvenir')->value('id');
        $nombre = $empresaId
            ? CrmCliente::where('empresa_id', $empresaId)->whereKey($clienteId)->value('nombre')
            : null;

        if ($nombre === null) {
            throw ValidationException::withMessages(['cliente_id' => 'El cliente no es del CRM de Splendid by Porvenir.']);
        }

        return $nombre;
    }

    /**
     * Todas las variedades deben existir, ser del cultivo de la presentación
     * y caer en el mismo grupo de balance.
     *
     * @param  list<int>  $variedadIds
     */
    private function exigirMismoGrupo(array $variedadIds, int $cultivoId, ProgramaSemanalService $servicio): void
    {
        $grupos = [];
        foreach ($variedadIds as $variedadId) {
            if ($servicio->cultivoDeVariedad($variedadId) !== $cultivoId) {
                throw ValidationException::withMessages(['variedad_ids' => self::MENSAJE_GRUPO]);
            }
            $grupos[$servicio->grupoDeVariedad($variedadId)] = true;
        }

        if (count($grupos) !== 1) {
            throw ValidationException::withMessages(['variedad_ids' => self::MENSAJE_GRUPO]);
        }
    }

    /**
     * Avisa a cada semana destino distinta (de otra semana) de los «mover
     * pedido» dados. Si el programa destino no existe no hay quién lo tenga abierto.
     *
     * @param  iterable<ProgramaAcuerdo>  $movimientos
     */
    private function avisarDestinos(ProgramaSemanal $programa, iterable $movimientos, RegistroCambiosPrograma $registro): void
    {
        $propio = $programa->lunes->toDateString();
        $lunes = [];
        foreach ($movimientos as $acuerdo) {
            $destino = $acuerdo->destino_lunes?->toDateString();
            if ($destino !== null && $destino !== $propio) {
                $lunes[$destino] = true;
            }
        }

        foreach (array_keys($lunes) as $fecha) {
            $destino = ProgramaSemanal::whereDate('lunes', $fecha)->first();
            if ($destino) {
                $registro->emitir($destino);
            }
        }
    }

    // ── Salidas ─────────────────────────────────────────

    /**
     * Atributos de la tabla a partir de los campos completos validados.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function atributos(array $datos): array
    {
        return [
            'bodega_id' => $datos['bodega_id'],
            'cliente_id' => $datos['cliente_id'],
            'cliente_nombre' => $datos['cliente_nombre'],
            'cultivo_id' => $datos['cultivo_id'],
            'presentacion_id' => $datos['presentacion_id'],
            'tipo_empaque_id' => $datos['tipo_empaque_id'],
        ] + $this->atributosCajas($datos['cajas']);
    }

    /**
     * @param  array<int, int|string>  $cajas  Seis cantidades, de lunes a sábado.
     * @return array<string, int>
     */
    private function atributosCajas(array $cajas): array
    {
        $salida = [];
        foreach (array_values($cajas) as $dia => $valor) {
            $salida["cajas_{$dia}"] = (int) $valor;
        }

        return $salida;
    }

    /** Valores del renglón para el historial (mismas llaves antes y después). */
    private function instantanea(ProgramaNecesidad $n): array
    {
        $variedades = DB::table('programa_necesidad_variedad')
            ->where('necesidad_id', $n->id)
            ->pluck('variedad_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        return [
            'bodega_id' => $n->bodega_id,
            'cliente_id' => $n->cliente_id,
            'cliente_nombre' => $n->cliente_nombre,
            'presentacion_id' => $n->presentacion_id,
            'tipo_empaque_id' => $n->tipo_empaque_id,
            'variedad_ids' => $variedades,
            'cajas' => $n->cajasPorDia(),
        ];
    }

    private function payload(ProgramaNecesidad $n): array
    {
        return [
            'id' => $n->id,
            'programa_id' => $n->programa_id,
            'cultivo_id' => $n->cultivo_id,
            'en_crm' => $n->cliente_id !== null,
        ] + $this->instantanea($n);
    }
}
